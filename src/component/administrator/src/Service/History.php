<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class History
{
    public function __construct(private Store $store)
    {
    }

    public function prepare(array $draft, array $message): array
    {
        $id = (int) $draft['id'];
        $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        $settings = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $snapshot = ['message' => $message, 'html' => Message::html($message), 'text' => Message::text($message),
            'da' => Message::html($message, 'da-DK'), 'en' => Message::html($message, 'en-GB'),
            'sender_email' => $settings['sender_email'] ?? '', 'template_version' => Message::templateVersion(),
            'estimate' => $draft['estimate_count'], 'estimate_checked' => $draft['estimate_checked'],
            'audience_fingerprint' => $draft['audience_fingerprint'], 'rules' => json_decode($draft['audience_rules'] ?? 'null', true)];
        $this->store->transaction(function () use ($draft, $message, $id, $snapshot, $settings): void {
            $old = $this->store->row("SELECT state FROM #__intercom_history WHERE draft_id=$id FOR UPDATE");
            if ($old && !in_array($old['state'], ['prepared', 'abandoned'], true)) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $account = (string) ($this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'] ?? '');
            $json = $this->store->q(json_encode($snapshot, JSON_THROW_ON_ERROR));
            $type = $this->store->q($message['type']);
            $mode = $this->store->q($draft['delivery_mode']);
            if ($old) {
                // Restored abandoned drafts start a new prepared mailing. The
                // durable abandonment record retains the previous provider IDs.
                $this->store->execute("UPDATE #__intercom_history SET state='prepared',revision=" . (int) $draft['revision'] . ",snapshot=$json,filter_id=" . (int) $draft['filter_id']
                    . ",type_key=$type,delivery_mode=$mode,account_id=" . $this->store->q($account) . ',group_id=' . (int) ($settings['group_id'] ?? 0)
                    . ",mailing_id=0,requested_at=NULL,scheduled_at=0,started_at=NULL,finished_at=NULL WHERE draft_id=$id");
            } else {
                $this->store->execute("INSERT INTO #__intercom_history(draft_id,revision,actor_id,delivery_mode,account_id,group_id,type_key,filter_id,snapshot,created_at) VALUES($id," . (int) $draft['revision'] . ',' . (int) $draft['owner_id'] . ",$mode," . $this->store->q($account) . ',' . (int) ($settings['group_id'] ?? 0) . ",$type," . (int) $draft['filter_id'] . ",$json,UTC_TIMESTAMP())");
            }
        });
        return $snapshot;
    }

    public function intent(array $draft, int $timestamp): void
    {
        $id = (int) $draft['id'];
        $history = $this->store->row("SELECT revision,state,snapshot FROM #__intercom_history WHERE draft_id=$id FOR UPDATE");
        if (!$history || $history['state'] !== 'prepared' || (int) $history['revision'] !== (int) $draft['revision']) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        $snapshot = json_decode($history['snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $snapshot['submission_estimate'] = $draft['estimate_count'];
        $snapshot['submission_estimate_checked'] = $draft['estimate_checked'];
        $frozen = $this->store->q(json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->store->execute("UPDATE #__intercom_history SET state='releasing',snapshot=$frozen,mailing_id=" . (int) $draft['mailing_id'] . ",requested_at=UTC_TIMESTAMP(),scheduled_at=$timestamp WHERE draft_id=$id");
    }

    public function outcome(int $id, string $state): void
    {
        $this->store->execute('UPDATE #__intercom_history SET state=' . $this->store->q($state) . " WHERE draft_id=$id AND state IN ('releasing','submitted','scheduled','uncertain')");
    }

    public function completed(int $id, array $remote): void
    {
        $start = $this->store->q(gmdate('Y-m-d H:i:s', (int) $remote['started']));
        $finish = $this->store->q(gmdate('Y-m-d H:i:s', (int) $remote['finished']));
        $this->store->execute("UPDATE #__intercom_history SET state='completed',started_at=$start,finished_at=$finish WHERE draft_id=$id AND state IN ('releasing','submitted','scheduled','uncertain')");
    }

    public function migrate(): void
    {
        // Older rows have no exact frozen rendering or independently recorded account.
        // Never invent provider send times from draft updates or audit timestamps.
        $this->store->execute("INSERT IGNORE INTO #__intercom_history(draft_id,revision,actor_id,delivery_mode,type_key,state,filter_id,mailing_id,snapshot,reconstructed,requested_at,scheduled_at,created_at) SELECT d.id,d.revision,d.owner_id,d.delivery_mode,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.content,'$.type')),''),d.state,d.filter_id,d.mailing_id,IF(d.content='{}',NULL,JSON_OBJECT('message',JSON_EXTRACT(d.content,'$'))),1,(SELECT MIN(a.created_at) FROM #__intercom_audit a WHERE a.draft_id=d.id AND a.event='release.intent'),d.send_at,d.created_at FROM #__intercom_drafts d WHERE d.state IN ('submitted','scheduled','completed') OR (d.state='uncertain' AND EXISTS(SELECT 1 FROM #__intercom_audit a WHERE a.draft_id=d.id AND a.event IN ('release.intent','release.uncertain')))");
    }

    public function maintain(int $days): void
    {
        $days = max(1, min(3650, $days));
        $this->store->execute("UPDATE #__intercom_history h JOIN #__intercom_drafts d ON d.id=h.draft_id SET h.snapshot=NULL WHERE h.snapshot IS NOT NULL AND (d.state IN ('completed','cancelled','deleted') OR (d.delivery_mode='fake' AND d.state='submitted')) AND COALESCE(h.finished_at,h.requested_at,h.created_at)<UTC_TIMESTAMP()-INTERVAL $days DAY");
    }

    public function page(array $filters, int $limit, int $start, bool $latest = false): array
    {
        $limit = in_array($limit, [5, 10, 20, 50, 100], true) ? $limit : 20;
        $where = ["h.state!='prepared'"];
        if ($latest) {
            $where[] = "h.state='completed' AND h.delivery_mode='live' AND h.started_at IS NOT NULL";
        }
        foreach (['state', 'type_key'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = 'h.' . $key . '=' . $this->store->q(substr($filters[$key], 0, 64));
            }
        }
        if (($filters['actor'] ?? '') !== '') {
            $where[] = 'h.actor_id=' . max(0, (int) $filters['actor']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key]) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_DATE', 422);
                }
                $where[] = 'COALESCE(h.started_at,h.requested_at,h.created_at)' . $operator . $this->store->q($filters[$key] . ($key === 'from' ? ' 00:00:00' : ' 23:59:59'));
            }
        }
        $where = implode(' AND ', $where);
        $total = (int) $this->store->row("SELECT COUNT(*) total FROM #__intercom_history h WHERE $where")['total'];
        $start = min(max(0, $start), max(0, (int) (ceil($total / $limit) - 1)) * $limit);
        $order = $latest ? 'h.started_at DESC,h.draft_id DESC' : 'COALESCE(h.requested_at,h.created_at) DESC,h.draft_id DESC';
        return ['total' => $total, 'start' => $start, 'limit' => $limit, 'rows' => $this->store->rows("SELECT h.*,u.name actor_name FROM #__intercom_history h LEFT JOIN #__users u ON u.id=h.actor_id WHERE $where ORDER BY $order LIMIT $limit OFFSET $start")];
    }

    public function detail(int $id): array
    {
        $row = $this->store->row("SELECT h.*,u.name actor_name FROM #__intercom_history h LEFT JOIN #__users u ON u.id=h.actor_id WHERE h.draft_id=$id AND h.state!='prepared'");
        if (!$row) {
            throw new \RuntimeException('COM_INTERCOM_DRAFT_NOT_FOUND', 404);
        }
        return $row;
    }

    public static function preview(string $html): string
    {
        // Frozen preview only: block tracking/resources, scripts and navigation.
        $html = preg_replace('/\s(?:href|src|action)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $policy = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; form-action \'none\'; base-uri \'none\'">';
        return preg_replace('/<head[^>]*>/i', '$0' . $policy, $html, 1);
    }
}

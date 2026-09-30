<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Activity
{
    public function __construct(private Store $store, private array $config)
    {
    }

    private function context(): int
    {
        return ($this->config['mode'] ?? 'fake') === 'fake' ? 0 : (int) ($this->config['group_id'] ?? 0);
    }

    public function overview(): array
    {
        $list = $this->context();
        $pool = $this->store->row("SELECT COUNT(*) total,COALESCE(SUM(f.draft_id IS NULL),0) free,COALESCE(SUM(d.state='uncertain'),0) uncertain FROM #__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id WHERE f.managed=1 AND f.group_id=$list");
        $intents = $list ? $this->store->row("SELECT COUNT(*) total,COALESCE(SUM(state IN ('pending','uncertain')),0) uncertain FROM #__intercom_filter_creations WHERE group_id=$list") : ['total' => $pool['total'], 'uncertain' => 0];
        $mode = $this->store->q($this->config['mode'] ?? 'fake');
        $days = min(30, max(1, (int) ($this->config['retention_days'] ?? 30)));
        return ['pool' => $pool, 'intents' => $intents, 'cap' => max(1, min(20, (int) ($this->config['max_filters'] ?? 5))),
            'days' => $days, 'drafts' => $this->store->rows("SELECT state,COUNT(*) total FROM #__intercom_drafts WHERE delivery_mode=$mode GROUP BY state ORDER BY state"),
            'accepted' => (int) $this->store->row("SELECT COUNT(*) total FROM #__intercom_audit a JOIN #__intercom_drafts d ON d.id=a.draft_id WHERE a.event='release.accepted' AND d.delivery_mode=$mode AND a.created_at>=UTC_TIMESTAMP()-INTERVAL $days DAY")['total'],
            'archives' => $this->store->rows("SELECT state,COUNT(*) total FROM #__intercom_archives WHERE state!='submitted' GROUP BY state"),
            'refresh' => $this->store->row("SELECT refreshed_at FROM #__intercom_catalogues WHERE list_id=$list")['refreshed_at'] ?? null,
            'maintenance' => $this->store->row("SELECT created_at FROM #__intercom_audit WHERE event='maintenance.completed' ORDER BY id DESC LIMIT 1")['created_at'] ?? null];
    }

    public function page(string $view, array $filters, int $limit, int $start): array
    {
        $limit = in_array($limit, [5, 10, 20, 50, 100], true) ? $limit : 20;
        $where = ['1=1'];
        if ($view === 'audit') {
            $from = '#__intercom_audit a';
            $columns = 'a.*';
            $order = 'a.id DESC';
            if (($filters['actor'] ?? '') !== '') {
                $where[] = 'a.actor_id=' . max(0, (int) $filters['actor']);
            }
            if (($filters['event'] ?? '') !== '') {
                $where[] = 'a.event=' . $this->store->q(substr((string) $filters['event'], 0, 80));
            }
            foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
                $value = (string) ($filters[$key] ?? '');
                if ($value !== '') {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        throw new \RuntimeException('COM_INTERCOM_INVALID_DATE', 422);
                    }
                    $where[] = 'a.created_at' . $operator . $this->store->q($value . ($key === 'from' ? ' 00:00:00' : ' 23:59:59'));
                }
            }
        } elseif ($view === 'filters') {
            $from = '#__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id';
            $columns = 'f.*,d.state,d.updated_at,d.owner_id';
            $order = 'd.updated_at DESC,f.filter_id DESC';
            $list = $this->context();
            if (($filters['scope'] ?? 'current') === 'current') {
                $where[] = "f.group_id=$list AND f.managed=1";
            } elseif (($filters['scope'] ?? '') === 'historical') {
                $where[] = "(f.group_id!=$list OR f.managed=0)";
            }
            $state = $filters['state'] ?? '';
            if ($state === 'free') {
                $where[] = 'f.draft_id IS NULL';
            } elseif (in_array($state, ['draft', 'tested', 'testing', 'releasing', 'submitted', 'scheduled', 'cancelled', 'uncertain'], true)) {
                $where[] = 'd.state=' . $this->store->q($state);
            }
        } else {
            throw new \InvalidArgumentException('Unknown activity page');
        }
        $where = implode(' AND ', $where);
        $total = (int) $this->store->row("SELECT COUNT(*) total FROM $from WHERE $where")['total'];
        $start = min(max(0, $start), max(0, (int) (ceil($total / $limit) - 1)) * $limit);
        return ['total' => $total, 'start' => $start, 'limit' => $limit,
            'rows' => $this->store->rows("SELECT $columns FROM $from WHERE $where ORDER BY $order LIMIT $limit OFFSET $start")];
    }
}

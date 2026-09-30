<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\MailingStatus;
use FKT\Component\Intercom\Administrator\Domain\ReconciliationGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Reconciliation
{
    public function __construct(private Store $store, private ReconciliationGateway $gateway, private array $config)
    {
    }

    private function lockConfiguration(): int
    {
        // Hold the same connection lock as reservations and account changes while
        // reading provider evidence and applying it. Never reconcile another list.
        $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
        $params = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        $current = json_decode($params['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $group = (int) ($current['group_id'] ?? 0);
        if (($current['mode'] ?? 'fake') !== 'live' || $group < 1 || $group !== (int) ($this->config['group_id'] ?? 0)) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        return $group;
    }

    private function record(string $table, string $key, int $id, array $row, string $status, int $actor, int $draft = 0): void
    {
        $this->store->execute("UPDATE #__intercom_$table SET reconciliation_status=" . $this->store->q($status) . ",checked_at=UTC_TIMESTAMP() WHERE $key=$id");
        if (($row['reconciliation_status'] ?? '') !== $status) {
            $this->store->audit($actor, 'reconciliation.' . $status, $draft, [$key => $id, 'kind' => $table, 'previous' => $row['reconciliation_status'] ?? '']);
        }
    }

    public function run(int $actor = 0): array
    {
        $summary = ['checked' => 0, 'released' => 0, 'adopted' => 0, 'blocked' => 0];
        if (($this->config['mode'] ?? 'fake') !== 'live') {
            return $summary;
        }
        $group = (int) ($this->config['group_id'] ?? 0);
        $leases = $this->store->rows("SELECT f.filter_id FROM #__intercom_filters f JOIN #__intercom_drafts d ON d.id=f.draft_id WHERE f.managed=1 AND f.group_id=$group AND d.delivery_mode='live' AND d.state IN ('submitted','scheduled','uncertain','cancelled','deleted') ORDER BY f.checked_at,f.filter_id LIMIT 20");
        $started = time();
        foreach ($leases as $lease) {
            if (time() - $started >= 20) {
                break;
            }
            $status = $this->store->transaction(function () use ($lease, $actor): ?string {
                $group = $this->lockConfiguration();
                $id = (int) $lease['filter_id'];
                $filter = $this->store->row("SELECT * FROM #__intercom_filters WHERE filter_id=$id AND managed=1 AND group_id=$group FOR UPDATE");
                if (!$filter || !$filter['draft_id']) {
                    return null;
                }
                $draftId = (int) $filter['draft_id'];
                $draft = $this->store->row("SELECT * FROM #__intercom_drafts WHERE id=$draftId FOR UPDATE");
                if (!$draft || $draft['delivery_mode'] !== 'live' || !in_array($draft['state'], ['submitted', 'scheduled', 'uncertain', 'cancelled', 'deleted'], true)) {
                    return null;
                }
                $status = 'unknown';
                if ((int) $draft['filter_id'] !== $id) {
                    $status = 'mismatch';
                } elseif ((int) $draft['mailing_id'] > 0) {
                    try {
                        $remote = $this->gateway->mailing((int) $draft['mailing_id']);
                        $status = MailingStatus::status($remote, (int) $draft['mailing_id'], $group);
                        if ($status === 'completed') {
                            // A missing filter is not proof of a safe reservation.
                            $remoteFilter = $this->gateway->filter($group, $id);
                            $status = (string) ($remoteFilter['id'] ?? '') === (string) $id ? 'released' : 'mismatch';
                        }
                    } catch (\Throwable $error) {
                        $status = $error->getCode() === 404 ? 'missing' : 'provider_error';
                    }
                }
                if ($status === 'released') {
                    // Keep historical IDs on the draft, but remove its live lease.
                    // Deleted/cancelled records remain hidden/inactive.
                    $state = in_array($draft['state'], ['deleted', 'cancelled'], true) ? $draft['state'] : 'completed';
                    $this->store->execute("UPDATE #__intercom_drafts SET state='$state',tested_revision=NULL,tested_fingerprint=NULL,updated_at=UTC_TIMESTAMP() WHERE id=$draftId");
                    $this->store->execute("UPDATE #__intercom_filters SET draft_id=NULL WHERE filter_id=$id AND draft_id=$draftId");
                    $this->store->audit($actor, 'mailing.completed', $draftId, ['mailing_id' => (int) $draft['mailing_id'], 'filter_id' => $id, 'previous_state' => $draft['state']]);
                }
                $this->record('filters', 'filter_id', $id, $filter, $status, $actor, $draftId);
                return $status;
            });
            if ($status !== null) {
                $summary['checked']++;
                $summary[$status === 'released' ? 'released' : 'blocked']++;
            }
        }
        $intents = $this->store->rows("SELECT id FROM #__intercom_filter_creations WHERE group_id=$group AND (state='uncertain' OR (state='pending' AND created_at<UTC_TIMESTAMP()-INTERVAL 15 MINUTE)) ORDER BY checked_at,id LIMIT 20");
        foreach ($intents as $intent) {
            if (time() - $started >= 40) {
                break;
            }
            $status = $this->store->transaction(function () use ($intent, $actor): ?string {
                $group = $this->lockConfiguration();
                $id = (int) $intent['id'];
                $row = $this->store->row("SELECT * FROM #__intercom_filter_creations WHERE id=$id AND group_id=$group FOR UPDATE");
                if (!$row || !in_array($row['state'], ['pending', 'uncertain'], true)) {
                    return null;
                }
                if ($row['state'] === 'pending') {
                    // Age establishes uncertainty only; it never frees a slot.
                    if (strtotime($row['created_at'] . ' UTC') >= time() - 900) {
                        return null;
                    }
                    $this->store->execute("UPDATE #__intercom_filter_creations SET state='uncertain' WHERE id=$id");
                    $this->store->audit($actor, 'filter.creation_interrupted', 0, ['intent_id' => $id, 'group_id' => $group]);
                }
                $matches = null;
                try {
                    $matches = array_values(array_filter($this->gateway->filters($group), static fn (array $filter): bool => ($filter['name'] ?? '') === $row['remote_name']));
                    $status = count($matches) === 0 ? 'missing' : 'ambiguous';
                } catch (\Throwable $error) {
                    $status = $error->getCode() === 404 ? 'missing' : 'provider_error';
                }
                if ($matches !== null && count($matches) === 1 && ctype_digit((string) ($matches[0]['id'] ?? '')) && (int) $matches[0]['id'] > 0 && (int) $matches[0]['id'] <= 4294967295) {
                    $filterId = (int) $matches[0]['id'];
                    $existing = $this->store->row("SELECT filter_id FROM #__intercom_filters WHERE filter_id=$filterId FOR UPDATE");
                    if (!$existing) {
                        $this->store->execute("INSERT INTO #__intercom_filters (filter_id,group_id,managed) VALUES ($filterId,$group,1)");
                        $this->store->execute("UPDATE #__intercom_filter_creations SET state='created',filter_id=$filterId WHERE id=$id");
                        $status = 'adopted';
                        $this->store->audit($actor, 'filter.creation_recovered', 0, ['intent_id' => $id, 'filter_id' => $filterId, 'group_id' => $group]);
                    }
                }
                $this->record('filter_creations', 'id', $id, $row, $status, $actor);
                return $status;
            });
            if ($status !== null) {
                $summary['checked']++;
                $summary[$status === 'adopted' ? 'adopted' : 'blocked']++;
            }
        }
        $this->store->audit($actor, 'reconciliation.completed', 0, $summary + ['group_id' => $group]);
        return $summary;
    }
}

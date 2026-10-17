<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\PoolCleanupGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

// Explicit administrator retirement of externally deleted segments. Never reuse
// their IDs, infer successful delivery, or initiate any provider mutation.
final class PoolCleanup
{
    public function __construct(private Store $store, private PoolCleanupGateway $gateway, private array $config)
    {
    }

    private function configuration(): array
    {
        $connection = $this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
        $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
        $current = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $account = (string) ($connection['account_id'] ?? '');
        $group = (int) ($current['group_id'] ?? 0);
        if ($account === '' || ($current['mode'] ?? '') !== 'live' || ($this->config['mode'] ?? '') !== 'live' || $group < 1 || $group !== (int) ($this->config['group_id'] ?? 0)) {
            throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
        }
        return [$account, $group];
    }

    private function inspect(string $account, int $group): array
    {
        if ($this->gateway->account() !== $account) {
            throw new \RuntimeException('COM_INTERCOM_ACCOUNT_MISMATCH', 409);
        }
        $remote = $this->gateway->filters($group);
        if (!array_is_list($remote)) {
            throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
        }
        $present = [];
        foreach ($remote as $row) {
            if (!is_array($row) || !ctype_digit((string) ($row['id'] ?? '')) || (int) $row['id'] < 1) {
                throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
            }
            $present[] = (int) $row['id'];
        }
        $filters = [];
        foreach ($this->store->rows("SELECT * FROM #__intercom_filters WHERE group_id=$group AND managed=1 ORDER BY filter_id FOR UPDATE") as $filter) {
            $id = (int) $filter['filter_id'];
            if (in_array($id, $present, true)) {
                continue;
            }
            $this->gateway->assertFilterAbsent($group, $id);
            $draftId = (int) ($filter['draft_id'] ?? 0);
            $draft = $draftId ? $this->store->row("SELECT * FROM #__intercom_drafts WHERE id=$draftId FOR UPDATE") : null;
            if ($draftId) {
                if (!$draft || $draft['delivery_mode'] !== 'live' || (int) $draft['filter_id'] !== $id || !in_array($draft['state'], ['draft', 'tested', 'uncertain', 'submitted', 'cancelled', 'deleted'], true)) {
                    throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
                }
                $operation = $this->store->row("SELECT outcome FROM #__intercom_test_operations WHERE draft_id=$draftId FOR UPDATE");
                $acceptance = $this->store->row("SELECT state FROM #__intercom_acceptance WHERE draft_id=$draftId FOR UPDATE");
                if (($operation['outcome'] ?? '') === 'running' || ($acceptance && !in_array($acceptance['state'], ['preparing', 'prepared', 'retired'], true))) {
                    throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
                }
                $archive = $this->store->row("SELECT state FROM #__intercom_archives WHERE draft_id=$draftId FOR UPDATE");
                if (($archive['state'] ?? '') === 'sending') {
                    throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
                }
                $filter['archive_state'] = $archive['state'] ?? null;
                $history = $this->store->row("SELECT * FROM #__intercom_history WHERE draft_id=$draftId FOR UPDATE");
                if ($history && ($history['state'] === 'completed' || $history['finished_at'] !== null || $history['account_id'] !== $account || (int) $history['group_id'] !== $group || (int) $history['filter_id'] !== $id || (int) $history['mailing_id'] !== (int) $draft['mailing_id'])) {
                    throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
                }
                if ((int) $draft['mailing_id'] > 0) {
                    $this->gateway->assertMailingAbsent((int) $draft['mailing_id']);
                }
                $submitted = $draft['state'] === 'submitted' || ($history && $history['requested_at'] !== null)
                    || $this->store->row("SELECT id FROM #__intercom_audit WHERE draft_id=$draftId AND event LIKE 'release.%' LIMIT 1");
                $filter['close_state'] = $draft['state'] === 'deleted' ? 'deleted' : ($submitted ? 'closed_unverified' : 'cancelled');
                $filter['draft_state'] = $draft['state'];
                $filter['revision'] = (int) $draft['revision'];
                $filter['generation'] = (int) $draft['lease_generation'];
                $filter['mailing_id'] = (int) $draft['mailing_id'];
            }
            // Retired creations must no longer consume capacity. Never retire an
            // in-flight creation: its remote write may still finish later.
            $filter['creations'] = $this->store->rows("SELECT id,state,filter_id,remote_name FROM #__intercom_filter_creations WHERE group_id=$group AND filter_id=$id ORDER BY id FOR UPDATE");
            foreach ($filter['creations'] as $creation) {
                if ($creation['state'] !== 'created') {
                    throw new \RuntimeException('COM_INTERCOM_CLEANUP_UNSAFE', 409);
                }
            }
            // Reconciliation timestamps do not change ownership or the reviewed operation.
            unset($filter['checked_at'], $filter['reconciliation_status']);
            $filters[] = $filter;
        }
        return ['account' => $account, 'group' => $group, 'filters' => $filters,
            'fingerprint' => hash('sha256', json_encode([$account, $group, $filters], JSON_THROW_ON_ERROR))];
    }

    public function preview(): array
    {
        return $this->store->transaction(function (): array {
            [$account, $group] = $this->configuration();
            return $this->inspect($account, $group);
        });
    }

    public function apply(string $fingerprint, bool $confirmed, string $reason, int $actor): int
    {
        $reason = trim($reason);
        if (!$confirmed || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || $reason === '' || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1f]/', $reason)) {
            throw new \RuntimeException('COM_INTERCOM_CLEANUP_CONFIRM', 422);
        }
        return $this->store->transaction(function () use ($fingerprint, $reason, $actor): int {
            [$account, $group] = $this->configuration();
            $quoted = $this->store->q($fingerprint);
            if ($this->store->row("SELECT id FROM #__intercom_audit WHERE event='pool.cleaned' AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.fingerprint'))=$quoted LIMIT 1")) {
                return 0;
            }
            $preview = $this->inspect($account, $group);
            if (!hash_equals($preview['fingerprint'], $fingerprint)) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            foreach ($preview['filters'] as $filter) {
                $id = (int) $filter['filter_id'];
                $draft = (int) ($filter['draft_id'] ?? 0);
                if ($draft) {
                    $state = $this->store->q($filter['close_state']);
                    $this->store->execute("UPDATE #__intercom_drafts SET state=$state,revision=revision+1,filter_id=NULL,mailing_id=0,mailing_attempted=0,send_at=0,tested_revision=NULL,tested_fingerprint=NULL,tested_audience=NULL,tested_count=NULL,estimate_count=NULL,estimate_checked=NULL,audience_fingerprint=NULL,audience_rules=NULL,lease_until=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=$draft");
                    // Freeze old IDs and submission evidence in history; do not fabricate completion.
                    $this->store->execute("UPDATE #__intercom_history SET state=$state WHERE draft_id=$draft");
                    $this->store->execute("UPDATE #__intercom_archives SET state='uncertain',updated_at=UTC_TIMESTAMP() WHERE draft_id=$draft AND state='pending'");
                    $this->store->execute("UPDATE #__intercom_acceptance SET state='retired' WHERE draft_id=$draft AND state IN ('preparing','prepared')");
                }
                $this->store->execute("UPDATE #__intercom_filters SET managed=0,draft_id=NULL,reconciliation_status='retired',checked_at=UTC_TIMESTAMP() WHERE filter_id=$id");
                $this->store->execute("UPDATE #__intercom_filter_creations SET state='retired',reconciliation_status='retired',checked_at=UTC_TIMESTAMP() WHERE group_id=$group AND filter_id=$id AND state='created'");
                $this->store->audit($actor, 'filter.retired', $draft, ['filter_id' => $id, 'mailing_id' => $filter['mailing_id'] ?? 0,
                    'group_id' => $group, 'previous_state' => $filter['draft_state'] ?? null, 'state' => $filter['close_state'] ?? null,
                    'fingerprint' => $fingerprint, 'reason' => $reason, 'permanent_removal_attested' => true]);
            }
            $count = count($preview['filters']);
            $this->store->audit($actor, 'pool.cleaned', 0, ['fingerprint' => $fingerprint, 'group_id' => $group, 'account_id' => $account, 'retired' => $count]);
            return $count;
        });
    }
}

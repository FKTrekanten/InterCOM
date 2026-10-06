<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\RetirementGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Abandonment
{
    public function __construct(private Store $store, private RetirementGateway $gateway, private array $config)
    {
    }

    private function configuration(?array $operation = null): string
    {
        $connection = $this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
        $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
        $config = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $account = (string) ($connection['account_id'] ?? '');
        if (
            $account === '' || ($config['mode'] ?? '') !== 'live' || ($this->config['mode'] ?? '') !== 'live'
            || (int) ($config['group_id'] ?? 0) < 1 || (int) $config['group_id'] !== (int) ($this->config['group_id'] ?? 0)
            || ($operation && ($operation['account_id'] !== $account || (int) $operation['group_id'] !== (int) $config['group_id']))
        ) {
            throw new \RuntimeException('COM_INTERCOM_ABANDON_UNSAFE', 409);
        }
        return $account;
    }

    public function details(int $filter): array
    {
        $row = $this->store->row("SELECT f.*,d.state draft_state,d.revision,d.lease_generation,d.mailing_id,d.delivery_mode FROM #__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id WHERE f.filter_id=$filter");
        if (!$row) {
            throw new \RuntimeException('COM_INTERCOM_DRAFT_NOT_FOUND', 404);
        }
        $row['operation'] = $this->store->row("SELECT * FROM #__intercom_abandonments WHERE filter_id=$filter ORDER BY id DESC LIMIT 1");
        return $row;
    }

    private function candidate(int $filter, int $revision, int $generation, string $account): array
    {
        $lease = $this->store->row("SELECT * FROM #__intercom_filters WHERE filter_id=$filter FOR UPDATE");
        $id = (int) ($lease['draft_id'] ?? 0);
        $draft = $this->store->row("SELECT * FROM #__intercom_drafts WHERE id=$id FOR UPDATE");
        $history = $this->store->row("SELECT * FROM #__intercom_history WHERE draft_id=$id FOR UPDATE");
        if (
            !$draft || !$history || (int) ($lease['managed'] ?? 0) !== 1 || (int) $lease['group_id'] !== (int) $this->config['group_id']
            || $draft['delivery_mode'] !== 'live' || !in_array($draft['state'], ['draft', 'tested', 'cancelled', 'deleted'], true)
            || (int) $draft['revision'] !== $revision || (int) $draft['lease_generation'] !== $generation
            || (int) $draft['filter_id'] !== $filter || (int) $draft['mailing_id'] < 1 || (int) $draft['mailing_attempted'] !== 1 || (int) $draft['send_at'] !== 0
            || $history['delivery_mode'] !== 'live' || (int) $history['actor_id'] !== (int) $draft['owner_id']
            || $history['state'] !== 'prepared' || (int) $history['reconstructed'] !== 0 || $history['requested_at'] !== null
            || $history['started_at'] !== null || $history['finished_at'] !== null || (int) $history['scheduled_at'] !== 0
            || $history['account_id'] !== $account || (int) $history['group_id'] !== (int) $lease['group_id']
            || (int) $history['mailing_id'] !== (int) $draft['mailing_id'] || (int) $history['filter_id'] !== $filter
            || $this->store->row("SELECT id FROM #__intercom_audit WHERE draft_id=$id AND event LIKE 'release.%' LIMIT 1")
            || $this->store->row("SELECT draft_id FROM #__intercom_acceptance WHERE draft_id=$id AND state IN ('checking','submitted','uncertain','verified')")
        ) {
            throw new \RuntimeException('COM_INTERCOM_ABANDON_UNSAFE', 409);
        }
        return $draft;
    }

    public function inspect(int $filter, int $revision, int $generation, int $actor, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $reason)) {
            throw new \RuntimeException('COM_INTERCOM_ABANDON_REASON_REQUIRED', 422);
        }
        $operation = $this->store->transaction(function () use ($filter, $revision, $generation, $actor, $reason): array {
            $account = $this->configuration();
            $existing = $this->store->row("SELECT * FROM #__intercom_abandonments WHERE filter_id=$filter AND state IN ('inspecting','awaiting_removal','unverified') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            if ($existing) {
                $this->claimed($existing);
                if ((int) $existing['revision'] !== $revision || (int) $existing['lease_generation'] !== $generation) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                return $existing;
            }
            $draft = $this->candidate($filter, $revision, $generation, $account);
            $id = (int) $draft['id'];
            $group = (int) $this->config['group_id'];
            $mailing = (int) $draft['mailing_id'];
            $this->store->execute('INSERT INTO #__intercom_abandonments(draft_id,revision,lease_generation,filter_id,mailing_id,account_id,group_id,previous_state,actor_id,reason,state,created_at,updated_at) VALUES('
                . "$id,$revision,$generation,$filter,$mailing," . $this->store->q($account) . ",$group," . $this->store->q($draft['state']) . ",$actor," . $this->store->q($reason) . ",'inspecting',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $operationId = (int) $this->store->db->insertid();
            $this->store->execute("UPDATE #__intercom_drafts SET state='abandoning',updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $this->store->audit($actor, 'abandonment.requested', $id, ['operation_id' => $operationId, 'mailing_id' => $mailing, 'filter_id' => $filter]);
            return $this->store->row("SELECT * FROM #__intercom_abandonments WHERE id=$operationId");
        });
        $operationId = (int) $operation['id'];
        if ((int) $operation['baseline_verified'] === 1) {
            return $operationId;
        }
        try {
            $this->assertAccount($operation);
            $this->gateway->assertDraft((int) $operation['mailing_id'], (int) $operation['group_id'], $filter);
            $this->store->transaction(function () use ($operation, $operationId, $actor): void {
                $this->claimed($operation);
                $this->store->execute("UPDATE #__intercom_abandonments SET baseline_verified=1,state='awaiting_removal',updated_at=UTC_TIMESTAMP() WHERE id=$operationId");
                $this->store->audit($actor, 'abandonment.draft_verified', (int) $operation['draft_id'], ['operation_id' => $operationId]);
            });
        } catch (\Throwable $error) {
            $this->failed($operationId, $actor);
            throw $error;
        }
        return $operationId;
    }

    private function assertAccount(array $operation): void
    {
        if ($this->gateway->account() !== $operation['account_id']) {
            throw new \RuntimeException('COM_INTERCOM_ACCOUNT_MISMATCH', 409);
        }
    }

    private function claimed(array $operation): array
    {
        $this->configuration($operation);
        $operationId = (int) $operation['id'];
        $current = $this->store->row("SELECT * FROM #__intercom_abandonments WHERE id=$operationId FOR UPDATE");
        $id = (int) $operation['draft_id'];
        $filter = (int) $operation['filter_id'];
        $draft = $this->store->row("SELECT * FROM #__intercom_drafts WHERE id=$id FOR UPDATE");
        $lease = $this->store->row("SELECT * FROM #__intercom_filters WHERE filter_id=$filter FOR UPDATE");
        $history = $this->store->row("SELECT * FROM #__intercom_history WHERE draft_id=$id FOR UPDATE");
        if (
            !$current || !in_array($current['state'], ['inspecting', 'awaiting_removal', 'unverified'], true) || !$draft || !$lease || !$history
            || $draft['state'] !== 'abandoning' || $draft['delivery_mode'] !== 'live'
            || (int) $lease['draft_id'] !== $id || (int) $lease['managed'] !== 1 || (int) $lease['group_id'] !== (int) $operation['group_id']
            || (int) $draft['revision'] !== (int) $operation['revision'] || (int) $draft['lease_generation'] !== (int) $operation['lease_generation']
            || (int) $draft['mailing_id'] !== (int) $operation['mailing_id'] || (int) $draft['filter_id'] !== $filter
            || $history['state'] !== 'prepared' || $history['requested_at'] !== null || (int) $draft['send_at'] !== 0
            || $history['account_id'] !== $operation['account_id'] || (int) $history['group_id'] !== (int) $operation['group_id']
            || (int) $history['filter_id'] !== $filter || (int) $history['mailing_id'] !== (int) $operation['mailing_id']
            || $history['started_at'] !== null || $history['finished_at'] !== null || (int) $history['scheduled_at'] !== 0
        ) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        return $current;
    }

    private function failed(int $operationId, int $actor): void
    {
        $this->store->transaction(function () use ($operationId, $actor): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $operation = $this->store->row("SELECT * FROM #__intercom_abandonments WHERE id=$operationId FOR UPDATE");
            if ($operation && in_array($operation['state'], ['inspecting', 'awaiting_removal', 'unverified'], true)) {
                $id = (int) $operation['draft_id'];
                $draft = $this->store->row("SELECT state,revision,lease_generation,filter_id,mailing_id FROM #__intercom_drafts WHERE id=$id FOR UPDATE");
                if (
                    (int) $operation['baseline_verified'] === 0 && $draft && $draft['state'] === 'abandoning'
                    && (int) $draft['revision'] === (int) $operation['revision'] && (int) $draft['lease_generation'] === (int) $operation['lease_generation']
                    && (int) $draft['filter_id'] === (int) $operation['filter_id'] && (int) $draft['mailing_id'] === (int) $operation['mailing_id']
                ) {
                    // No retirement instructions were issued and no remote mutation
                    // occurred. A failed initial inspection must not trap a draft
                    // or stop ordinary completed-mailing reconciliation.
                    $this->store->execute('UPDATE #__intercom_drafts SET state=' . $this->store->q($operation['previous_state']) . ",lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=$id");
                    $this->store->execute("UPDATE #__intercom_abandonments SET state='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=$operationId");
                    $this->store->audit($actor, 'abandonment.inspection_failed', $id, ['operation_id' => $operationId]);
                    return;
                }
                $this->store->execute("UPDATE #__intercom_abandonments SET state='unverified',updated_at=UTC_TIMESTAMP() WHERE id=$operationId");
                $this->store->audit($actor, 'abandonment.unverified', (int) $operation['draft_id'], ['operation_id' => $operationId]);
            }
        });
    }

    public function verify(int $operationId, int $actor, bool $permanentlyRemoved): void
    {
        if (!$permanentlyRemoved) {
            throw new \RuntimeException('COM_INTERCOM_ABANDON_CONFIRM_REQUIRED', 422);
        }
        $operation = $this->store->transaction(function () use ($operationId): array {
            $operation = $this->store->row("SELECT * FROM #__intercom_abandonments WHERE id=$operationId");
            if (!$operation) {
                throw new \RuntimeException('COM_INTERCOM_DRAFT_NOT_FOUND', 404);
            }
            if ($operation['state'] === 'released') {
                return $operation;
            }
            if ($operation['state'] === 'cancelled') {
                throw new \RuntimeException('COM_INTERCOM_ABANDON_UNVERIFIED', 409);
            }
            $operation = $this->claimed($operation);
            if ((int) $operation['baseline_verified'] !== 1) {
                throw new \RuntimeException('COM_INTERCOM_ABANDON_UNVERIFIED', 409);
            }
            return $operation;
        });
        if ($operation['state'] === 'released') {
            return; // An old verification cannot modify a newly reused filter.
        }
        try {
            $this->assertAccount($operation);
            $this->gateway->assertAbsent((int) $operation['mailing_id'], (int) $operation['group_id'], (int) $operation['filter_id']);
            $this->store->transaction(function () use ($operation, $operationId, $actor): void {
                // Serialize duplicate verification before validating ownership.
                $this->configuration($operation);
                $current = $this->store->row("SELECT state FROM #__intercom_abandonments WHERE id=$operationId FOR UPDATE");
                if ($current && $current['state'] === 'released') {
                    return;
                }
                $this->claimed($operation);
                $id = (int) $operation['draft_id'];
                $filter = (int) $operation['filter_id'];
                $state = $operation['previous_state'] === 'deleted' ? 'deleted' : 'cancelled';
                $this->store->execute("UPDATE #__intercom_filters SET draft_id=NULL,reconciliation_status='abandoned',checked_at=UTC_TIMESTAMP() WHERE filter_id=$filter AND draft_id=$id");
                $this->store->execute("UPDATE #__intercom_drafts SET state='$state',revision=revision+1,filter_id=NULL,mailing_id=0,mailing_attempted=0,tested_revision=NULL,tested_fingerprint=NULL,tested_audience=NULL,tested_count=NULL,estimate_count=NULL,estimate_checked=NULL,audience_fingerprint=NULL,audience_rules=NULL,lease_until=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=$id");
                $this->store->execute("UPDATE #__intercom_history SET state='abandoned' WHERE draft_id=$id AND state='prepared'");
                $this->store->execute("UPDATE #__intercom_acceptance SET state='retired' WHERE draft_id=$id AND state IN ('preparing','prepared','retired')");
                $this->store->execute("UPDATE #__intercom_abandonments SET state='released',verified_at=UTC_TIMESTAMP(),verified_by=$actor,updated_at=UTC_TIMESTAMP() WHERE id=$operationId");
                $this->store->audit($actor, 'abandonment.released', $id, ['operation_id' => $operationId, 'mailing_id' => (int) $operation['mailing_id'], 'filter_id' => $filter, 'permanent_removal_attested' => true]);
            });
        } catch (\Throwable $error) {
            $this->failed($operationId, $actor);
            throw $error;
        }
    }
}

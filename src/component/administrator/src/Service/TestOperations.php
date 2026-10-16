<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class TestOperations
{
    public function __construct(private Store $store)
    {
    }

    // Called in the same transaction as acquiring the testing state.
    public function claim(array $draft, int $group): string
    {
        $id = (int) $draft['id'];
        $operation = bin2hex(random_bytes(16));
        $account = $this->store->q($this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'] ?? '');
        $this->store->execute("DELETE FROM #__intercom_test_operations WHERE draft_id=$id");
        $this->store->execute("INSERT INTO #__intercom_test_operations (draft_id,operation_id,revision,lease_generation,previous_attempted,account_id,group_id,stage,updated_at) VALUES ($id," . $this->store->q($operation) . ',' . (int) $draft['revision'] . ',' . (int) $draft['lease_generation'] . ',' . (int) $draft['mailing_attempted'] . ",$account,$group,'validation',UTC_TIMESTAMP())");
        return $operation;
    }

    public function progress(int $id, string $operation, string $stage, int $mailing = 0): void
    {
        $this->store->transaction(function () use ($id, $operation, $stage, $mailing): void {
            $this->locked($id, $operation);
            $accepted = in_array($stage, ['accepted_da', 'accepted_en'], true) ? ',' . $stage . '=1' : '';
            $this->store->execute('UPDATE #__intercom_test_operations SET stage=' . $this->store->q($stage) . "$accepted,updated_at=UTC_TIMESTAMP() WHERE draft_id=$id");
            if ($mailing > 0) {
                $this->store->execute("UPDATE #__intercom_drafts SET mailing_id=$mailing WHERE id=$id");
                $this->store->execute("UPDATE #__intercom_history SET mailing_id=$mailing WHERE draft_id=$id AND state='prepared'");
            }
            $this->store->execute("UPDATE #__intercom_drafts SET updated_at=UTC_TIMESTAMP() WHERE id=$id");
        });
    }

    private function locked(int $id, string $operation): array
    {
        $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
        $draft = $this->store->row("SELECT * FROM #__intercom_drafts WHERE id=$id FOR UPDATE");
        $row = $this->store->row("SELECT * FROM #__intercom_test_operations WHERE draft_id=$id FOR UPDATE");
        if (!$draft || !$row || $row['operation_id'] !== $operation || $row['outcome'] !== 'running' || $draft['state'] !== 'testing' || (int) $draft['revision'] !== (int) $row['revision'] || (int) $draft['lease_generation'] !== (int) $row['lease_generation']) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        $account = $this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'] ?? '';
        $params = json_decode($this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'")['params'] ?? '{}', true);
        if ($draft['delivery_mode'] !== ($params['mode'] ?? 'fake') || $account !== $row['account_id'] || (int) ($params['group_id'] ?? 0) !== (int) $row['group_id']) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        return $row;
    }

    public function accept(int $id, string $operation, callable $finish): void
    {
        $this->store->transaction(function () use ($id, $operation, $finish): void {
            $this->locked($id, $operation);
            $finish();
            $this->store->execute("UPDATE #__intercom_test_operations SET outcome='accepted',stage='complete',updated_at=UTC_TIMESTAMP() WHERE draft_id=$id");
        });
    }

    public static function safeStage(string $stage): bool
    {
        return in_array($stage, ['validation', 'history', 'provider_checks', 'filter_confirmed', 'mailing_confirmed', 'test_da', 'accepted_da', 'test_en', 'accepted_en'], true);
    }

    public function fail(int $id, string $operation, \Throwable $error): void
    {
        $this->store->transaction(function () use ($id, $operation, $error): void {
            $row = $this->locked($id, $operation);
            $safe = self::safeStage($row['stage']);
            $state = $safe ? 'draft' : 'uncertain';
            // A failed filter write remains protected, even without a mailing ID.
            $attempted = in_array($row['stage'], ['validation', 'history', 'provider_checks'], true) ? (int) $row['previous_attempted'] : 1;
            $this->store->execute("UPDATE #__intercom_drafts SET state='$state',mailing_attempted=$attempted,tested_revision=NULL,tested_fingerprint=NULL,tested_audience=NULL,tested_count=NULL,updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $outcome = $safe ? 'failed' : 'uncertain';
            $this->store->execute("UPDATE #__intercom_test_operations SET outcome='$outcome',updated_at=UTC_TIMESTAMP() WHERE draft_id=$id");
            $draft = $this->store->row("SELECT owner_id FROM #__intercom_drafts WHERE id=$id");
            $this->store->audit((int) $draft['owner_id'], 'preview.failed', $id, ['operation' => $operation, 'stage' => $row['stage'], 'outcome' => $outcome, 'exception' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'code' => $error->getCode(), 'http_status' => $error instanceof \FKT\Component\Intercom\Administrator\Domain\ProviderFailure ? $error->httpStatus : null, 'transport_code' => $error instanceof \FKT\Component\Intercom\Administrator\Domain\ProviderFailure ? $error->transportCode : null]);
        });
    }

    public function interrupted(int $id): bool
    {
        $row = $this->store->row("SELECT * FROM #__intercom_test_operations WHERE draft_id=$id AND outcome='running'");
        if (!$row) {
            return false;
        }
        $this->fail($id, $row['operation_id'], new \RuntimeException('Interrupted operation'));
        return true;
    }
}

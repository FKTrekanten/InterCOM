<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Workflow
{
    public function __construct(private Store $store, private DeliveryGateway $gateway, private Policy $policy, private int $actor)
    {
    }

    public function save(array $input, int $id = 0, int $revision = 0): array
    {
        $message = Message::validate($input);
        $mode = $this->store->q($this->gateway->mode());
        $this->policy->assertAllowed($message['type'], $message['tags'], 'compose');
        return $this->store->transaction(function () use ($message, $id, $revision, $mode): array {
            $json = $this->store->q(json_encode($message, JSON_THROW_ON_ERROR));
            if ($id) {
                $draft = $this->store->draft($id, $this->actor, true);
                if ($draft['delivery_mode'] !== $this->gateway->mode()) {
                    throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
                }
                if ((int) $draft['revision'] !== $revision || !in_array($draft['state'], ['draft', 'tested'], true)) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                $this->store->execute("UPDATE #__intercom_drafts SET content=$json,revision=revision+1,tested_revision=NULL,state='draft',updated_at=UTC_TIMESTAMP() WHERE id=$id");
            } else {
                $this->store->execute("INSERT INTO #__intercom_drafts (owner_id,delivery_mode,content,revision,state,created_at,updated_at) VALUES ({$this->actor},$mode,$json,1,'draft',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $id = (int) $this->store->db->insertid();
            }
            $draft = $this->store->draft($id, $this->actor);
            $this->store->execute("INSERT INTO #__intercom_revisions (draft_id,revision,content,created_at) VALUES ($id,{$draft['revision']},$json,UTC_TIMESTAMP())");
            $this->store->audit($this->actor, 'draft.saved', $id, ['revision' => (int) $draft['revision']]);
            return $draft;
        });
    }

    private function begin(int $id, int $revision, string $operation): array
    {
        return $this->store->transaction(function () use ($id, $revision, $operation): array {
            // Serialize account changes with acquiring a provider reservation.
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $settings = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
            $configuredMode = json_decode($settings['params'] ?? '{}', true)['mode'] ?? 'fake';
            if ($configuredMode !== $this->gateway->mode()) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $draft = $this->store->draft($id, $this->actor, true);
            if ($draft['delivery_mode'] !== $this->gateway->mode()) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
            $this->policy->assertAllowed($message['type'], $message['tags'], $operation === 'release' ? 'send' : 'compose');
            if (
                (int) $draft['revision'] !== $revision || !in_array($draft['state'], ['draft', 'tested'], true)
                || ($operation === 'release' && ($draft['state'] !== 'tested' || (int) $draft['tested_revision'] !== $revision))
            ) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            if (empty($draft['filter_id'])) {
                $filter = $this->store->row('SELECT filter_id FROM #__intercom_filters WHERE draft_id IS NULL ORDER BY filter_id LIMIT 1 FOR UPDATE');
                if (!$filter) {
                    throw new \RuntimeException('COM_INTERCOM_POOL_BUSY', 409);
                }
                $draft['filter_id'] = (int) $filter['filter_id'];
                $this->store->execute("UPDATE #__intercom_filters SET draft_id=$id WHERE filter_id={$draft['filter_id']} AND draft_id IS NULL");
            }
            $lease = $this->store->row("SELECT draft_id FROM #__intercom_filters WHERE filter_id={$draft['filter_id']} FOR UPDATE");
            if ((int) ($lease['draft_id'] ?? 0) !== $id) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $state = $operation === 'release' ? 'releasing' : 'testing';
            $this->store->execute("UPDATE #__intercom_drafts SET state='$state',filter_id={$draft['filter_id']},updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $this->store->audit($this->actor, $operation . '.intent', $id, ['revision' => $revision]);
            return $draft;
        });
    }

    public function preview(int $id, int $revision, string $email): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_EMAIL', 422);
        }
        $draft = $this->begin($id, $revision, 'preview');
        try {
            if ($draft['delivery_mode'] !== $this->gateway->mode()) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
            if (
                array_diff($message['tags'], $this->gateway->tags('group'))
                || array_diff($message['memberships'], $this->gateway->tags('membership'))
            ) {
                throw new \RuntimeException('COM_INTERCOM_SCOPE_DENIED');
            }
            $mailing = $this->gateway->prepare($message, (int) $draft['filter_id'], (int) $draft['mailing_id']);
            $this->store->execute("UPDATE #__intercom_drafts SET mailing_id=$mailing WHERE id=$id AND state='testing'");
            $this->gateway->preview($mailing, $email);
            $this->store->transaction(function () use ($id, $revision): void {
                $this->store->execute("UPDATE #__intercom_drafts SET state='tested',tested_revision=$revision,updated_at=UTC_TIMESTAMP() WHERE id=$id AND state='testing'");
                $this->store->audit($this->actor, 'preview.succeeded', $id, ['revision' => $revision]);
            });
        } catch (\Throwable $e) {
            $this->store->execute("UPDATE #__intercom_drafts SET state='draft',tested_revision=NULL WHERE id=$id AND state='testing'");
            $this->store->audit($this->actor, 'preview.failed', $id);
            throw $e;
        }
        return $this->store->draft($id, $this->actor);
    }

    public function release(int $id, int $revision, int $timestamp): array
    {
        if ($timestamp && ($timestamp < time() + 60 || $timestamp > time() + 31536000)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_DATE', 422);
        }
        $draft = $this->begin($id, $revision, 'release');
        try {
            $this->gateway->release((int) $draft['mailing_id'], $timestamp);
            $this->store->transaction(function () use ($id, $timestamp): void {
                $state = $timestamp ? 'scheduled' : 'submitted';
                $this->store->execute("UPDATE #__intercom_drafts SET state='$state',send_at=$timestamp,updated_at=UTC_TIMESTAMP() WHERE id=$id AND state='releasing'");
                $this->store->audit($this->actor, 'release.accepted', $id, ['send_at' => $timestamp]);
            });
        } catch (\Throwable) {
            $this->store->execute("UPDATE #__intercom_drafts SET state='uncertain',updated_at=UTC_TIMESTAMP() WHERE id=$id AND state='releasing'");
            $this->store->audit($this->actor, 'release.uncertain', $id);
            throw new \RuntimeException('COM_INTERCOM_UNCERTAIN', 409);
        }
        // Never auto-reuse a submitted filter based on elapsed time alone.
        return $this->store->draft($id, $this->actor);
    }

    public function cancel(int $id, int $revision): void
    {
        $this->store->transaction(function () use ($id, $revision): void {
            // Serialize account changes with acquiring a provider reservation.
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $settings = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
            $configuredMode = json_decode($settings['params'] ?? '{}', true)['mode'] ?? 'fake';
            if ($configuredMode !== $this->gateway->mode()) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $draft = $this->store->draft($id, $this->actor, true);
            if ($draft['delivery_mode'] !== $this->gateway->mode()) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
            $this->policy->assertAllowed($message['type'], $message['tags'], 'compose');
            if (!in_array($draft['state'], ['draft', 'tested'], true) || (int) $draft['revision'] !== $revision) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $this->store->execute("UPDATE #__intercom_drafts SET state='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=$id");
            // Cancelled remote drafts retain their reservation pending operator reconciliation.
            $this->store->audit($this->actor, 'draft.cancelled', $id);
        });
    }

    public function maintain(int $retentionDays): void
    {
        $days = max(1, min(3650, $retentionDays));
        $this->store->transaction(function () use ($days): void {
            $stale = $this->store->rows("SELECT id,state FROM #__intercom_drafts WHERE state IN ('testing','releasing') AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE FOR UPDATE");
            foreach ($stale as $row) {
                $id = (int) $row['id'];
                $this->store->execute("UPDATE #__intercom_drafts SET state='uncertain' WHERE id=$id");
                $this->store->audit(0, 'operation.interrupted', $id);
            }
            $this->store->execute("DELETE FROM #__intercom_audit WHERE created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            $this->store->execute("DELETE FROM #__intercom_revisions WHERE created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            // Purge inactive terminal message content, retaining operational IDs/leases.
            $this->store->execute("UPDATE #__intercom_drafts SET content='{}' WHERE state IN ('submitted','cancelled') AND updated_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            $this->store->audit(0, 'maintenance.completed', 0, ['retention_days' => $days]);
        });
    }
}

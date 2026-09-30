<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\FilterCreator;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Workflow
{
    public function __construct(private Store $store, private DeliveryGateway $gateway, private Policy $policy, private int $actor, private ?Catalog $catalog = null, private ?Archive $archive = null, private ?Reconciliation $reconciliation = null, private ?array $providerConfig = null)
    {
    }

    public function save(array $input, int $id = 0, int $revision = 0): array
    {
        return $this->saveMessage(Message::validate($input), $id, $revision);
    }

    public function saveAudience(array $input, int $id = 0, int $revision = 0): array
    {
        $audience = \FKT\Component\Intercom\Administrator\Domain\Audience::validate($input);
        return $this->store->transaction(function () use ($input, $audience, $id, $revision): array {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $this->policy->assertAllowed($audience['type'], $audience['tags'], 'compose');
            $old = [];
            if ($id) {
                $draft = $this->store->draft($id, $this->actor, true);
                if ((int) $draft['revision'] !== $revision || !in_array($draft['state'], ['draft', 'tested'], true)) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                $old = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
                $this->catalog?->snapshot($audience);
                if ($draft['delivery_mode'] !== $this->gateway->mode()) {
                    throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
                }
                if (\FKT\Component\Intercom\Administrator\Domain\Audience::validate($old) === $audience) {
                    return $draft;
                }
            }
            // Audience saves never overwrite a previously saved subject/body.
            $message = Message::validate(array_merge($id ? $old : $input, $audience), false);
            return $this->saveMessage($message, $id, $revision);
        });
    }

    private function settings(): array
    {
        $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        $params = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        if ($this->providerConfig !== null) {
            foreach (['mode','group_id','sender_email','unsubscribe_form_id'] as $key) {
                if ((string) ($params[$key] ?? '') !== (string) ($this->providerConfig[$key] ?? '')) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
            }
        }
        return $params;
    }

    private function audienceIdentity(array $message): array
    {
        $params = $this->settings();
        $rules = \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway::filterRules($message);
        $account = (string) ($this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'] ?? '');
        return [$rules, \FKT\Component\Intercom\Administrator\Domain\Audience::fingerprint($rules, $message['definition'] ?? ['type_key' => $message['type']], $account, $this->gateway->mode(), (int) ($params['group_id'] ?? 0))];
    }

    private function saveMessage(array $message, int $id, int $revision): array
    {
        $mode = $this->store->q($this->gateway->mode());
        $this->policy->assertAllowed($message['type'], $message['tags'], 'compose');
        return $this->store->transaction(function () use ($message, $id, $revision, $mode): array {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            if ($this->catalog) {
                $message['definition'] = $this->catalog->snapshot($message);
                $message['design'] = (new Design($this->store))->snapshot();
                $message['footer'] = $this->catalog->footer();
                $message['tag_labels'] = $this->catalog->tagLabels($message);
                $this->store->execute('UPDATE #__intercom_types SET used=1 WHERE id=' . (int) $message['definition']['id']);
            }
            $json = $this->store->q(json_encode($message, JSON_THROW_ON_ERROR));
            if ($id) {
                $draft = $this->store->draft($id, $this->actor, true);
                if ($draft['delivery_mode'] !== $this->gateway->mode()) {
                    throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
                }
                if ((int) $draft['revision'] !== $revision || !in_array($draft['state'], ['draft', 'tested'], true)) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                [$rules, $audience] = $this->audienceIdentity($message);
                $invalidate = ($draft['audience_fingerprint'] ?? '') !== $audience ? ',estimate_count=NULL,estimate_checked=NULL,audience_fingerprint=NULL,audience_rules=NULL' : '';
                $this->store->execute("UPDATE #__intercom_drafts SET content=$json$invalidate,revision=revision+1,tested_revision=NULL,tested_fingerprint=NULL,state='draft',updated_at=UTC_TIMESTAMP() WHERE id=$id");
            } else {
                $this->store->execute("INSERT INTO #__intercom_drafts (owner_id,delivery_mode,content,revision,state,mailing_attempted,created_at,updated_at) VALUES ({$this->actor},$mode,$json,1,'draft',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
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
            $this->settings();
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
            Message::validate($message, $operation !== 'estimate');
            if ($this->catalog) {
                if (($message['definition'] ?? null) !== $this->catalog->snapshot($message) || ($message['design'] ?? null) !== (new Design($this->store))->snapshot() || ($message['footer'] ?? null) !== $this->catalog->footer() || ($message['tag_labels'] ?? null) !== $this->catalog->tagLabels($message)) {
                    throw new \RuntimeException('COM_INTERCOM_DEFINITION_CHANGED', 409);
                }
                $fingerprint = $this->catalog->fingerprint($message);
                if ($operation === 'release' && ($draft['tested_fingerprint'] ?? '') !== $fingerprint) {
                    throw new \RuntimeException('COM_INTERCOM_DEFINITION_CHANGED', 409);
                }
                $draft['current_fingerprint'] = $fingerprint;
            }
            $this->policy->assertAllowed($message['type'], $message['tags'], $operation === 'release' ? 'send' : 'compose');
            if (
                (int) $draft['revision'] !== $revision || !in_array($draft['state'], ['draft', 'tested'], true)
                || ($operation === 'release' && ($draft['state'] !== 'tested' || (int) $draft['tested_revision'] !== $revision))
            ) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            // New live mailings use only filters created by Intercom for this list.
            $params = json_decode($settings['params'] ?? '{}', true) ?: [];
            $groupId = (int) ($params['group_id'] ?? 0);
            if (empty($draft['filter_id'])) {
                $where = 'managed=1 AND group_id=' . ($configuredMode === 'live' ? $groupId : 0);
                $filter = $this->store->row("SELECT filter_id FROM #__intercom_filters WHERE draft_id IS NULL AND $where ORDER BY filter_id LIMIT 1 FOR UPDATE");
                if (!$filter) {
                    throw new \RuntimeException('COM_INTERCOM_POOL_BUSY', 409);
                }
                $draft['filter_id'] = (int) $filter['filter_id'];
                $this->store->execute("UPDATE #__intercom_filters SET draft_id=$id,reconciliation_status='',checked_at=NULL WHERE filter_id={$draft['filter_id']} AND draft_id IS NULL");
            }
            $lease = $this->store->row("SELECT draft_id FROM #__intercom_filters WHERE filter_id={$draft['filter_id']} FOR UPDATE");
            if ((int) ($lease['draft_id'] ?? 0) !== $id) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $ownership = $this->store->row("SELECT group_id,managed FROM #__intercom_filters WHERE filter_id={$draft['filter_id']}");
            if ((int) ($ownership['managed'] ?? 0) !== 1 || (int) ($ownership['group_id'] ?? -1) !== ($configuredMode === 'live' ? $groupId : 0)) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $state = $operation === 'release' ? 'releasing' : ($operation === 'estimate' ? 'estimating' : 'testing');
            $attempt = $operation === 'preview' ? ',mailing_attempted=1' : '';
            $minutes = max(5, min(240, (int) ($params['audience_lease_minutes'] ?? 30)));
            $draft['lease_generation'] = (int) $draft['lease_generation'] + 1;
            $this->store->execute("UPDATE #__intercom_drafts SET state='$state'$attempt,filter_id={$draft['filter_id']},lease_generation={$draft['lease_generation']},lease_until=UTC_TIMESTAMP()+INTERVAL $minutes MINUTE,updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $this->store->audit($this->actor, $operation . '.intent', $id, ['revision' => $revision]);
            return $draft;
        });
    }

    private function ensureCapacity(): void
    {
        $intent = $this->store->transaction(function (): ?array {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $this->settings();
            $settings = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
            $params = json_decode($settings['params'] ?? '{}', true) ?: [];
            $mode = $params['mode'] ?? 'fake';
            if ($mode !== $this->gateway->mode()) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $cap = max(1, min(20, (int) ($params['max_filters'] ?? 5)));
            if ($mode === 'fake') {
                $free = $this->store->row('SELECT filter_id FROM #__intercom_filters WHERE group_id=0 AND managed=1 AND draft_id IS NULL LIMIT 1');
                if ($free) {
                    return null;
                }
                $count = (int) ($this->store->row('SELECT COUNT(*) AS n FROM #__intercom_filters WHERE group_id=0 AND managed=1')['n'] ?? 0);
                if ($count >= $cap) {
                    throw new \RuntimeException('COM_INTERCOM_POOL_BUSY', 409);
                }
                // Older simulations can retain IDs after an upgrade; never collide with them.
                $last = (int) ($this->store->row('SELECT MAX(filter_id) AS id FROM #__intercom_filters WHERE filter_id >= 4000000001')['id'] ?? 4000000000);
                $filterId = max(4000000000, $last) + 1;
                if ($filterId > 4294967295) {
                    throw new \RuntimeException('COM_INTERCOM_POOL_BUSY', 409);
                }
                $this->store->execute("INSERT INTO #__intercom_filters (filter_id,group_id,managed) VALUES ($filterId,0,1)");
                $this->store->audit($this->actor, 'filter.simulated_created', 0, ['filter_id' => $filterId]);
                return null;
            }
            $groupId = (int) ($params['group_id'] ?? 0);
            if ($groupId < 1 || !$this->gateway instanceof FilterCreator) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
            }
            if ($this->store->row("SELECT filter_id FROM #__intercom_filters WHERE group_id=$groupId AND managed=1 AND draft_id IS NULL LIMIT 1")) {
                return null;
            }
            $count = (int) ($this->store->row("SELECT COUNT(*) AS n FROM #__intercom_filter_creations WHERE group_id=$groupId")['n'] ?? 0);
            if ($count >= $cap) {
                throw new \RuntimeException('COM_INTERCOM_POOL_BUSY', 409);
            }
            $name = 'Intercom-' . bin2hex(random_bytes(12));
            $this->store->execute("INSERT INTO #__intercom_filter_creations (group_id,remote_name,state,created_at) VALUES ($groupId," . $this->store->q($name) . ",'pending',UTC_TIMESTAMP())");
            return ['intent_id' => (int) $this->store->db->insertid(), 'group_id' => $groupId, 'name' => $name];
        });
        if ($intent === null) {
            return;
        }
        try {
            $filterId = $this->gateway->createFilter($intent['group_id'], $intent['name']);
            $this->store->transaction(function () use ($intent, $filterId): void {
                $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
                $creation = $this->store->row("SELECT state,filter_id FROM #__intercom_filter_creations WHERE id={$intent['intent_id']} FOR UPDATE");
                if (($creation['state'] ?? '') === 'created') {
                    if ((int) $creation['filter_id'] === $filterId) {
                        return;
                    }
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                $this->store->execute("INSERT INTO #__intercom_filters (filter_id,group_id,managed) VALUES ($filterId,{$intent['group_id']},1)");
                $this->store->execute("UPDATE #__intercom_filter_creations SET state='created',filter_id=$filterId WHERE id={$intent['intent_id']}");
                $this->store->audit($this->actor, 'filter.created', 0, ['filter_id' => $filterId, 'group_id' => $intent['group_id']]);
            });
        } catch (\Throwable) {
            // A timed-out POST may have created a remote filter. Hold its slot for reconciliation.
            $this->store->transaction(function () use ($intent): void {
                $this->store->execute("UPDATE #__intercom_filter_creations SET state='uncertain' WHERE id={$intent['intent_id']} AND state='pending'");
                $this->store->audit($this->actor, 'filter.creation_uncertain', 0, ['group_id' => $intent['group_id']]);
            });
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
    }

    public function estimate(int $id, int $revision, bool $force = false): array
    {
        $draft = $this->store->draft($id, $this->actor);
        if ($draft['delivery_mode'] !== $this->gateway->mode()) {
            throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
        }
        $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
        $this->policy->assertAllowed($message['type'], $message['tags'], 'compose');
        if ($this->catalog && ($message['definition'] ?? null) !== $this->catalog->snapshot($message)) {
            throw new \RuntimeException('COM_INTERCOM_DEFINITION_CHANGED', 409);
        }
        if ((int) $draft['revision'] !== $revision || !in_array($draft['state'], ['draft', 'tested'], true)) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        [$rules, $fingerprint] = $this->audienceIdentity($message);
        $minutes = max(1, min(60, (int) ($this->settings()['estimate_cache_minutes'] ?? 5)));
        if (!$force && $draft['filter_id'] && $draft['audience_fingerprint'] === $fingerprint && $draft['estimate_count'] !== null && strtotime(($draft['estimate_checked'] ?? '') . ' UTC') > time() - $minutes * 60) {
            $this->keepalive($id, $revision);
            return $this->store->draft($id, $this->actor);
        }
        if (!$this->gateway instanceof \FKT\Component\Intercom\Administrator\Domain\AudienceGateway) {
            return array_merge($draft, ['estimate_error' => 'COM_INTERCOM_ESTIMATE_UNAVAILABLE']);
        }
        try {
            try {
                $draft = $this->begin($id, $revision, 'estimate');
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'COM_INTERCOM_POOL_BUSY') {
                    throw $e;
                }
                $this->ensureCapacity();
                $draft = $this->begin($id, $revision, 'estimate');
            }
        } catch (\Throwable $e) {
            if (in_array($e->getCode(), [403, 404, 422], true)) {
                throw $e;
            }
            return array_merge($this->store->draft($id, $this->actor), ['estimate_error' => str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ESTIMATE_UNAVAILABLE']);
        }
        $updated = $draft['audience_fingerprint'] === $fingerprint && $draft['audience_rules'] === json_encode($rules, JSON_THROW_ON_ERROR);
        try {
            if (!$updated) {
                $this->gateway->updateAudience((int) $draft['filter_id'], $rules);
                $updated = true;
            }
            $count = $this->gateway->statistics((int) $draft['filter_id']);
            $this->store->transaction(function () use ($draft, $id, $revision, $rules, $fingerprint, $count): void {
                $current = $this->store->draft($id, $this->actor, true);
                if ($current['state'] !== 'estimating' || (int) $current['revision'] !== $revision || (int) $current['lease_generation'] !== (int) $draft['lease_generation']) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                $state = $draft['state'];
                $this->store->execute("UPDATE #__intercom_drafts SET state=" . $this->store->q($state) . ',audience_fingerprint=' . $this->store->q($fingerprint) . ',audience_rules=' . $this->store->q(json_encode($rules, JSON_THROW_ON_ERROR)) . ",estimate_count=$count,estimate_checked=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=$id");
                $this->store->audit($this->actor, 'audience.estimated', $id, ['count' => $count, 'mode' => $this->gateway->mode(), 'revision' => $revision]);
            });
        } catch (\Throwable) {
            // A failed rule write may have reached CleverReach. Preserve its lease.
            // A failed read after a confirmed write does not make a send uncertain.
            $state = $updated ? $draft['state'] : 'uncertain';
            $this->store->execute('UPDATE #__intercom_drafts SET state=' . $this->store->q($state) . ",estimate_count=NULL,estimate_checked=NULL WHERE id=$id AND state='estimating' AND lease_generation=" . (int) $draft['lease_generation']);
            $this->store->audit($this->actor, $updated ? 'audience.count_failed' : 'audience.write_uncertain', $id);
            return array_merge($this->store->draft($id, $this->actor), ['estimate_error' => 'COM_INTERCOM_ESTIMATE_UNAVAILABLE']);
        }
        return $this->store->draft($id, $this->actor);
    }

    public function keepalive(int $id, int $revision): void
    {
        $this->store->transaction(function () use ($id, $revision): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $draft = $this->store->draft($id, $this->actor, true);
            $this->policy->assertManageDraft();
            if ((int) $draft['revision'] !== $revision || $draft['state'] !== 'draft' || (int) $draft['mailing_attempted'] !== 0 || !$draft['filter_id']) {
                return;
            }
            $minutes = max(5, min(240, (int) ($this->settings()['audience_lease_minutes'] ?? 30)));
            $this->store->execute("UPDATE #__intercom_drafts SET lease_until=UTC_TIMESTAMP()+INTERVAL $minutes MINUTE WHERE id=$id");
        });
    }

    private function releasePreflight(int $id, int $revision, int $approvedCount): void
    {
        $draft = $this->store->draft($id, $this->actor);
        if (!in_array($draft['state'], ['draft', 'tested'], true)) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
        $this->policy->assertAllowed($message['type'], $message['tags'], 'send');
        if ($this->catalog && (($message['definition'] ?? null) !== $this->catalog->snapshot($message) || ($draft['tested_fingerprint'] ?? '') !== $this->catalog->fingerprint($message))) {
            throw new \RuntimeException('COM_INTERCOM_DEFINITION_CHANGED', 409);
        }
        if ((int) $draft['revision'] !== $revision || $draft['state'] !== 'tested' || (int) $draft['tested_revision'] !== $revision) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        if (!$this->gateway instanceof \FKT\Component\Intercom\Administrator\Domain\AudienceGateway) {
            if ($this->gateway->mode() === 'live') {
                throw new \RuntimeException('COM_INTERCOM_ESTIMATE_UNAVAILABLE');
            }
            return;
        }
        [$rules, $fingerprint] = $this->audienceIdentity($message);
        if ($draft['tested_audience'] !== $fingerprint || $draft['audience_fingerprint'] !== $fingerprint) {
            throw new \RuntimeException('COM_INTERCOM_AUDIENCE_CHANGED', 409);
        }
        $this->gateway->assertAudience((int) $draft['filter_id'], $rules);
        $count = $this->gateway->statistics((int) $draft['filter_id']);
        $this->store->execute("UPDATE #__intercom_drafts SET estimate_count=$count,estimate_checked=UTC_TIMESTAMP() WHERE id=$id AND revision=$revision AND state='tested'");
        if ($count === 0) {
            throw new \RuntimeException('COM_INTERCOM_NO_RECIPIENTS', 409);
        }
        if ($this->gateway->mode() === 'live' && $count !== $approvedCount) {
            throw new \RuntimeException('COM_INTERCOM_COUNT_CHANGED', 409);
        }
    }

    private function freeAudience(int $id): void
    {
        $this->store->execute("UPDATE #__intercom_filters SET draft_id=NULL WHERE draft_id=$id");
        $this->store->execute("UPDATE #__intercom_drafts SET filter_id=NULL,estimate_count=NULL,estimate_checked=NULL,audience_fingerprint=NULL,audience_rules=NULL,lease_until=NULL,lease_generation=lease_generation+1 WHERE id=$id");
    }

    public function preview(int $id, int $revision, string $email): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_EMAIL', 422);
        }
        if ($this->gateway instanceof \FKT\Component\Intercom\Administrator\Domain\AudienceGateway) {
            $estimated = $this->estimate($id, $revision, true);
            if (!empty($estimated['estimate_error'])) {
                throw new \RuntimeException($estimated['estimate_error'], 409);
            }
        }
        try {
            $draft = $this->begin($id, $revision, 'preview');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'COM_INTERCOM_POOL_BUSY') {
                throw $e;
            }
            $this->ensureCapacity();
            $draft = $this->begin($id, $revision, 'preview');
        }
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
            $message['audience_rules'] = json_decode($draft['audience_rules'] ?? 'null', true) ?? \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway::filterRules($message);
            $snapshot = (new History($this->store))->prepare($draft, $message);
            $message['prepared_html'] = $snapshot['html'];
            $message['prepared_text'] = $snapshot['text'];
            $mailing = $this->gateway->prepare($message, (int) $draft['filter_id'], (int) $draft['mailing_id']);
            $this->store->execute("UPDATE #__intercom_history SET mailing_id=$mailing WHERE draft_id=$id AND state='prepared'");
            $this->store->execute("UPDATE #__intercom_drafts SET mailing_id=$mailing WHERE id=$id AND state='testing'");
            $this->gateway->preview($mailing, $email);
            $this->store->transaction(function () use ($id, $revision, $draft): void {
                $fingerprint = $this->store->q($draft['current_fingerprint'] ?? '');
                $testedAudience = $this->store->q($draft['audience_fingerprint'] ?? '');
                $testedCount = $draft['estimate_count'] === null ? 'NULL' : (int) $draft['estimate_count'];
                $this->store->execute("UPDATE #__intercom_drafts SET tested_audience=$testedAudience,tested_count=$testedCount,tested_fingerprint=$fingerprint,state='tested',tested_revision=$revision,updated_at=UTC_TIMESTAMP() WHERE id=$id AND state='testing'");
                $this->store->audit($this->actor, 'preview.accepted', $id, ['revision' => $revision]);
            });
        } catch (\Throwable $e) {
            $state = isset($mailing) ? 'draft' : 'uncertain';
            $this->store->execute("UPDATE #__intercom_drafts SET state='$state',tested_revision=NULL WHERE id=$id AND state='testing'");
            $this->store->audit($this->actor, 'preview.failed', $id);
            throw $e;
        }
        return $this->store->draft($id, $this->actor);
    }

    public function release(int $id, int $revision, int $timestamp, int $approvedCount = -1): array
    {
        if ($timestamp && ($timestamp < time() + 60 || $timestamp > time() + 31536000)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_DATE', 422);
        }
        $this->releasePreflight($id, $revision, $approvedCount);
        $draft = $this->store->transaction(function () use ($id, $revision, $timestamp): array {
            $draft = $this->begin($id, $revision, 'release');
            (new History($this->store))->intent($draft, $timestamp);
            return $draft;
        });
        try {
            $this->gateway->release((int) $draft['mailing_id'], $timestamp);
            $this->store->transaction(function () use ($id, $timestamp, $draft): void {
                $state = $timestamp ? 'scheduled' : 'submitted';
                $this->store->execute("UPDATE #__intercom_drafts SET state='$state',send_at=$timestamp,updated_at=UTC_TIMESTAMP() WHERE id=$id AND state='releasing'");
                (new History($this->store))->outcome($id, $state);
                $this->archive?->queue($draft, $timestamp, $this->actor);
                $this->store->audit($this->actor, 'release.accepted', $id, ['send_at' => $timestamp]);
            });
        } catch (\Throwable) {
            $this->store->execute("UPDATE #__intercom_drafts SET state='uncertain',updated_at=UTC_TIMESTAMP() WHERE id=$id AND state='releasing'");
            (new History($this->store))->outcome($id, 'uncertain');
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
            $this->settings();
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
            if ((int) $draft['mailing_attempted'] === 0) {
                $this->freeAudience($id);
            }
            // Mailing-associated remote drafts retain their reservation for reconciliation.
            $this->store->audit($this->actor, 'draft.cancelled', $id);
        });
    }

    public function delete(int $id, int $revision): void
    {
        $this->store->transaction(function () use ($id, $revision): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $draft = $this->store->draft($id, $this->actor, true);
            if (!in_array($draft['state'], ['draft', 'tested', 'cancelled'], true) || (int) $draft['revision'] !== $revision) {
                throw new \RuntimeException('COM_INTERCOM_DELETE_DENIED', 409);
            }
            $this->policy->assertManageDraft();
            if ($draft['delivery_mode'] === 'fake' || (int) $draft['mailing_attempted'] === 0) {
                // Simulation has no external mailing that can still use this filter.
                $this->store->execute("UPDATE #__intercom_filters SET draft_id=NULL WHERE draft_id=$id");
                $this->freeAudience($id);
                $this->store->execute("UPDATE #__intercom_drafts SET mailing_id=0 WHERE id=$id");
            }
            $this->store->execute("UPDATE #__intercom_drafts SET state='deleted',revision=revision+1,tested_revision=NULL,tested_fingerprint=NULL,updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $this->store->audit($this->actor, 'draft.deleted', $id, ['previous_state' => $draft['state'], 'live_reservation_retained' => $draft['delivery_mode'] !== 'fake' && !empty($draft['filter_id'])]);
        });
    }

    public function restore(int $id, int $revision): array
    {
        $this->store->transaction(function () use ($id, $revision): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $draft = $this->store->draft($id, $this->actor, true, true);
            if ($draft['state'] !== 'deleted' || (int) $draft['revision'] !== $revision || $draft['content'] === '{}') {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
            $this->policy->assertAllowed($message['type'], $message['tags'], 'compose');
            $this->store->execute("UPDATE #__intercom_drafts SET state='draft',revision=revision+1,tested_revision=NULL,tested_fingerprint=NULL,updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $this->store->audit($this->actor, 'draft.restored', $id);
        });
        return $this->store->draft($id, $this->actor);
    }

    public function maintain(int $retentionDays): void
    {
        $days = max(1, min(3650, $retentionDays));
        $this->store->transaction(function () use ($days): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            foreach ($this->store->rows("SELECT id FROM #__intercom_drafts WHERE state IN ('draft','cancelled','deleted') AND mailing_attempted=0 AND filter_id IS NOT NULL AND lease_until<UTC_TIMESTAMP() FOR UPDATE") as $expired) {
                $this->freeAudience((int) $expired['id']);
                $this->store->audit(0, 'audience.lease_expired', (int) $expired['id']);
            }
            $stale = $this->store->rows("SELECT id,state FROM #__intercom_drafts WHERE state IN ('testing','releasing','estimating') AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE FOR UPDATE");
            foreach ($stale as $row) {
                $id = (int) $row['id'];
                $this->store->execute("UPDATE #__intercom_drafts SET state='uncertain' WHERE id=$id");
                (new History($this->store))->outcome($id, 'uncertain');
                $this->store->audit(0, 'operation.interrupted', $id);
            }
            $this->store->execute("DELETE FROM #__intercom_archives WHERE state IN ('submitted','uncertain') AND updated_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            $this->store->execute("DELETE FROM #__intercom_audit WHERE created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            $this->store->execute("DELETE FROM #__intercom_revisions WHERE created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            // Purge inactive terminal message content, retaining operational IDs/leases.
            $this->store->execute("UPDATE #__intercom_drafts SET content='{}' WHERE state IN ('completed','submitted','cancelled','deleted') AND updated_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
            (new History($this->store))->maintain($days);
            $this->store->audit(0, 'maintenance.completed', 0, ['retention_days' => $days]);
        });
        $this->reconciliation?->run();
        $this->archive?->maintain();
    }
}

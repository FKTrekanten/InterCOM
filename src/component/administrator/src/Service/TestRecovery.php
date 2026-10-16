<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class TestRecovery
{
    public function __construct(private Store $store)
    {
    }

    // Deliberately narrow legacy contract: the first and only preview failed
    // before History::prepare, under the operator-attested 0.3.24 workflow.
    public function eligible(array $draft): bool
    {
        $id = (int) $draft['id'];
        if ($draft['state'] !== 'uncertain' || (int) $draft['mailing_id'] !== 0 || (int) $draft['send_at'] !== 0 || $draft['delivery_mode'] !== 'live' || !$draft['filter_id']) {
            return false;
        }
        foreach (['history', 'test_operations', 'acceptance', 'abandonments'] as $table) {
            if ($this->store->row("SELECT draft_id FROM #__intercom_$table WHERE draft_id=$id")) {
                return false;
            }
        }
        $events = $this->store->rows("SELECT event,context,created_at FROM #__intercom_audit WHERE draft_id=$id ORDER BY id");
        $intents = $failures = $initialSave = 0;
        $intent = null;
        $savedRevision = 0;
        foreach ($events as $event) {
            if (!in_array($event['event'], ['draft.saved', 'estimate.intent', 'audience.estimated', 'preview.intent', 'preview.failed'], true)) {
                return false;
            }
            $context = json_decode($event['context'], true);
            if ($event['event'] === 'draft.saved' && (int) ($context['revision'] ?? 0) !== ++$savedRevision) {
                return false;
            }
            if ($event['event'] === 'draft.saved' && (int) ($context['revision'] ?? 0) === 1 && $event['created_at'] === $draft['created_at']) {
                $initialSave++;
            }
            if ($event['event'] === 'preview.intent') {
                $intents++;
                $intent = $event;
                if ((int) ($context['revision'] ?? 0) !== (int) $draft['revision'] || $event['created_at'] !== $draft['updated_at']) {
                    return false;
                }
            }
            if ($event['event'] === 'preview.failed') {
                $failures++;
                if (!$intent || $context !== [] || $event['created_at'] < $intent['created_at'] || strtotime($event['created_at'] . ' UTC') - strtotime($intent['created_at'] . ' UTC') > 300) {
                    return false;
                }
            } elseif ($failures > 0 || ($intent && $event['event'] !== 'preview.intent')) {
                return false;
            }
        }
        $params = json_decode($this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'")['params'] ?? '{}', true);
        $filter = $this->store->row('SELECT * FROM #__intercom_filters WHERE filter_id=' . (int) $draft['filter_id']);
        return $savedRevision === (int) $draft['revision'] && $initialSave === 1 && $intents === 1 && $failures === 1 && ($params['mode'] ?? '') === 'live'
            && (int) ($filter['draft_id'] ?? 0) === $id && (int) ($filter['managed'] ?? 0) === 1
            && (int) ($filter['group_id'] ?? 0) === (int) ($params['group_id'] ?? 0);
    }

    public function recover(int $id, int $owner, int $revision, bool $legacyVerified): void
    {
        $this->store->transaction(function () use ($id, $owner, $revision, $legacyVerified): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $draft = $this->store->draft($id, $owner, true);
            $previous = $this->store->row("SELECT event,context FROM #__intercom_audit WHERE draft_id=$id ORDER BY id DESC LIMIT 1");
            if ($legacyVerified && $draft['state'] === 'draft' && (int) $draft['revision'] === $revision + 1 && ($previous['event'] ?? '') === 'preview.recovered' && (int) (json_decode($previous['context'], true)['revision'] ?? 0) === $revision) {
                return; // Duplicate POST after a lost response changes nothing.
            }
            if (!$legacyVerified || (int) $draft['revision'] !== $revision || !$this->eligible($draft)) {
                throw new \RuntimeException('COM_INTERCOM_RECOVERY_BLOCKED', 409);
            }
            $this->store->row('SELECT filter_id FROM #__intercom_filters WHERE filter_id=' . (int) $draft['filter_id'] . ' FOR UPDATE');
            $this->store->execute("UPDATE #__intercom_drafts SET state='draft',revision=revision+1,mailing_attempted=0,tested_revision=NULL,tested_fingerprint=NULL,tested_audience=NULL,tested_count=NULL,estimate_count=NULL,estimate_checked=NULL,audience_fingerprint=NULL,audience_rules=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=$id");
            $this->store->audit($owner, 'preview.recovered', $id, ['contract' => '0.3.24-pre-history', 'revision' => $revision, 'filter_id' => (int) $draft['filter_id'], 'operator_verified' => true]);
        });
    }
}

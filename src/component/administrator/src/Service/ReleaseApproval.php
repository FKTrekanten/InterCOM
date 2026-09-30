<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\MailingStatus;

final class ReleaseApproval
{
    public function __construct(private Store $store)
    {
    }

    public function fingerprint(): string
    {
        $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        $config = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $account = $this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'] ?? '';
        return hash('sha256', json_encode([$account, array_intersect_key($config, array_flip(['mode','group_id','sender_name','sender_email','unsubscribe_form_id','acceptance_recipient'])),
            Message::templateVersion(), (new Design($this->store))->snapshot(), \FKT\Component\Intercom\Administrator\Domain\Footer::validate($config),
            $this->store->rows('SELECT id,type_key,suppression,category_id,state FROM #__intercom_types ORDER BY id'), $this->store->rows('SELECT * FROM #__intercom_type_translations ORDER BY type_id,language')], JSON_THROW_ON_ERROR));
    }

    public function valid(): bool
    {
        $fingerprint = $this->store->q($this->fingerprint());
        return $this->store->row("SELECT draft_id FROM #__intercom_acceptance WHERE state='verified' AND fingerprint=$fingerprint LIMIT 1") !== null;
    }

    public function latest(int $actor): ?array
    {
        return $this->store->row("SELECT a.*,d.revision,d.state draft_state,d.mailing_id FROM #__intercom_acceptance a JOIN #__intercom_drafts d ON d.id=a.draft_id WHERE a.actor_id=$actor ORDER BY a.draft_id DESC LIMIT 1");
    }

    public function prepared(array $draft, string $recipient, int $actor): void
    {
        $id = (int) $draft['id'];
        $fingerprint = $this->store->q($this->fingerprint());
        $hash = $this->store->q(hash('sha256', strtolower($recipient)));
        $this->store->execute("INSERT INTO #__intercom_acceptance(draft_id,actor_id,fingerprint,recipient_hash,state,created_at) VALUES($id,$actor,$fingerprint,$hash,'preparing',UTC_TIMESTAMP())");
        $this->store->audit($actor, 'acceptance.preparing', $id, ['count' => 1]);
    }

    public function check(int $id, int $actor, array $config, CleverReachGateway $gateway): void
    {
        $draft = $this->store->draft($id, $actor);
        $proof = $this->store->row("SELECT * FROM #__intercom_acceptance WHERE draft_id=$id AND actor_id=$actor");
        $recipient = (string) ($config['acceptance_recipient'] ?? '');
        if (
            !$proof || !in_array($proof['state'], ['prepared','checking'], true) || !hash_equals($proof['fingerprint'], $this->fingerprint())
            || !hash_equals($proof['recipient_hash'], hash('sha256', strtolower($recipient))) || !in_array($draft['state'], ['tested','releasing'], true)
        ) {
            throw new \RuntimeException('COM_INTERCOM_RELEASE_NOT_VERIFIED', 409);
        }
        $owned = $this->store->row('SELECT filter_id FROM #__intercom_filters WHERE managed=1 AND draft_id=' . $id . ' AND filter_id=' . (int) $draft['filter_id'] . ' AND group_id=' . (int) $config['group_id']);
        $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
        if (!$owned || ($message['acceptance_email'] ?? '') !== $recipient) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_RECIPIENT', 409);
        }
        $gateway->assertAudience((int) $draft['filter_id'], json_decode($draft['audience_rules'], true, 64, JSON_THROW_ON_ERROR));
        $gateway->assertOneRecipient((int) $draft['filter_id'], $recipient);
        $remote = $gateway->mailing((int) $draft['mailing_id']);
        if ((int) ($remote['category_id'] ?? -1) !== (int) ($message['definition']['category_id'] ?? 0) || ($remote['sender_name'] ?? '') !== $message['sender']) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_MAILING', 409);
        }
    }

    public function authorize(int $mailing, int $id = 0, int $actor = 0): void
    {
        if ($id === 0 && $this->valid()) {
            return;
        }
        if ($id && $this->store->row("SELECT a.draft_id FROM #__intercom_acceptance a JOIN #__intercom_drafts d ON d.id=a.draft_id WHERE a.draft_id=$id AND a.actor_id=$actor AND a.state='checking' AND d.owner_id=$actor AND d.mailing_id=$mailing AND d.state IN ('tested','releasing') AND a.fingerprint=" . $this->store->q($this->fingerprint()))) {
            return;
        }
        throw new \RuntimeException('COM_INTERCOM_RELEASE_NOT_VERIFIED', 409);
    }

    public function verify(int $id, int $actor, array $config, CleverReachGateway $gateway): void
    {
        $this->store->transaction(function () use ($id, $actor, $config, $gateway): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $proof = $this->store->row("SELECT * FROM #__intercom_acceptance WHERE draft_id=$id AND actor_id=$actor FOR UPDATE");
            $draft = $this->store->draft($id, $actor);
            if (!$proof || !in_array($proof['state'], ['submitted','verified','uncertain','checking'], true) || !hash_equals($proof['fingerprint'], $this->fingerprint())) {
                throw new \RuntimeException('COM_INTERCOM_RELEASE_NOT_VERIFIED', 409);
            }
            $remote = $gateway->mailing((int) $draft['mailing_id']);
            if (MailingStatus::status($remote, (int) $draft['mailing_id'], (int) $config['group_id']) !== 'completed') {
                throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_WAITING', 409);
            }
            if ($proof['state'] === 'verified') {
                return;
            }
            $this->store->execute("UPDATE #__intercom_acceptance SET state='verified',verified_at=UTC_TIMESTAMP() WHERE draft_id=$id");
            (new History($this->store))->completed($id, $remote);
            $this->store->audit($actor, 'acceptance.verified', $id, ['count' => 1, 'received_confirmed' => true]);
        });
    }
}

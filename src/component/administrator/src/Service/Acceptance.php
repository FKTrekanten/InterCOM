<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use Joomla\CMS\User\User;

final class Acceptance
{
    public function __construct(private Runtime $runtime, private User $user, private ?CleverReachGateway $gateway = null)
    {
        if (!$user->authorise('core.admin', 'com_intercom') || ($runtime->config['mode'] ?? 'fake') !== 'live') {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        $this->gateway ??= new CleverReachGateway(fn () => $runtime->connection->token(), $runtime->config);
    }

    public function prepare(): array
    {
        $r = $this->runtime;
        $recipient = (string) ($r->config['acceptance_recipient'] ?? '');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_RECIPIENT', 422);
        }
        $r->connection->pin((int) $this->user->id);
        $types = array_filter($r->catalog->types(), static fn ($t) => !$t['require_group']);
        if (!$types || !filter_var($r->config['sender_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_TYPE', 422);
        }
        $previous = (new ReleaseApproval($r->store))->latest((int) $this->user->id);
        if ($previous && in_array($previous['state'], ['preparing', 'prepared', 'checking', 'submitted', 'uncertain'], true)) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_EXISTING', 409);
        }
        $w = $r->workflow($this->user);
        $draft = $w->saveAcceptance(['type' => array_key_first($types), 'sender' => $r->config['sender_name'],
            'subject_da' => '[InterCOM] Leveringstest til én modtager', 'subject_en' => '[InterCOM] One-recipient acceptance test',
            'body_da' => "Hej {FIRSTNAME[std:Medlem]}\n\nDette er en test af InterCOM. Kontrollér layout, afsender, profillink og afmeldingsformular. Åbn formularen, men bekræft ikke afmeldingen.",
            'body_en' => "Hello {FIRSTNAME[std:Member]}\n\nThis is an InterCOM acceptance test. Check the layout, sender, member profile link and unsubscribe form. Open the form, but do not confirm an unsubscribe."], $recipient);
        $id = (int) $draft['id'];
        try {
            $draft = $w->estimate($id, (int) $draft['revision'], true);
            if (!empty($draft['estimate_error'])) {
                throw new \RuntimeException($draft['estimate_error'], 409);
            }
            // No remote mailing is created until exactly the approved eligible member is proven.
            $this->gateway->assertAudience((int) $draft['filter_id'], json_decode($draft['audience_rules'], true, 64, JSON_THROW_ON_ERROR));
            $this->gateway->assertOneRecipient((int) $draft['filter_id'], $recipient);
            $this->gateway->assertUnsubscribeForm((string) $r->config['unsubscribe_form_id'], (int) $r->config['group_id']);
            (new ReleaseApproval($r->store))->prepared($draft, $recipient, (int) $this->user->id);
            $draft = $w->preview($id, (int) $draft['revision'], (string) $this->user->email);
            $r->store->execute("UPDATE #__intercom_acceptance SET state='prepared' WHERE draft_id=$id AND state='preparing'");
            $r->store->audit((int) $this->user->id, 'acceptance.prepared', $id, ['count' => 1]);
        } catch (\Throwable $e) {
            $r->store->execute("UPDATE #__intercom_acceptance SET state='uncertain' WHERE draft_id=$id AND state='preparing'");
            $current = $r->store->draft($id, (int) $this->user->id);
            if ((int) $current['mailing_attempted'] === 0 && in_array($current['state'], ['draft', 'tested'], true)) {
                $w->delete($id, (int) $current['revision']);
            }
            throw $e;
        }
        return $draft;
    }

    public function release(int $id, int $revision): void
    {
        $r = $this->runtime;
        $actor = (int) $this->user->id;
        $approval = new ReleaseApproval($r->store);
        $r->store->transaction(function () use ($r, $id, $actor, $approval, $revision): void {
            $r->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $draft = $r->store->draft($id, $actor, true);
            if ((int) $draft['revision'] !== $revision) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $proof = $r->store->row("SELECT state FROM #__intercom_acceptance WHERE draft_id=$id AND actor_id=$actor FOR UPDATE");
            if (!$proof || $proof['state'] !== 'prepared') {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $approval->check($id, $actor, $r->config, $this->gateway);
            $r->store->execute("UPDATE #__intercom_acceptance SET state='checking' WHERE draft_id=$id AND state='prepared'");
        });
        try {
            $r->acceptanceWorkflow($this->user, $id)->release($id, $revision, 0, 1);
            $r->store->execute("UPDATE #__intercom_acceptance SET state='submitted' WHERE draft_id=$id AND state='checking'");
        } catch (\Throwable $e) {
            $state = $r->store->draft($id, $actor)['state'] === 'tested' ? 'prepared' : 'uncertain';
            $r->store->execute("UPDATE #__intercom_acceptance SET state='$state' WHERE draft_id=$id AND state='checking'");
            throw $e;
        }
    }

    public function retire(int $id, int $revision): void
    {
        $r = $this->runtime;
        $actor = (int) $this->user->id;
        $r->store->transaction(function () use ($r, $actor, $id, $revision): void {
            $r->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $proof = $r->store->row("SELECT state FROM #__intercom_acceptance WHERE draft_id=$id AND actor_id=$actor FOR UPDATE");
            if (!$proof || $proof['state'] !== 'prepared') {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $r->workflow($this->user)->delete($id, $revision);
            // Keep the remote mailing's reservation. Retiring a proof does not prove
            // the external mailing can no longer use its filter.
            $r->store->execute("UPDATE #__intercom_acceptance SET state='retired' WHERE draft_id=$id");
            $r->store->audit($actor, 'acceptance.retired', $id);
        });
    }

    public function verify(int $id): void
    {
        (new ReleaseApproval($this->runtime->store))->verify($id, (int) $this->user->id, $this->runtime->config, $this->gateway);
    }
}

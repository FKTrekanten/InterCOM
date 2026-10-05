<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Archive
{
    public function __construct(private Store $store, private string $address, private \Closure $finished, private \Closure $send)
    {
    }

    public function queue(array $draft, int $timestamp, int $actor): void
    {
        if ($this->address === '') {
            return;
        }
        if (!filter_var($this->address, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        $message = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
        $payload = ['subject' => '[InterCOM archive #' . (int) $draft['id'] . '] ' . $message['subject_da'],
            'html' => '<p>Archive of an accepted communication. Sender: ' . htmlspecialchars($message['sender'], ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>DA: ' . htmlspecialchars($message['subject_da'], ENT_QUOTES, 'UTF-8') . '</p>' . Message::bodyHtml($message, 'da')
                . '<hr><p>EN: ' . htmlspecialchars($message['subject_en'], ENT_QUOTES, 'UTF-8') . '</p>' . Message::bodyHtml($message, 'en')
                . '<hr><p>Audience criteria: ' . htmlspecialchars(json_encode(['groups' => $message['tags'], 'memberships' => $message['memberships'],
                    'age_from' => $message['age_from'], 'age_to' => $message['age_to'], 'gender' => $message['gender'],
                    'communication' => $message['definition']['key'] ?? $message['type'], 'suppression' => $message['definition']['suppression'] ?? $message['type']]), ENT_QUOTES, 'UTF-8') . '</p>',
            'text' => Message::text($message), 'mailing_id' => (int) $draft['mailing_id'], 'revision' => (int) $draft['revision']];
        foreach (['html', 'text'] as $field) {
            $payload[$field] = strtr($payload[$field], ['{FIRSTNAME[std:Medlem]}' => 'Medlem', '{FIRSTNAME[std:Member]}' => 'Member',
                '{ONLINE_VERSION}' => '', '{UNSUBSCRIBE}' => '']);
        }
        $row = (object) ['draft_id' => (int) $draft['id'], 'address' => $this->address,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'state' => 'pending',
            'due_at' => $timestamp ?: time(), 'updated_at' => gmdate('Y-m-d H:i:s')];
        $this->store->db->insertObject('#__intercom_archives', $row);
        $this->store->audit($actor, 'archive.queued', (int) $draft['id'], ['revision' => (int) $draft['revision']]);
    }

    public function maintain(): void
    {
        // A crashed SMTP call has an unknown outcome; never resend it automatically.
        $this->store->transaction(function (): void {
            foreach ($this->store->rows("SELECT draft_id FROM #__intercom_archives WHERE state='sending' AND updated_at < UTC_TIMESTAMP()-INTERVAL 15 MINUTE FOR UPDATE") as $row) {
                $id = (int) $row['draft_id'];
                $this->store->execute("UPDATE #__intercom_archives SET state='uncertain',updated_at=UTC_TIMESTAMP() WHERE draft_id=$id");
                $this->store->audit(0, 'archive.uncertain', $id);
            }
        });
        foreach ($this->store->rows("SELECT draft_id FROM #__intercom_archives WHERE state='pending' AND due_at <= UNIX_TIMESTAMP() ORDER BY due_at LIMIT 10") as $candidate) {
            $id = (int) $candidate['draft_id'];
            $row = $this->store->row("SELECT * FROM #__intercom_archives WHERE draft_id=$id");
            if (!$row || $row['state'] !== 'pending') {
                continue;
            }
            $payload = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
            try {
                if (!(($this->finished)((int) $payload['mailing_id']))) {
                    continue;
                }
            } catch (\Throwable) {
                $this->store->audit(0, 'archive.status_failed', $id);
                continue;
            }
            $lease = $this->store->transaction(function () use ($id): ?array {
                $current = $this->store->row("SELECT * FROM #__intercom_archives WHERE draft_id=$id FOR UPDATE");
                if (!$current || $current['state'] !== 'pending') {
                    return null;
                }
                $this->store->execute("UPDATE #__intercom_archives SET state='sending',updated_at=UTC_TIMESTAMP() WHERE draft_id=$id");
                $this->store->audit(0, 'archive.intent', $id);
                return $current;
            });
            if (!$lease) {
                continue;
            }
            try {
                if (!(($this->send)($lease['address'], $payload))) {
                    throw new \RuntimeException('Archive not accepted');
                }
                $this->store->execute("UPDATE #__intercom_archives SET state='submitted',updated_at=UTC_TIMESTAMP() WHERE draft_id=$id AND state='sending'");
                $this->store->audit(0, 'archive.accepted', $id);
            } catch (\Throwable) {
                $this->store->execute("UPDATE #__intercom_archives SET state='uncertain',updated_at=UTC_TIMESTAMP() WHERE draft_id=$id AND state='sending'");
                $this->store->audit(0, 'archive.uncertain', $id);
            }
        }
    }
}

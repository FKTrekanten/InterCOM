<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\FilterCreator;
use FKT\Component\Intercom\Administrator\Domain\Message;

final class CleverReachGateway implements DeliveryGateway, FilterCreator
{
    public function __construct(private \Closure $token, private array $config)
    {
    }

    private function request(string $method, string $path, ?array $data = null): mixed
    {
        $token = ($this->token)();
        $curl = curl_init('https://rest.cleverreach.com/v3' . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($error || $status < 200 || $status >= 300) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        if ($status === 204 || $body === '') {
            return null;
        }
        try {
            $result = json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        if ($result === false || (is_array($result) && isset($result['error']))) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return $result;
    }

    public function groups(): array
    {
        $groups = $this->request('GET', '/groups');
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return $groups;
    }

    public function createFilter(int $groupId, string $name): int
    {
        $result = $this->request('POST', '/groups/' . $groupId . '/filters', [
            'name' => $name,
            'operator' => 'AND',
            'rules' => [['field' => 'email', 'logic' => 'eq', 'condition' => $name . '@example.invalid']],
        ]);
        $id = (int) ($result['id'] ?? 0);
        if ($id < 1) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return $id;
    }

    public function mode(): string
    {
        return 'live';
    }

    public function tags(string $origin): array
    {
        $tags = [];
        for ($page = 0; $page < 100; $page++) {
            $rows = $this->request('GET', '/tags?limit=100&offset=' . ($page * 100));
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
            }
            foreach ($rows as $row) {
                if (($row['origin'] ?? '') === $origin) {
                    $tags[] = $origin . '.' . $row['tag'];
                }
            }
            if (count($rows) < 100) {
                return array_values(array_unique($tags));
            }
        }
        throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
    }

    public static function filterRules(array $message, ?\DateTimeImmutable $today = null): array
    {
        $rules = [];
        $add = static function (string $field, string $logic, string $condition) use (&$rules): void {
            $rules[] = ['operator' => $rules ? 'AND' : '', 'field' => $field, 'logic' => $logic, 'condition' => $condition];
        };
        if ($message['tags']) {
            $add('tags', 'CONTAINS', implode(',', $message['tags']));
        }
        if ($message['memberships']) {
            $add('tags', 'CONTAINS', implode(',', $message['memberships']));
        }
        $today ??= new \DateTimeImmutable('today', new \DateTimeZone('Europe/Copenhagen'));
        if ($message['age_from']) {
            $add('birthdate', 'SM', $today->modify('-' . $message['age_from'] . ' years +1 day')->format('Y-m-d'));
        }
        if ($message['age_to']) {
            $add('birthdate', 'BG', $today->modify('-' . $message['age_to'] . ' years -1 day')->format('Y-m-d'));
        }
        if ($message['gender']) {
            $add('gender', 'EQ', $message['gender']);
        }
        $add('suppression', 'NOCONTAINS', $message['definition']['suppression'] ?? $message['type']);
        return $rules;
    }

    public function prepare(array $message, int $filterId, int $mailingId): int
    {
        $rules = self::filterRules($message);
        $this->request(
            'PUT',
            '/groups/' . (int) $this->config['group_id'] . '/filters/' . $filterId,
            ['name' => 'Intercom ' . $filterId, 'rules' => $rules]
        );
        $category = (int) ($message['definition']['category_id'] ?? 0);
        $da = Message::translation($message['definition'] ?? [], 'da-DK');
        $en = Message::translation($message['definition'] ?? [], 'en-GB');
        $prefix = static fn ($value) => $value ? '[' . $value . '] ' : '';
        $body = ['name' => 'Intercom ' . gmdate('Y-m-d H:i'), 'subject' =>
            '{IF[language=="da-DK"]}' . $prefix($da['subject_prefix']) . $message['subject_da'] . '{ELSE[language]}' . $prefix($en['subject_prefix']) . $message['subject_en'] . '{ENDIF[language]}',
            'sender_name' => $message['sender'], 'sender_email' => $this->config['sender_email'],
            'content' => ['type' => 'html/text', 'html' => Message::html($message),
                'text' => Message::text($message)],
            'receivers' => ['filter' => (string) $filterId],
            'settings' => ['editor' => 'advanced', 'unsubscribe_form_id' => (string) $this->config['unsubscribe_form_id'],
                'category_id' => (string) $category]];
        $result = $this->request($mailingId ? 'PUT' : 'POST', '/mailings' . ($mailingId ? '/' . $mailingId : ''), $body);
        if (!$mailingId && empty($result['id'])) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return $mailingId ?: (int) $result['id'];
    }

    public function preview(int $mailingId, string $email): void
    {
        $this->request('POST', '/mailings/' . $mailingId . '/sendpreview', ['receivers' => [$email], 'previewText' => ' - TEST']);
    }

    public function finished(int $mailingId): bool
    {
        $mailing = $this->request('GET', '/mailings/' . $mailingId);
        return is_array($mailing) && !empty($mailing['finished']) && !empty($mailing['started']);
    }

    public function release(int $mailingId, int $timestamp): void
    {
        // Live release is intentionally gated until the one-recipient acceptance check.
        if (empty($this->config['release_verified'])) {
            throw new \RuntimeException('COM_INTERCOM_RELEASE_NOT_VERIFIED');
        }
        $this->request('POST', '/mailings/' . $mailingId . '/release', ['time' => $timestamp ?: time() + 30]);
    }
}

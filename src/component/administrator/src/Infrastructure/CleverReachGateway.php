<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\FilterCreator;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\UnsubscribeForm;

final class CleverReachGateway implements DeliveryGateway, FilterCreator, \FKT\Component\Intercom\Administrator\Domain\ReconciliationGateway
{
    public function __construct(private \Closure $token, private array $config, private ?\Closure $transport = null)
    {
    }

    private function request(string $method, string $path, ?array $data = null, string $base = '/v3'): mixed
    {
        $token = ($this->token)();
        if ($this->transport !== null) {
            return ($this->transport)($method, $base . $path, $data);
        }
        $curl = curl_init('https://rest.cleverreach.com' . $base . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $method === 'GET' ? 10 : 25, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($error || $status < 200 || $status >= 300) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR', $status === 404 ? 404 : 0);
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

    public function unsubscribeForms(int $groupId): array
    {
        // Flows use a separate base URL; the v3 forms catalogue is deprecated.
        $flows = $this->request('GET', '/flow?playbook=unsubscribe&order=name&dir=asc', null, '/flow');
        if (!is_array($flows) || !array_is_list($flows)) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return array_values(array_filter($flows, static fn ($flow): bool => is_array($flow)
            && is_string($flow['name'] ?? null) && UnsubscribeForm::belongsTo($flow, $groupId)));
    }

    public function assertUnsubscribeForm(string $value, int $groupId): void
    {
        $id = UnsubscribeForm::identifier($value);
        if (!$id || $groupId < 1) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_UNSUBSCRIBE', 422);
        }
        if (UnsubscribeForm::isFlow($id)) {
            $flow = $this->request('GET', '/flow/' . $id, null, '/flow');
            if (!is_array($flow) || ($flow['id'] ?? '') !== $id || !UnsubscribeForm::belongsTo($flow, $groupId)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_UNSUBSCRIBE', 422);
            }
            return;
        }
        // Read old list forms only to preserve and validate existing installations.
        $forms = $this->request('GET', '/groups/' . $groupId . '/forms');
        if (is_array($forms) && array_is_list($forms)) {
            foreach ($forms as $form) {
                if (
                    is_array($form) && (string) ($form['id'] ?? '') === $id
                    && (string) ($form['customer_tables_id'] ?? '') === (string) $groupId
                ) {
                    return;
                }
            }
        }
        throw new \RuntimeException('COM_INTERCOM_INVALID_UNSUBSCRIBE', 422);
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
        $this->assertUnsubscribeForm((string) ($this->config['unsubscribe_form_id'] ?? ''), (int) ($this->config['group_id'] ?? 0));
        $rules = self::filterRules($message);
        $this->request(
            'PUT',
            '/groups/' . (int) $this->config['group_id'] . '/filters/' . $filterId,
            ['rules' => $rules]
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

    private function assertMailingForm(int $mailingId): void
    {
        $mailing = $this->request('GET', '/mailings/' . $mailingId);
        if (!is_array($mailing) || (string) ($mailing['unsubscribe_form_id'] ?? '') !== (string) $this->config['unsubscribe_form_id']) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_UNSUBSCRIBE', 422);
        }
    }

    public function preview(int $mailingId, string $email): void
    {
        // The workflow persists the remote ID before this check, so a failed
        // read-back cannot lose track of a newly created mailing.
        $this->assertMailingForm($mailingId);
        $this->request('POST', '/mailings/' . $mailingId . '/sendpreview', ['receivers' => [$email], 'previewText' => ' - TEST']);
    }

    public function mailing(int $id): array
    {
        $result = $this->request('GET', '/mailings/' . $id);
        if (!is_array($result) || array_is_list($result)) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return $result;
    }

    public function filter(int $groupId, int $id): array
    {
        $result = $this->request('GET', '/groups/' . $groupId . '/filters/' . $id);
        if (!is_array($result) || array_is_list($result)) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return $result;
    }

    public function filters(int $groupId): array
    {
        $result = $this->request('GET', '/groups/' . $groupId . '/filters');
        if (!is_array($result) || !array_is_list($result)) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        foreach ($result as $filter) {
            if (!is_array($filter) || !isset($filter['id'], $filter['name']) || !is_string($filter['name']) || !ctype_digit((string) $filter['id'])) {
                throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
            }
        }
        return $result;
    }

    public function finished(int $mailingId): bool
    {
        $mailing = $this->request('GET', '/mailings/' . $mailingId);
        return is_array($mailing) && \FKT\Component\Intercom\Administrator\Domain\MailingStatus::status($mailing, $mailingId, (int) ($this->config['group_id'] ?? 0)) === 'completed';
    }

    public function release(int $mailingId, int $timestamp): void
    {
        // Live release is intentionally gated until the one-recipient acceptance check.
        if (empty($this->config['release_verified'])) {
            throw new \RuntimeException('COM_INTERCOM_RELEASE_NOT_VERIFIED');
        }
        $this->assertUnsubscribeForm((string) ($this->config['unsubscribe_form_id'] ?? ''), (int) ($this->config['group_id'] ?? 0));
        $this->assertMailingForm($mailingId);
        $this->request('POST', '/mailings/' . $mailingId . '/release', ['time' => $timestamp ?: time() + 30]);
    }
}

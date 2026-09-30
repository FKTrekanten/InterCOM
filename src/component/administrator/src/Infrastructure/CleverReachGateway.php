<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\FilterCreator;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\UnsubscribeForm;

final class CleverReachGateway implements \FKT\Component\Intercom\Administrator\Domain\ReleaseGateway, DeliveryGateway, FilterCreator, \FKT\Component\Intercom\Administrator\Domain\AudienceGateway, \FKT\Component\Intercom\Administrator\Domain\ReconciliationGateway
{
    public function __construct(private \Closure $token, private array $config, private ?\Closure $transport = null, private ?\Closure $authorizeRelease = null)
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
        if (isset($message['acceptance_email'])) {
            $add('email', 'EQ', $message['acceptance_email']);
        }
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
            $add('birthdate', 'BG', $today->modify('-' . ($message['age_to'] + 1) . ' years')->format('Y-m-d'));
        }
        if ($message['gender']) {
            $add('gender', 'EQ', $message['gender']);
        }
        $add('suppression', 'NOCONTAINS', $message['definition']['suppression'] ?? $message['type']);
        return $rules;
    }

    public function updateAudience(int $filterId, array $rules): void
    {
        $this->request('PUT', '/groups/' . (int) $this->config['group_id'] . '/filters/' . $filterId, ['rules' => $rules]);
    }

    public function assertAudience(int $filterId, array $rules): void
    {
        $filter = $this->filter((int) $this->config['group_id'], $filterId);
        $aliases = [];
        if (array_filter($filter['rules'] ?? [], static fn ($r) => preg_match('/^a[0-9]+\.value$/D', $r['field'] ?? ''))) {
            foreach ([$this->request('GET', '/attributes'), $this->request('GET', '/groups/' . (int) $this->config['group_id'] . '/attributes')] as $attributes) {
                if (!is_array($attributes) || !array_is_list($attributes)) {
                    throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
                }
                foreach ($attributes as $attribute) {
                    if (ctype_digit((string) ($attribute['id'] ?? '')) && is_string($attribute['name'] ?? null)) {
                        $aliases['a' . $attribute['id'] . '.value'] = $attribute['name'];
                    }
                }
            }
        }
        $normalise = static function (array $rules) use ($aliases): array {
            return array_map(static fn (array $r): array => ['operator' => strtoupper((string) ($r['operator'] ?? '')) ?: 'AND', 'field' => (string) ($aliases[$r['field'] ?? ''] ?? $r['field'] ?? ''), 'logic' => strtoupper((string) ($r['logic'] ?? '')), 'condition' => (string) ($r['condition'] ?? '')], $rules);
        };
        if ((string) ($filter['id'] ?? '') !== (string) $filterId || !is_array($filter['rules'] ?? null) || $normalise($filter['rules']) !== $normalise($rules)) {
            throw new \RuntimeException('COM_INTERCOM_AUDIENCE_CHANGED', 409);
        }
    }

    public function statistics(int $filterId): int
    {
        return \FKT\Component\Intercom\Administrator\Domain\RecipientCount::active($this->request('GET', '/groups/' . (int) $this->config['group_id'] . '/filters/' . $filterId . '/stats'));
    }

    public function prepare(array $message, int $filterId, int $mailingId): int
    {
        $this->assertUnsubscribeForm((string) ($this->config['unsubscribe_form_id'] ?? ''), (int) ($this->config['group_id'] ?? 0));
        $rules = $message['audience_rules'] ?? self::filterRules($message);
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
            'content' => ['type' => 'html/text', 'html' => $message['prepared_html'] ?? Message::html($message),
                'text' => $message['prepared_text'] ?? Message::text($message)],
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

    public function assertOneRecipient(int $filterId, string $approved): void
    {
        if (!filter_var($approved, FILTER_VALIDATE_EMAIL) || $this->statistics($filterId) !== 1) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_RECIPIENT', 409);
        }
        $rows = $this->request('GET', '/groups/' . (int) $this->config['group_id'] . '/filters/' . $filterId . '/receivers?type=active&pagesize=2&page=0');
        if (
            !is_array($rows) || !array_is_list($rows) || count($rows) !== 1 || !is_array($rows[0])
            || !is_string($rows[0]['email'] ?? null) || !hash_equals(strtolower($approved), strtolower($rows[0]['email']))
            || (!is_int($rows[0]['group_id'] ?? null) && !is_string($rows[0]['group_id'] ?? null)) || (string) ($rows[0]['group_id'] ?? '') !== (string) $this->config['group_id']
            || (!is_int($rows[0]['activated'] ?? null) && !is_string($rows[0]['activated'] ?? null)) || !ctype_digit((string) ($rows[0]['activated'] ?? '')) || (int) $rows[0]['activated'] < 1
            || !in_array($rows[0]['deactivated'] ?? null, [0, '0'], true) || !in_array($rows[0]['bounced'] ?? null, [0, '0'], true)
        ) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_RECIPIENT', 409);
        }
        try {
            $this->request('GET', '/blacklist/' . rawurlencode($approved));
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_RECIPIENT', 409);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $blocked = $this->request('GET', '/groups/' . (int) $this->config['group_id'] . '/blacklist');
        if (!is_array($blocked) || !array_is_list($blocked)) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        foreach ($blocked as $entry) {
            $email = is_string($entry) ? $entry : (is_array($entry) ? ($entry['email'] ?? null) : null);
            if (!is_string($email) || strtolower($email) === strtolower($approved)) {
                throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_RECIPIENT', 409);
            }
        }
    }

    public function preflightRelease(int $mailingId): void
    {
        if ($this->authorizeRelease === null) {
            throw new \RuntimeException('COM_INTERCOM_RELEASE_NOT_VERIFIED', 409);
        }
        ($this->authorizeRelease)($mailingId);
        $this->assertUnsubscribeForm((string) ($this->config['unsubscribe_form_id'] ?? ''), (int) ($this->config['group_id'] ?? 0));
        $this->assertMailingForm($mailingId);
        $mailing = $this->mailing($mailingId);
        if (
            (string) ($mailing['id'] ?? '') !== (string) $mailingId || !filter_var($this->config['sender_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || ($mailing['sender_email'] ?? '') !== $this->config['sender_email'] || ($mailing['sender_name'] ?? '') !== ($this->config['sender_name'] ?? '') || ($mailing['is_mailing'] ?? null) !== true
            || ($mailing['is_campaign'] ?? null) !== false || ($mailing['is_dynamic'] ?? null) !== false
            || array_map('strval', $mailing['mailing_groups']['group_ids'] ?? []) !== [(string) $this->config['group_id']]
        ) {
            throw new \RuntimeException('COM_INTERCOM_ACCEPTANCE_MAILING', 409);
        }
    }

    public function release(int $mailingId, int $timestamp): void
    {
        try {
            $this->preflightRelease($mailingId);
        } catch (\Throwable $e) {
            throw new \FKT\Component\Intercom\Administrator\Domain\ReleaseBlocked(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_PROVIDER_ERROR', in_array($e->getCode(), [403, 409, 422], true) ? $e->getCode() : 409);
        }
        $this->request('POST', '/mailings/' . $mailingId . '/release', ['time' => $timestamp ?: time() + 30]);
    }
}

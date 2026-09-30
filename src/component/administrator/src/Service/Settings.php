<?php

namespace FKT\Component\Intercom\Administrator\Service;

final class Settings
{
    public function __construct(private Runtime $runtime)
    {
    }
    public function save(array $values, int $actor): array
    {
        $r = $this->runtime;
        $input = new \Joomla\Input\Input($values);
        $rules = json_decode($r->config['audience_rules'] ?? '[]', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_RULES');
        }
        foreach ($rules as $rule) {
            if (
                !is_array($rule) || !is_int($rule['group'] ?? null) || $rule['group'] < 1
                || !is_bool($rule['all'] ?? false) || !is_array($rule['tags'] ?? [])
            ) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_RULES');
            }
            foreach ($rule['tags'] ?? [] as $tag) {
                if (!is_string($tag) || !str_starts_with($tag, 'group.') || str_contains($tag, ',')) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_RULES');
                }
            }
        }
        $sender = $input->get('sender_name', $r->config['sender_name'] ?? 'Trekanten Fencing', 'raw');
        if (!is_string($sender)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        $config = ['mode' => $input->getCmd('mode') === 'live' ? 'live' : 'fake',
            'retention_days' => max(1, min(3650, $input->getInt('retention_days', 30))),
            'group_id' => $input->getInt('group_id'), 'unsubscribe_form_id' => \FKT\Component\Intercom\Administrator\Domain\UnsubscribeForm::identifier($input->get('unsubscribe_form_id', '', 'raw')),
            'sender_name' => trim($sender),
            'sender_email' => $input->getString('sender_email'), 'audience_rules' => json_encode($rules),
            'release_verified' => false, 'acceptance_recipient' => strtolower(trim($input->getString('acceptance_recipient', $r->config['acceptance_recipient'] ?? ''))), 'board_archive_email' => trim($input->getString('board_archive_email')),
            'communication_catalog_version' => (int) ($r->config['communication_catalog_version'] ?? 1),
            'estimate_cache_minutes' => max(1, min(60, $input->getInt('estimate_cache_minutes', (int) ($r->config['estimate_cache_minutes'] ?? 5)))),
            'audience_lease_minutes' => max(5, min(240, $input->getInt('audience_lease_minutes', (int) ($r->config['audience_lease_minutes'] ?? 30)))),
            'max_filters' => max(1, min(20, $input->getInt('max_filters', 5)))];
        if ($config['sender_name'] === '' || strlen($config['sender_name']) > 255 || preg_match('/[\x00-\x1f{}<>]/', $config['sender_name'])) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        if (
            $config['mode'] === 'live' && (!$config['group_id'] || !$config['unsubscribe_form_id']
            || !filter_var($config['sender_email'], FILTER_VALIDATE_EMAIL))
        ) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        if ($config['acceptance_recipient'] !== '' && !filter_var($config['acceptance_recipient'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        if ($config['board_archive_email'] && !filter_var($config['board_archive_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        $config = array_merge($config, \FKT\Component\Intercom\Administrator\Domain\Footer::validate(array_merge($r->config, $values)));
        $r->store->transaction(function () use ($r, &$config, $actor): void {
            $r->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $row = $r->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
            $current = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            // These settings have their own editors. An Options save must not overwrite
            // audience changes made after this request's Runtime was constructed.
            $config['audience_rules'] = $current['audience_rules'] ?? '[]';
            $config['communication_catalog_version'] = (int) ($current['communication_catalog_version'] ?? 1);
            // Do not switch accounts, modes or recipient lists while any filter is reserved.
            $reserved = $r->store->hasLiveReservations();
            foreach (['mode', 'group_id'] as $key) {
                if ($reserved && ($current[$key] ?? ($key === 'mode' ? 'fake' : 0)) != $config[$key]) {
                    throw new \RuntimeException('COM_INTERCOM_LIVE_RESERVATIONS');
                }
            }
            if (
                $config['mode'] === 'live' && (($current['mode'] ?? 'fake') !== 'live'
                || (string) ($current['unsubscribe_form_id'] ?? '') !== $config['unsubscribe_form_id']
                || (int) ($current['group_id'] ?? 0) !== $config['group_id'])
            ) {
                // Verify changed selections before persisting; unrelated edits can still
                // preserve an existing selection when the provider is temporarily offline.
                (new \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway(fn () => $r->connection->token(), $config))
                    ->assertUnsubscribeForm($config['unsubscribe_form_id'], $config['group_id']);
            }
            if (($current['mode'] ?? 'fake') !== $config['mode']) {
                // Simulated mailings have no external recipient filter to protect.
                $r->store->execute("UPDATE #__intercom_filters f JOIN #__intercom_drafts d ON d.id=f.draft_id SET f.draft_id=NULL WHERE d.delivery_mode='fake'");
                $r->store->execute("UPDATE #__intercom_drafts SET filter_id=NULL,tested_revision=NULL,state='cancelled' WHERE delivery_mode='fake' AND state IN ('draft','tested','testing')");
                $r->store->audit($actor, 'simulation.reservations_cleared');
            }
            $json = $r->store->q(json_encode($config, JSON_THROW_ON_ERROR));
            $r->store->execute("UPDATE #__extensions SET params=$json WHERE element='com_intercom' AND type='component'");
            // Historical reservations remain auditable; unused manual filters are obsolete.
            $r->store->execute('DELETE FROM #__intercom_filters WHERE draft_id IS NULL AND managed=0');
            $r->store->audit(
                $actor,
                'configuration.saved',
                0,
                ['before' => array_diff_key($current, array_flip(['client_id', 'client_secret', 'access_token', 'refresh_token', 'acceptance_recipient'])), 'configuration' => array_diff_key($config, ['acceptance_recipient' => true]), 'acceptance_recipient_hash' => hash('sha256', $config['acceptance_recipient'])]
            );
        });
        return $config;
    }
}

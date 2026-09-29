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
        $rules = json_decode($input->get('audience_rules', '[]', 'raw'), true, 32, JSON_THROW_ON_ERROR);
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
        $filters = array_values(array_unique(array_filter(array_map('intval', explode(',', $input->getString('filter_ids'))), fn ($v) => $v > 0)));
        $config = ['mode' => $input->getCmd('mode') === 'live' ? 'live' : 'fake',
            'retention_days' => max(1, min(3650, $input->getInt('retention_days', 30))),
            'group_id' => $input->getInt('group_id'), 'unsubscribe_form_id' => $input->getInt('unsubscribe_form_id'),
            'sender_email' => $input->getString('sender_email'), 'audience_rules' => json_encode($rules),
            'release_verified' => $input->getBool('release_verified'), 'categories' => [], 'filter_ids' => implode(',', $filters),
            'max_filters' => max(1, min(20, $input->getInt('max_filters', 5)))];
        if (
            $config['mode'] === 'live' && (!$config['group_id'] || !$config['unsubscribe_form_id']
            || !filter_var($config['sender_email'], FILTER_VALIDATE_EMAIL))
        ) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
        }
        foreach (\FKT\Component\Intercom\Administrator\Domain\Policy::TYPES as $type) {
            $config['categories'][$type] = $input->getInt('category_' . $type, 0);
            $config['category_' . $type] = $config['categories'][$type];
        }
        $r->store->transaction(function () use ($r, $config, $filters, $actor): void {
            $r->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            // Do not switch accounts, modes or recipient lists while any filter is reserved.
            $reserved = $r->store->row('SELECT f.filter_id FROM #__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id WHERE f.draft_id IS NOT NULL AND (d.delivery_mode IS NULL OR d.delivery_mode != \'fake\') LIMIT 1 FOR UPDATE');
            foreach (['mode', 'group_id'] as $key) {
                if ($reserved && ($r->config[$key] ?? ($key === 'mode' ? 'fake' : 0)) != $config[$key]) {
                    throw new \RuntimeException('COM_INTERCOM_LIVE_RESERVATIONS');
                }
            }
            if (($r->config['mode'] ?? 'fake') !== $config['mode']) {
                // Simulated mailings have no external recipient filter to protect.
                $r->store->execute("UPDATE #__intercom_filters f JOIN #__intercom_drafts d ON d.id=f.draft_id SET f.draft_id=NULL WHERE d.delivery_mode='fake'");
                $r->store->execute("UPDATE #__intercom_drafts SET filter_id=NULL,tested_revision=NULL,state='cancelled' WHERE delivery_mode='fake' AND state IN ('draft','tested','testing')");
                $r->store->audit($actor, 'simulation.reservations_cleared');
                // A new provider configuration must supply its own filter IDs.
                $r->store->execute('DELETE FROM #__intercom_filters WHERE draft_id IS NULL AND managed=0');
            }
            $json = $r->store->q(json_encode($config, JSON_THROW_ON_ERROR));
            $r->store->execute("UPDATE #__extensions SET params=$json WHERE element='com_intercom' AND type='component'");
            foreach ($filters as $id) {
                $r->store->execute("INSERT IGNORE INTO #__intercom_filters (filter_id,group_id,managed) VALUES ($id,0,0)");
            }
            // Keep historical reservations for audit, but retire unused IDs removed from Options.
            $condition = $filters ? ' AND filter_id NOT IN (' . implode(',', $filters) . ')' : '';
            $r->store->execute('DELETE FROM #__intercom_filters WHERE draft_id IS NULL AND managed=0' . $condition);
            $r->store->audit(
                $actor,
                'configuration.saved',
                0,
                ['configuration' => $config, 'filter_ids' => $filters]
            );
        });
        return $config;
    }
}

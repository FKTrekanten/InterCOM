<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class SettingsMigration
{
    public static function run(Store $store): void
    {
        $store->transaction(function () use ($store): void {
            $store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $row = $store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
            $params = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            $design = $store->row('SELECT configuration FROM #__intercom_design WHERE id=1 FOR UPDATE');
            $settings = json_decode($design['configuration'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            if (!isset($params['sender_name'])) {
                $params['sender_name'] = $settings['sender_en'] ?? $settings['sender_da'] ?? 'Trekanten Fencing';
                $store->execute('UPDATE #__extensions SET params=' . $store->q(json_encode($params, JSON_THROW_ON_ERROR)) . " WHERE element='com_intercom' AND type='component'");
                $store->audit(0, 'configuration.sender_migrated');
            }
            if (isset($settings['sender_da']) || isset($settings['sender_en'])) {
                unset($settings['sender_da'], $settings['sender_en']);
                $store->execute('UPDATE #__intercom_design SET revision=revision+1,configuration=' . $store->q(json_encode($settings, JSON_THROW_ON_ERROR)) . ' WHERE id=1');
            }
        });
    }
}

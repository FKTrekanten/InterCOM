<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Table\CommunicationTable;
use Joomla\CMS\Access\Rules;

final class CatalogMigration
{
    public static function run(Store $store): void
    {
        $settings = $store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        $params = json_decode($settings['params'] ?? '{}', true) ?: [];
        if (($params['communication_catalog_version'] ?? 0) >= 1) {
            return;
        }
        $defaults = [
            'club' => ['Klubinformation', 'Club information', 'Vigtig information om medlemskab og klubben.', 'Important membership and club information.'],
            'class' => ['Holdnyheder', 'Team news', 'Nyheder og ændringer for dit hold.', 'News and updates for your team.'],
            'license' => ['Licensinformation', 'License information', 'Information om fægtelicenser.', 'Information about fencing licenses.'],
            'newsletter' => ['Nyhedsbrev', 'Newsletter', 'Nyheder om klubbens aktiviteter.', 'News about club activities.'],
            'offer' => ['Tilbud og kampagner', 'Offers and campaigns', 'Tilbud og invitationer fra klubben.', 'Offers and invitations from the club.'],
        ];
        $asset = $store->row("SELECT * FROM #__assets WHERE name='com_intercom'");
        $legacy = [];
        while ($asset) {
            $rules = json_decode($asset['rules'] ?? '{}', true) ?: [];
            foreach (array_keys($defaults) as $key) {
                foreach ($rules['intercom.' . $key] ?? [] as $group => $value) {
                    if ($value !== '' && $value !== null) {
                        $legacy[$key][$group] = isset($legacy[$key][$group]) ? min($legacy[$key][$group], (int) $value) : (int) $value;
                    }
                }
            }
            $asset = (int) ($asset['parent_id'] ?? 0) > 0 ? $store->row('SELECT * FROM #__assets WHERE id=' . (int) $asset['parent_id']) : null;
        }
        $store->transaction(function () use ($store, $params, $defaults, $legacy): void {
            foreach ($defaults as $key => $copy) {
                $qkey = $store->q($key);
                $existing = $store->row("SELECT * FROM #__intercom_types WHERE type_key=$qkey");
                if ($existing && (int) $existing['asset_id'] > 0) {
                    continue;
                }
                $table = new CommunicationTable($store->db);
                $table->bind($existing ?: ['type_key' => $key, 'suppression' => $key, 'require_group' => (int) ($key === 'class'),
                    'category_id' => (int) ($params['categories'][$key] ?? $params['category_' . $key] ?? 0), 'state' => 1,
                    'ordering' => array_search($key, array_keys($defaults), true) + 1, 'revision' => 1]);
                $typeRules = $legacy[$key] ?? [];
                $table->setRules(new Rules(['intercom.type.compose' => $typeRules, 'intercom.type.send' => $typeRules]));
                $table->store();
                $id = (int) $table->id;
                foreach (['da-DK', 'en-GB'] as $i => $language) {
                    $name = $store->q($copy[$i]);
                    $description = $store->q($copy[$i + 2]);
                    $store->execute("INSERT IGNORE INTO #__intercom_type_translations (type_id,language,name,description,subject_prefix,heading) VALUES ($id," . $store->q($language) . ",$name,$description,$name,$name)");
                }
                $store->audit(0, 'communication.migrated', 0, ['type_id' => $id, 'key' => $key]);
            }
            $store->execute("UPDATE #__intercom_types t SET used=1 WHERE EXISTS (SELECT 1 FROM #__intercom_drafts d WHERE JSON_UNQUOTE(JSON_EXTRACT(d.content,'$.type'))=t.type_key)");
            $params['communication_catalog_version'] = 1;
            unset($params['categories']);
            foreach (array_keys($defaults) as $key) {
                unset($params['category_' . $key]);
            }
            $store->execute('UPDATE #__extensions SET params=' . $store->q(json_encode($params, JSON_THROW_ON_ERROR)) . " WHERE element='com_intercom' AND type='component'");
        });
    }
}

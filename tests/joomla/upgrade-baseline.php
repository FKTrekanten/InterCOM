<?php
require __DIR__ . '/bootstrap.php';
// Before the first public release, use an explicitly synthetic prior schema.
// Existing rows from integration.php must survive Joomla's migration runner.
$id = (int) $db->setQuery("SELECT extension_id FROM #__extensions WHERE element='com_intercom'")->loadResult();
$db->setQuery('ALTER TABLE #__intercom_drafts DROP COLUMN tested_fingerprint, DROP COLUMN tested_revision, DROP COLUMN delivery_mode')->execute();
$db->setQuery('ALTER TABLE #__intercom_filters DROP COLUMN group_id, DROP COLUMN managed, DROP COLUMN reconciliation_status, DROP COLUMN checked_at')->execute();
$db->setQuery('DROP TABLE #__intercom_filter_creations')->execute();
foreach (['types', 'type_translations', 'tags', 'catalogues', 'archives', 'design'] as $table) {
    $db->setQuery('DROP TABLE #__intercom_' . $table)->execute();
}
// Preserve native asset nesting when removing synthetic record assets.
$store = $app->bootComponent('com_intercom')->runtime->store;
foreach ($store->rows("SELECT id FROM #__assets WHERE name LIKE 'com_intercom.communication.%'") as $row) {
    $asset = new \Joomla\CMS\Table\Asset($db);
    $asset->delete((int) $row['id']);
}
$params = json_decode($store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true);
unset($params['communication_catalog_version']);
$params['categories'] = ['club' => 71];
$params['unsubscribe_form_id'] = 432342;
$store->execute('UPDATE #__extensions SET params=' . $store->q(json_encode($params)) . " WHERE element='com_intercom'");
$asset = new \Joomla\CMS\Table\Asset($db);
$asset->loadByName('com_intercom');
$asset->rules = json_encode(['intercom.class' => [2 => 1, 3 => 0], 'intercom.club' => [2 => 0], 'intercom.compose' => [2 => 1]]);
$asset->store();
$db->setQuery("UPDATE #__schemas SET version_id='0.0.0' WHERE extension_id=$id")->execute();
check(true, 'Synthetic 0.0.0 upgrade baseline prepared');

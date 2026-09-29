<?php
require __DIR__ . '/bootstrap.php';
// Before the first public release, use an explicitly synthetic prior schema.
// Existing rows from integration.php must survive Joomla's migration runner.
$id = (int) $db->setQuery("SELECT extension_id FROM #__extensions WHERE element='com_intercom'")->loadResult();
$db->setQuery('ALTER TABLE #__intercom_drafts DROP COLUMN tested_revision, DROP COLUMN delivery_mode')->execute();
$db->setQuery('ALTER TABLE #__intercom_filters DROP COLUMN group_id, DROP COLUMN managed')->execute();
$db->setQuery('DROP TABLE #__intercom_filter_creations')->execute();
$db->setQuery("UPDATE #__schemas SET version_id='0.0.0' WHERE extension_id=$id")->execute();
check(true, 'Synthetic 0.0.0 upgrade baseline prepared');

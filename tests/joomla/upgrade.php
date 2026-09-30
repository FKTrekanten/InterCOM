<?php
require __DIR__ . '/bootstrap.php';
$fixture=json_decode(file_get_contents('/tmp/intercom-upgrade.json'),true);
$r=$app->bootComponent('com_intercom')->runtime;
check((bool)$r->store->row("SELECT id FROM #__intercom_audit WHERE event='upgrade.fixture'"),'Upgrade preserves audit');
check($r->store->draft((int)$fixture['id'],42)['revision']===$fixture['revision'],'Upgrade preserves draft revision');
check($r->store->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope']===$fixture['envelope'],'Upgrade preserves encrypted credentials');
echo "UPGRADE OK\n";

check(array_key_exists("tested_revision", $r->store->row("SELECT * FROM #__intercom_drafts LIMIT 1")), "Native SQL migration restored tested_revision column");
check(array_key_exists('managed', $r->store->row('SELECT * FROM #__intercom_filters LIMIT 1')), 'Native migration adds filter ownership');
check(!$r->store->row('SELECT filter_id FROM #__intercom_filters WHERE managed=0 AND draft_id IS NULL'), 'Upgrade retires unreserved manual filters');
check((bool)$r->store->row("SELECT id FROM #__intercom_audit WHERE event='migration.filters_retired'"), 'Upgrade audits manual filter retirement');
$params = json_decode($r->store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true, 64, JSON_THROW_ON_ERROR);
check(!array_key_exists('filter_ids', $params), 'Upgrade removes obsolete manual filter setting');

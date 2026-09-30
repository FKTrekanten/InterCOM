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

check(array_key_exists('tested_fingerprint', $r->store->row('SELECT * FROM #__intercom_drafts LIMIT 1')), 'Upgrade adds current-definition test fingerprint');
check(count($r->catalog->types()) === 5, 'Upgrade seeds the five communication groups');
check($r->catalog->types()['club']['category_id'] === 71, 'Upgrade preserves category mapping in communication record');
foreach (['intercom.type.compose', 'intercom.type.send'] as $action) {
    $class = $r->catalog->types()['class']['id'];
    check(\Joomla\CMS\Access\Access::checkGroup(2, $action, 'com_intercom.communication.' . $class), 'Upgrade preserves delegated grant: ' . $action);
    check(!\Joomla\CMS\Access\Access::checkGroup(3, $action, 'com_intercom.communication.' . $class), 'Upgrade preserves inherited explicit denial: ' . $action);
    $club = $r->catalog->types()['club']['id'];
    check(!\Joomla\CMS\Access\Access::checkGroup(2, $action, 'com_intercom.communication.' . $club), 'Global compose cannot override denied communication type: ' . $action);
}

check(($r->config['sender_name'] ?? '') === 'Trekanten Fencing', 'Native upgrade initializes single sender in Options');
check(!isset($r->design->snapshot()['settings']['sender_en']), 'Email design excludes sender configuration');
check((bool)$r->store->row("SHOW COLUMNS FROM #__intercom_tags LIKE 'labels'"), 'Native upgrade installs tag labels');

check((int) ($r->config['unsubscribe_form_id'] ?? 0) === 432342, 'Upgrade preserves legacy unsubscribe selection');

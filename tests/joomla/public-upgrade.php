<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Public upgrade fixtures require the disposable CI stack'); }
require __DIR__ . '/bootstrap.php';
$fixture = json_decode(file_get_contents('/tmp/intercom-public-upgrade.json'), true, 64, JSON_THROW_ON_ERROR);
$r = $app->bootComponent('com_intercom')->runtime;
$store = $r->store;
$id = (int)$fixture['draft']['id'];
check($store->draft($id, 42) === $fixture['draft'], 'Public upgrade preserves the complete draft');
check($store->row("SELECT * FROM #__intercom_revisions WHERE draft_id=$id") === $fixture['revision'], 'Public upgrade preserves saved message revisions');
check(json_decode($store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true) === $fixture['params'], 'Public upgrade preserves component settings');
check($store->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope'] === $fixture['envelope'], 'Public upgrade preserves encrypted credentials');
$cipher = new \FKT\Component\Intercom\Administrator\Domain\CredentialCipher($app->get('secret'));
check($cipher->decrypt($fixture['envelope'])['client_secret'] === 'fixture-secret', 'Upgraded credentials remain decryptable');
check($store->row("SELECT * FROM #__intercom_types WHERE type_key='license'") === $fixture['type'], 'Public upgrade preserves customised communication groups');
$assetId = (int)$fixture['type']['asset_id'];
check($store->row("SELECT rules FROM #__assets WHERE id=$assetId")['rules'] === $fixture['rules'], 'Public upgrade preserves delegated permissions');
check((bool)$store->row("SELECT id FROM #__intercom_audit WHERE event='public.upgrade.fixture' AND draft_id=$id"), 'Public upgrade preserves audit history');
check((int)$db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND folder='task' AND element='intercom'")->loadResult() === 0, 'Public upgrade preserves disabled maintenance');
// The administrator can enable the retained plugin and use its scheduler routine.
$db->setQuery("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='task' AND element='intercom'")->execute();
echo "PUBLIC UPGRADE OK\n";

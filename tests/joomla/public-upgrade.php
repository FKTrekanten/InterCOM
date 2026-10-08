<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Public upgrade fixtures require the disposable CI stack'); }
require __DIR__ . '/bootstrap.php';
$fixture = json_decode(file_get_contents('/tmp/intercom-public-upgrade.json'), true, 64, JSON_THROW_ON_ERROR);
$r = $app->bootComponent('com_intercom')->runtime;
$store = $r->store;
$id = (int)$fixture['draft']['id'];
check($store->draft($id, 42) === $fixture['draft'], 'Public upgrade preserves the complete draft');
check($store->row("SELECT * FROM #__intercom_revisions WHERE draft_id=$id") === $fixture['revision'], 'Public upgrade preserves saved message revisions');
$expectedParams = $fixture['params'];
$expectedParams['member_audience_version'] = 1;
$expectedParams['audience_rules'] = json_encode([['group'=>2,'all'=>false,'tags'=>['discipline.epee','group.Youth']]]);
check(json_decode($store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true) === $expectedParams, 'Public upgrade preserves settings and migrates discipline grants');
$legacy = $store->row('SELECT * FROM #__intercom_drafts WHERE id=' . (int)$fixture['legacy_id']);
$audience = json_decode($legacy['content'],true);
check($audience['tags']===['group.Youth'] && $audience['disciplines']===['discipline.epee'] && $audience['age_from']===8, 'Native public upgrade preserves and migrates the saved audience');
check($legacy['state']==='draft' && $legacy['tested_revision']===null && $legacy['audience_fingerprint']===null && $legacy['estimate_count']===null, 'Native public upgrade requires a fresh audience test');
$tag = $store->row("SELECT * FROM #__intercom_tags WHERE list_id=0 AND tag='discipline.epee'");
check((int)$tag['enabled']===1 && (int)$tag['ordering']===17 && json_decode($tag['labels'],true)['da-DK']==='Kårde', 'Native public upgrade preserves discipline visibility, ordering and labels');
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

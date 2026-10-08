<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Public upgrade fixtures require the disposable CI stack'); }
require __DIR__ . '/bootstrap.php';
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE element='com_intercom'")->loadResult() === 0, 'Public upgrade starts from a fresh Joomla installation');
$temporary = tempnam('/tmp', 'intercom-public-') . '.zip';
copy($argv[1], $temporary);
$archive = \Joomla\CMS\Installer\InstallerHelper::unpack($temporary, true);
check(is_array($archive) && !empty($archive['dir']), 'Unpack verified public package');
check(\Joomla\CMS\Installer\Installer::getInstance()->install($archive['dir']), 'Install published 0.3.11 through the native installer');
$app->createExtensionNamespaceMap();
$manifest = json_decode($db->setQuery("SELECT manifest_cache FROM #__extensions WHERE element='pkg_intercom'")->loadResult(), true);
check($manifest['version'] === '0.3.11', 'Upgrade baseline is the actual published version');
$r = $app->bootComponent('com_intercom')->runtime;
$store = $r->store;
$params = json_decode($store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true);
$params['audience_rules'] = json_encode([['group'=>2,'all'=>false,'tags'=>['group.epee','group.Youth']]]);
$params = array_merge($params, ['mode' => 'fake', 'sender_name' => 'Upgrade fixture', 'retention_days' => 45]);
$store->execute('UPDATE #__extensions SET params=' . $store->q(json_encode($params)) . " WHERE element='com_intercom'");
$cipher = new \FKT\Component\Intercom\Administrator\Domain\CredentialCipher($app->get('secret'));
$envelope = $cipher->encrypt(['client_id' => 'public-upgrade-fixture', 'client_secret' => 'fixture-secret']);
$store->execute('UPDATE #__intercom_connections SET envelope=' . $store->q($envelope) . " WHERE provider='cleverreach'");
$policy = new \FKT\Component\Intercom\Administrator\Domain\Policy(['access' => true, 'compose' => true, 'license' => true], ['all' => true]);
$workflow = new \FKT\Component\Intercom\Administrator\Service\Workflow($store, new \FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway(), $policy, 42);
$draft = $workflow->save(['type' => 'license', 'sender' => 'Upgrade fixture', 'subject_da' => 'Dansk', 'subject_en' => 'English', 'body_da' => 'Bevar kladden', 'body_en' => 'Preserve this draft', 'tags' => []]);
$id = (int)$draft['id'];
$store->execute("UPDATE #__intercom_types SET category_id=12345 WHERE type_key='license'");
$type = $store->row("SELECT * FROM #__intercom_types WHERE type_key='license'");
$assetId = (int)$type['asset_id'];
$rules = json_encode(['intercom.type.compose' => [2 => 1, 3 => 0], 'intercom.type.send' => [2 => 0]]);
$store->execute('UPDATE #__assets SET rules=' . $store->q($rules) . " WHERE id=$assetId");
$store->audit(42, 'public.upgrade.fixture', $id);
$store->execute("UPDATE #__extensions SET enabled=0 WHERE type='plugin' AND folder='task' AND element='intercom'");
$legacyDraft = $workflow->save(['type'=>'license','sender'=>'Upgrade fixture','subject_da'=>'DA','subject_en'=>'EN','body_da'=>'Dansk','body_en'=>'English','tags'=>['group.epee','group.Youth'],'age_from'=>8,'age_to'=>12]);
$legacyId = (int)$legacyDraft['id'];
$store->execute("UPDATE #__intercom_drafts SET state='tested',tested_revision=revision,tested_fingerprint='old',audience_fingerprint='old',estimate_count=34 WHERE id=$legacyId");
$store->execute("INSERT INTO #__intercom_tags (list_id,tag,enabled,available,ordering,labels) VALUES (0,'group.epee',1,1,17,'{\"da-DK\":\"Kårde\",\"en-GB\":\"Epee choice\"}')");
file_put_contents('/tmp/intercom-public-upgrade.json', json_encode(['legacy_id'=>$legacyId,'draft' => $draft, 'params' => $params, 'envelope' => $envelope,
    'type' => $type, 'rules' => $rules, 'revision' => $store->row("SELECT * FROM #__intercom_revisions WHERE draft_id=$id")]));
echo "PUBLIC UPGRADE BASELINE OK\n";

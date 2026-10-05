<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Requires disposable CI'); }
require __DIR__ . '/bootstrap.php';
$r=$app->bootComponent('com_intercom')->runtime;
check(($r->connection->credentials()['client_secret']??'')===getenv('INTERCOM_TEST_CLIENT_SECRET'),'HTTP credential save preserves special characters');
check((int)($r->config['retention_days']??0)===45,'Successful native settings preserved after rejected save');
check((bool)$r->store->row("SELECT id FROM #__intercom_audit WHERE event='configuration.saved'"),'HTTP configuration change audited');

// Identity/network behavior is injected explicitly in native service tests; HTTP
// smoke uses invalid-input checks so automatic CI never contacts CleverReach.
$connection=new \FKT\Component\Intercom\Administrator\Service\Connection($r->store,new \FKT\Component\Intercom\Administrator\Domain\CredentialCipher($app->get('secret')),new \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity(static fn($token)=>['id'=>'231113']));
$connection->importTokens(getenv('INTERCOM_TEST_ACCESS_TOKEN'),'',3600,42);
check($r->connection->token()===getenv('INTERCOM_TEST_ACCESS_TOKEN'),'Access-only token usable without OAuth refresh');
check(empty($r->connection->credentials()['refresh_token']),'No old refresh token retained');
check(($r->config['mode']??'')==='fake','Token import preserves simulation mode');
check($r->catalog->types()['club']['category_id'] === 71, 'Options preserves migrated communication category');
check(!isset($r->config['categories']), 'Categories are no longer stored in Options');
$stored=$r->store->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope'];
$params=$r->store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'];
$audit=json_encode($r->store->rows('SELECT context FROM #__intercom_audit'));
foreach ([getenv('INTERCOM_TEST_ACCESS_TOKEN'),getenv('INTERCOM_TEST_CLIENT_SECRET')] as $secret) {
    check(!str_contains($stored,$secret) && !str_contains($params,$secret) && !str_contains($audit,$secret),'Secrets encrypted and absent from settings/audit');
}
check((bool)$r->store->row("SELECT id FROM #__intercom_audit WHERE event='connection.tokens_imported'"),'Token import audited');
check((bool)$r->store->row("SELECT id FROM #__intercom_audit WHERE event='configuration.failed'"),'Rejected configuration save audited');

check(!$r->store->row('SELECT filter_id FROM #__intercom_filters WHERE filter_id=100'),'Saving Options retires unused legacy filter');
check(!array_key_exists('filter_ids', json_decode($params, true, 64, JSON_THROW_ON_ERROR)), 'Options no longer persists manual filter IDs');

check(($r->config['sender_name'] ?? '')==='CI shared sender', 'Options persists the single sender name');

check(($r->config['unsubscribe_form_id'] ?? '') === '432342', 'Native Options stores legacy unsubscribe ID as a lossless string');

check(($r->config['footer_address'] ?? '') === "HTTP Club address\nSecond address line", 'Native Options normalises browser textarea CRLF');
$httpGroup = $r->catalog->types()['http_group'];
$httpAsset = $r->store->row('SELECT rules FROM #__assets WHERE name='.$r->store->q('com_intercom.communication.'.$httpGroup['id']));
$httpRules = json_decode($httpAsset['rules'],true);
check(!isset($httpRules['intercom.type.compose'][1]) && \Joomla\CMS\Access\Access::checkGroup(2,'intercom.type.compose','com_intercom.communication.'.$httpGroup['id'])===true,'Actual HTTP form saves inheritance without an accidental Public denial');
check($httpGroup['revision']===1 && !$r->store->row("SELECT id FROM #__assets WHERE name LIKE 'com_intercom.settings.%'"),'HTTP permission preview does not save revisions or create settings assets');

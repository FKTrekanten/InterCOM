<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Requires disposable CI'); }
require __DIR__ . '/bootstrap.php';
$r=$app->bootComponent('com_intercom')->runtime;
check(($r->connection->credentials()['client_secret']??'')===getenv('INTERCOM_TEST_CLIENT_SECRET'),'HTTP credential save preserves special characters');
check((int)($r->config['retention_days']??0)===45,'HTTP settings persisted with credentials');
check((bool)$r->store->row("SELECT id FROM #__intercom_audit WHERE event='configuration.saved'"),'HTTP configuration change audited');

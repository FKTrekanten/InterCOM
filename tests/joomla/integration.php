<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Integration fixtures require the disposable CI stack'); }
require __DIR__ . '/bootstrap.php';
$r=$app->bootComponent('com_intercom')->runtime;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway;
$store=$r->store;
// Tests run on isolated CI database. No calls to a real provider.
$store->execute('DELETE FROM #__intercom_filters');
$store->execute('DELETE FROM #__intercom_drafts');
$store->execute('DELETE FROM #__intercom_revisions');
$store->execute('DELETE FROM #__intercom_audit');
$store->execute('INSERT INTO #__intercom_filters (filter_id) VALUES (100),(9001),(9002),(9003)');
$store->execute("UPDATE #__extensions SET params='{" . '"mode":"fake","filter_ids":"9001,9002,9003"' . "}' WHERE element='com_intercom' AND type='component'");
$policy=new Policy(['access'=>true,'compose'=>true,'send'=>true,'class'=>true],['all'=>false,'tags'=>['group.Youth']]);
$workflow=new Workflow($store,new FakeGateway(),$policy,42);
$message=['type'=>'class','sender'=>'Club','subject_da'=>'Hej','subject_en'=>'Hello','body_da'=>'Dansk','body_en'=>'English','tags'=>['group.Youth']];
$r->connection->save(['client_id'=>'fixture-id','client_secret'=>'fixture-secret'],42);
check($r->connection->credentials()['client_secret']==='fixture-secret','Joomla-secret credential roundtrip');
$draft=$workflow->save($message);$id=(int)$draft['id'];
try {$workflow->release($id,1,0);throw new Exception('Expected rejection');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_CONFLICT','Release requires successful current test');}
$tested=$workflow->preview($id,1,'one@example.invalid');
check($tested['state']==='tested','Preview accepted');
check((int)$tested['filter_id']===9001,'Unlisted old pool ID cannot be reserved');
$second=$workflow->save($message);$second=$workflow->preview((int)$second['id'],1,'one@example.invalid');
check($tested['filter_id']!==$second['filter_id'],'Separate drafts reserve distinct filters');
try {$workflow->save($message,$id,0);throw new Exception('Expected conflict');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_CONFLICT','Stale editor revision rejected');}
$changed=$workflow->save($message,$id,1);check($changed['tested_revision']===null,'Editing invalidates test');
try {$store->draft($id,99);throw new Exception('Expected denial');} catch(RuntimeException $e){check($e->getCode()===404,'Another owner cannot access draft');}
$workflow->preview($id,2,'one@example.invalid');$sent=$workflow->release($id,2,0);
check($sent['state']==='submitted','Release state recorded');
try {$workflow->release($id,2,0);throw new Exception('Expected conflict');} catch(RuntimeException $e){check($e->getCode()===409,'Duplicate release blocked');}
check((int)$store->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id='.(int)$sent['filter_id'])['draft_id']===$id,'Submitted filter not reused');
check(!str_contains($store->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope'],'fixture-secret'),'No plaintext secret in storage');
check(!str_contains(json_encode($store->rows('SELECT * FROM #__intercom_audit')),'fixture-secret'),'No secret in audit');
$store->execute("INSERT INTO #__intercom_audit(actor_id,event,draft_id,context,created_at) VALUES (0,'expired',0,'{}',UTC_TIMESTAMP()-INTERVAL 31 DAY)");
$workflow->maintain(30);
check(!$store->row("SELECT id FROM #__intercom_audit WHERE event='expired'"),'Retention removes expired audit');
check((bool)$store->row("SELECT id FROM #__intercom_audit WHERE event='release.accepted'"),'Recent audit preserved');
check($sent['delivery_mode']==='fake','Simulation mode recorded on draft');
$r->connection->save(['client_id'=>'real-configuration'],42);
check($r->connection->credentials()['client_id']==='real-configuration','Simulation leases do not block credentials');
$access=bin2hex(random_bytes(32)); $refresh=bin2hex(random_bytes(32));
$r->connection->importTokens($access,$refresh,3600,42);
check($r->connection->credentials()['refresh_token']===$refresh,'Manual refresh token encrypted roundtrip');
$r->connection->importTokens($access,'',3600,42);
check($r->connection->token()===$access && $r->connection->credentials()['refresh_token']==='','Access-only import clears previous refresh token');
try {$r->connection->importTokens($access,'',0,42);throw new Exception('Expected invalid lifetime');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_INVALID_TOKENS','Invalid token lifetime rejected');}
check($r->connection->token()===$access,'Invalid import preserves existing token');
$expired=$r->connection->credentials(); $expired['expires_at']=time()-1;
$cipher=new \FKT\Component\Intercom\Administrator\Domain\CredentialCipher($app->get('secret'));
$store->execute("UPDATE #__intercom_connections SET envelope=".$store->q($cipher->encrypt($expired))." WHERE provider='cleverreach'");
try {$r->connection->token();throw new Exception('Expected expired token');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_TOKEN_EXPIRED','Expired access-only token fails without network calls');}
$store->execute("UPDATE #__intercom_drafts SET delivery_mode='live' WHERE id=$id");
try {$r->connection->importTokens($access,'',3600,42);throw new Exception('Expected live reservation denial');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_LIVE_RESERVATIONS','Token import protects live reservations');}

try {$r->connection->save(['client_id'=>'other-account'],42);throw new Exception('Expected denial');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_LIVE_RESERVATIONS','Account changes blocked while filters are reserved');}
try {$workflow->preview($id,2,'one@example.invalid');throw new Exception('Expected mode rejection');} catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_MODE_CHANGED','Drafts cannot be reused across delivery modes');}
// Retained upgrade fixture.
$store->audit(42,'upgrade.fixture', $id);
file_put_contents('/tmp/intercom-upgrade.json',json_encode(['id'=>$id,'revision'=>$sent['revision'],'envelope'=>$store->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope']]));
echo "INTEGRATION OK\n";

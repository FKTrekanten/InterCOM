<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Connection fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity;
use FKT\Component\Intercom\Administrator\Service\Connection;
$r=$app->bootComponent('com_intercom')->runtime;
$s=$r->store;
$cipher=new CredentialCipher($app->get('secret'));
$answers=['old'=>['id'=>'231113'],'new'=>['id'=>'231113'],'other'=>['id'=>'999'],'bad'=>[], 'expired'=>null];
$lookup=new CleverReachIdentity(static function ($token) use (&$answers) {
    if (!isset($answers[$token])) throw new RuntimeException('COM_INTERCOM_ACCOUNT_UNAVAILABLE');
    return $answers[$token];
});
$c=new Connection($s,$cipher,$lookup);
$s->begin();
try {
    $s->execute("UPDATE #__intercom_connections SET account_id='',envelope=".$s->q($cipher->encrypt(['access_token'=>'old','refresh_token'=>'old-refresh','expires_at'=>time()+300,'client_id'=>'app','client_secret'=>'secret']))." WHERE provider='cleverreach'");
    $s->execute("INSERT INTO #__intercom_drafts(owner_id,content,delivery_mode,state,filter_id,mailing_id,created_at,updated_at) VALUES(42,'{}','live','uncertain',3999999010,123,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $id=(int)$s->db->insertid();
    $s->execute("INSERT INTO #__intercom_filters(filter_id,draft_id,group_id,managed) VALUES(3999999010,$id,758666,1)");
    $c->importTokens('new','',3600,42);
    check($c->accountId()==='231113','Legacy connection independently pinned with previous valid token');
    check($c->credentials()['refresh_token']==='','Replacement never retains previous refresh token');
    check((int)$s->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id=3999999010')['draft_id']===$id,'Renewal preserves uncertain live reservation');
    $before=$s->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope'];
    foreach (['other'=>'COM_INTERCOM_ACCOUNT_MISMATCH','bad'=>'COM_INTERCOM_ACCOUNT_UNAVAILABLE','expired'=>'COM_INTERCOM_ACCOUNT_UNAVAILABLE'] as $candidate=>$error) {
        try {$c->importTokens($candidate,'',3600,42);throw new Exception('Expected identity rejection');}
        catch(RuntimeException $e) {check($e->getMessage()===$error,'Reject candidate identity: '.$candidate);}
        check($s->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope']===$before,'Rejected import preserves encrypted credentials');
    }
    $s->execute("UPDATE #__intercom_connections SET envelope=".$s->q($cipher->encrypt(['access_token'=>'expired','expires_at'=>time()-1,'client_id'=>'app','client_secret'=>'secret']))." WHERE provider='cleverreach'");
    $c->importTokens('new','',3600,42);
    check($c->token()==='new','Pinned account permits renewal with expired previous token');
    $s->execute("UPDATE #__intercom_drafts SET state='submitted' WHERE id=$id");
    $c->importTokens('new','new-refresh',3600,42);
    check($c->credentials()['refresh_token']==='new-refresh','Submitted reservation allows verified renewal');
    $s->execute("INSERT INTO #__intercom_filter_creations(group_id,remote_name,state,created_at) VALUES(758666,'identity-test','uncertain',UTC_TIMESTAMP())");
    $c->importTokens('new','',3600,42);
    check($c->token()==='new','Uncertain filter creation allows same-account renewal');
    try {$c->save(['client_id'=>'changed'],42);throw new Exception('Expected app credential guard');}
    catch(RuntimeException $e) {check($e->getMessage()==='COM_INTERCOM_LIVE_RESERVATIONS','Changing application credentials still blocked');}
    $c->save(['client_id'=>'app','client_secret'=>'secret'],42);
    check($c->token()==='new','No-op credential save preserves token with reservations');
    $s->execute("UPDATE #__intercom_connections SET account_id='',envelope=".$s->q($cipher->encrypt(['access_token'=>'expired','expires_at'=>time()-1,'client_id'=>'app','client_secret'=>'secret']))." WHERE provider='cleverreach'");
    try {$c->importTokens('new','',3600,42);throw new Exception('Expected missing legacy proof');}
    catch(RuntimeException $e) {check($e->getMessage()==='COM_INTERCOM_ACCOUNT_UNPINNED','Cannot infer unpinned legacy account from replacement token');}
    check($c->accountId()==='' && $c->credentials()['access_token']==='expired','Unverifiable legacy credentials preserved');
    $s->execute("UPDATE #__intercom_connections SET envelope=".$s->q($cipher->encrypt(['access_token'=>'old','expires_at'=>time()+300]))." WHERE provider='cleverreach'");
    check($c->pin(42)==='231113','Explicit verification pins current legacy identity without replacement');
} finally { $s->rollback(); }
// Independent transaction for OAuth and refresh grants: no external calls in CI.
$s->begin();
try {
    $s->execute("UPDATE #__intercom_connections SET account_id='231113',envelope=".$s->q($cipher->encrypt(['access_token'=>'expired','refresh_token'=>'old-refresh','expires_at'=>time()-1,'client_id'=>'app','client_secret'=>'secret']))." WHERE provider='cleverreach'");
    $exchange=new Connection($s,$cipher,$lookup,static fn($form,$credentials)=>array_merge($credentials,['access_token'=>'new','refresh_token'=>'new-refresh','expires_at'=>time()+300]));
    $exchange->authorize('code','https://example.invalid/callback',42);
    check($exchange->token()==='new','OAuth authorization verifies replacement identity');
    $v=$exchange->credentials();$v['expires_at']=time()-1;
    $s->execute("UPDATE #__intercom_connections SET envelope=".$s->q($cipher->encrypt($v))." WHERE provider='cleverreach'");
    check($exchange->token()==='new','Automatic refresh verifies account identity');
    $badExchange=new Connection($s,$cipher,$lookup,static fn($form,$credentials)=>array_merge($credentials,['access_token'=>'other','expires_at'=>time()+300]));
    // Refresh must never switch accounts even with no live reservations.
    $v=$exchange->credentials();$v['expires_at']=time()-1;
    $s->execute("UPDATE #__intercom_connections SET envelope=".$s->q($cipher->encrypt($v))." WHERE provider='cleverreach'");
    try {$badExchange->token();throw new Exception('Expected refresh rejection');}
    catch(RuntimeException $e) {check($e->getMessage()==='COM_INTERCOM_ACCOUNT_MISMATCH','Refresh cannot change pinned account');}
    $s->execute('UPDATE #__intercom_filters SET draft_id=NULL WHERE group_id!=0');
    $s->execute("DELETE FROM #__intercom_filter_creations WHERE state IN ('pending','uncertain')");
    $s->execute('INSERT INTO #__intercom_filters(filter_id,group_id,managed) VALUES(3999999011,758666,1)');
    $exchange->importTokens('other','',3600,42);
    check($exchange->accountId()==='999' && !$s->row('SELECT filter_id FROM #__intercom_filters WHERE filter_id=3999999011'),'Verified account switch without leases retires old pool');
    $raw=$s->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope'];
    check(!str_contains($raw,'new-refresh'),'Replacement credentials remain encrypted');
    $events=json_encode($s->rows("SELECT context FROM #__intercom_audit WHERE event LIKE 'connection.%'"));
    check(!str_contains($events,'new-refresh') && !str_contains($events,'old-refresh'),'Identity audit contains no token material');
} finally { $s->rollback(); }
echo "CONNECTION OK\n";

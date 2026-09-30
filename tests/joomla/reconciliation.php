<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Reconciliation fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Service\Reconciliation;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;

$r = $app->bootComponent('com_intercom')->runtime;
$store = $r->store;
$store->begin();
try {
    $config = ['mode'=>'live','group_id'=>710001,'max_filters'=>1];
    $store->execute('UPDATE #__extensions SET params='.$store->q(json_encode($config))." WHERE element='com_intercom' AND type='component'");
    $calls=[];
    $catalogueCalls=0;
    $gateway = new CleverReachGateway(fn()=>'stub-no-network', $config, function($method,$path,$data) use (&$calls,&$catalogueCalls) {
        check($method==='GET' && $data===null,'Reconciliation performs read-only provider calls');
        $calls[]=$path;
        if ($path==='/v3/groups/710001/filters') {
            $catalogueCalls++;
            return [['id'=>'710020','name'=>'Intercom-recover'],['id'=>'710021','name'=>'Intercom-duplicate'],['id'=>'710022','name'=>'Intercom-duplicate'],['id'=>'710023','name'=>'Intercom-stale']];
        }
        if (preg_match('~/filters/(\d+)$~',$path,$m)) {
            if ((int)$m[1]===710008) { throw new RuntimeException('COM_INTERCOM_PROVIDER_ERROR',404); }
            return ['id'=>$m[1],'name'=>'Intercom '.$m[1]];
        }
        if (preg_match('~/mailings/(\d+)$~',$path,$m)) {
            $id=(int)$m[1];
            if ($id===710004) { throw new RuntimeException('COM_INTERCOM_PROVIDER_ERROR',404); }
            if ($id===710005) { throw new RuntimeException('COM_INTERCOM_PROVIDER_ERROR'); }
            $mailing=['id'=>$m[1],'started'=>100,'finished'=>120,'is_mailing'=>true,'is_campaign'=>false,'is_dynamic'=>false,'mailing_groups'=>['group_ids'=>['710001']]];
            if ($id===710003) { $mailing['finished']=0; }
            if ($id===710006) { $mailing['is_campaign']=true; }
            if ($id===710007) { $mailing['mailing_groups']['group_ids']=['710002']; }
            return $mailing;
        }
        throw new RuntimeException('Unexpected stub request');
    });
    $ids=[];
    foreach ([1=>'submitted',2=>'uncertain',3=>'scheduled',4=>'uncertain',5=>'submitted',6=>'scheduled',7=>'submitted',8=>'submitted',9=>'tested',10=>'deleted',11=>'cancelled',12=>'uncertain'] as $suffix=>$state) {
        $filter=710000+$suffix;
        $mailing=$suffix===12?0:$filter;
        $store->execute("INSERT INTO #__intercom_drafts(owner_id,delivery_mode,content,state,filter_id,mailing_id,created_at,updated_at) VALUES (42,'live','{}','$state',$filter,$mailing,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $id=(int)$store->db->insertid();$ids[$suffix]=$id;
        $store->execute("INSERT INTO #__intercom_filters(filter_id,draft_id,group_id,managed) VALUES ($filter,$id,710001,1)");
    }
    foreach (['recover','missing','duplicate','stale','recent'] as $name) {
        $state=in_array($name,['stale','recent'],true)?'pending':'uncertain';
        $created=$name==='stale'?'UTC_TIMESTAMP()-INTERVAL 16 MINUTE':'UTC_TIMESTAMP()';
        $store->execute("INSERT INTO #__intercom_filter_creations(group_id,remote_name,state,created_at) VALUES (710001,'Intercom-$name','$state',$created)");
    }
    $service=new Reconciliation($store,$gateway,$config);
    $result=$service->run(42);
    check($result['released']===4 && $result['adopted']===2,'Only confirmed static completions and unique creations reconcile');
    foreach ([1,2,10,11] as $suffix) {
        check($store->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id='.(710000+$suffix))['draft_id']===null,'Confirmed completed filter freed: '.$suffix);
        $state=$store->draft($ids[$suffix],42,false,true)['state'];
        check($state===([10=>'deleted',11=>'cancelled'][$suffix]??'completed'),'Preserve hidden deleted/cancelled history: '.$suffix);
    }
    foreach ([3=>'waiting',4=>'missing',5=>'provider_error',6=>'unknown',7=>'mismatch',8=>'missing',12=>'unknown'] as $suffix=>$status) {
        $row=$store->row('SELECT * FROM #__intercom_filters WHERE filter_id='.(710000+$suffix));
        check((int)$row['draft_id']===$ids[$suffix] && $row['reconciliation_status']===$status,'Unsafe result retains reservation: '.$status);
        check(!empty($row['checked_at']),'Provider check recorded');
    }
    check(!in_array('/v3/mailings/710009',$calls,true),'Unsent tested draft is not reconciled');
    check($store->row("SELECT state FROM #__intercom_filter_creations WHERE remote_name='Intercom-recent'")['state']==='pending','Active creation left alone');
    check($store->row("SELECT state FROM #__intercom_filter_creations WHERE remote_name='Intercom-missing'")['state']==='uncertain','Absent filter never frees creation slot');
    check($store->row("SELECT reconciliation_status FROM #__intercom_filter_creations WHERE remote_name='Intercom-duplicate'")['reconciliation_status']==='ambiguous','Duplicate names remain blocked');
    $before=(int)$store->row("SELECT COUNT(*) n FROM #__intercom_audit WHERE event='mailing.completed'")['n'];
    $repeat=$service->run(42);
    check($repeat['released']===0 && $repeat['adopted']===0,'Repeated reconciliation is idempotent');
    check((int)$store->row("SELECT COUNT(*) n FROM #__intercom_audit WHERE event='mailing.completed'")['n']===$before,'No duplicate completion audit');
    $errorGateway=new CleverReachGateway(fn()=>'stub', $config, static function() { throw new RuntimeException('COM_INTERCOM_PROVIDER_ERROR'); });
    (new Reconciliation($store,$errorGateway,$config))->run(42);
    check($store->row("SELECT reconciliation_status FROM #__intercom_filter_creations WHERE remote_name='Intercom-missing'")['reconciliation_status']==='provider_error','Creation provider errors retain cap slot');
    $store->execute("UPDATE #__intercom_connections SET account_id='231113' WHERE provider='cleverreach'");
    $otherConnection=new \FKT\Component\Intercom\Administrator\Service\Connection($store,new \FKT\Component\Intercom\Administrator\Domain\CredentialCipher($app->get('secret')),new \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity(static fn($token)=>['id'=>'999']));
    try { $otherConnection->importTokens('new-stub-token','',3600,42); throw new Exception('Expected reservation guard'); }
    catch (RuntimeException $e) { check($e->getMessage()==='COM_INTERCOM_ACCOUNT_MISMATCH','Unresolved leases/creations prevent account changes'); }
    $liveStub=new class implements DeliveryGateway {
        public function mode(): string { return 'live'; }
        public function tags(string $origin): array { return $origin==='group'?['group.Youth']:[]; }
        public function prepare(array $message,int $filterId,int $mailingId): int { return 719999; }
        public function preview(int $mailingId,string $email): void {}
        public function release(int $mailingId,int $timestamp): void {}
    };
    $workflow=new Workflow($store,$liveStub,new Policy(['access'=>true,'compose'=>true,'send'=>true,'class'=>true],['all'=>true]),42);
    $draft=$workflow->save(['type'=>'class','sender'=>'Club','subject_da'=>'DA','subject_en'=>'EN','body_da'=>'Dansk','body_en'=>'English','tags'=>['group.Youth']]);
    $tested=$workflow->preview((int)$draft['id'],1,'fixture@example.invalid');
    check((int)$tested['filter_id']===710001,'Reused verified free filter instead of exceeding cap');
    $service->run(42);
    check((int)$store->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id=710001')['draft_id']===(int)$draft['id'],'New owner cannot be released by old mailing completion');
    $store->execute("UPDATE #__extensions SET params='{}' WHERE element='com_intercom' AND type='component'");
    try { $service->run(42); throw new Exception('Expected configuration guard'); }
    catch (RuntimeException $e) { check($e->getCode()===409,'Stale list configuration rejected before provider reads'); }
} finally { $store->rollback(); }
echo "RECONCILIATION OK\n";

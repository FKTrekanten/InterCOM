<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Pool cleanup checks require disposable CI'); }
require __DIR__ . '/bootstrap.php';

use FKT\Component\Intercom\Administrator\Domain\PoolCleanupGateway;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Service\PoolCleanup;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Service\Mailbox;

$r=$app->bootComponent('com_intercom')->runtime;$s=$r->store;
$s->begin();
try {
    $config=['mode'=>'live','group_id'=>880001,'max_filters'=>1];
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($config))." WHERE element='com_intercom' AND type='component'");
    $s->execute("UPDATE #__intercom_connections SET account_id='cleanup-fixture' WHERE provider='cleverreach'");
    $gateway=new class implements PoolCleanupGateway {
        public string $identity='cleanup-fixture';
        public array $present=[],$mailings=[];
        public bool $failure=false,$filterExists=false;
        public function account():string{return $this->identity;}
        public function filters(int $group):array{if($this->failure)throw new RuntimeException('Synthetic unavailable catalogue');return array_map(static fn($id)=>['id'=>(string)$id],$this->present);}
        public function assertFilterAbsent(int $group,int $filter):void{if($this->filterExists || in_array($filter,$this->present,true))throw new RuntimeException('COM_INTERCOM_CLEANUP_EXISTS',409);}
        public function assertMailingAbsent(int $mailing):void{if(in_array($mailing,$this->mailings,true))throw new RuntimeException('COM_INTERCOM_CLEANUP_EXISTS',409);}
    };
    $service=new PoolCleanup($s,$gateway,$config);
    $throws=static function(callable $call,string $message):void {
        try{$call();}catch(RuntimeException $error){check(true,$message);return;}
        throw new RuntimeException('Expected rejection: '.$message);
    };
    $ids=[];
    foreach([1=>'uncertain',2=>'submitted',3=>'submitted',4=>'completed'] as $suffix=>$state){
        $filter=880100+$suffix;$mailing=$suffix===1?0:880200+$suffix;
        $s->execute("INSERT INTO #__intercom_drafts(owner_id,delivery_mode,content,state,filter_id,mailing_id,mailing_attempted,created_at,updated_at) VALUES(4242,'live','{}','$state',$filter,$mailing,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $id=(int)$s->db->insertid();$ids[$suffix]=$id;
        $claim=$suffix===4?'NULL':$id;
        $s->execute("INSERT INTO #__intercom_filters(filter_id,draft_id,group_id,managed) VALUES($filter,$claim,880001,1)");
        $s->execute("INSERT INTO #__intercom_filter_creations(group_id,remote_name,state,filter_id,created_at) VALUES(880001,'cleanup-$suffix','created',$filter,UTC_TIMESTAMP())");
        if($suffix>1){
            $s->execute("INSERT INTO #__intercom_history(draft_id,revision,actor_id,delivery_mode,account_id,group_id,type_key,state,filter_id,mailing_id,snapshot,requested_at,started_at,finished_at,created_at) VALUES($id,1,4242,'live','cleanup-fixture',880001,'club','$state',$filter,$mailing,'{}',UTC_TIMESTAMP(),".($suffix===4?'UTC_TIMESTAMP(),UTC_TIMESTAMP()':'NULL,NULL').",UTC_TIMESTAMP())");
        }
    }
    $s->execute("INSERT INTO #__intercom_archives(draft_id,address,payload,state,due_at,updated_at) VALUES({$ids[2]},'fixture@example.invalid','{}','pending',0,UTC_TIMESTAMP())");
    $completed=$s->row("SELECT * FROM #__intercom_drafts WHERE id={$ids[4]}");
    $completedHistory=$s->row("SELECT * FROM #__intercom_history WHERE draft_id={$ids[4]}");
    $preview=$service->preview();
    check(count($preview['filters'])===4,'Preview includes obsolete reservations and missing formerly free filters');
    $throws(fn()=>$service->apply($preview['fingerprint'],false,'Fixture cleanup',436),'Cleanup requires permanent-removal confirmation');
    $gateway->identity='wrong-account';$throws(fn()=>$service->preview(),'Wrong provider account blocks cleanup');$gateway->identity='cleanup-fixture';
    $s->execute("UPDATE #__extensions SET params='{}' WHERE element='com_intercom' AND type='component'");
    $throws(fn()=>$service->preview(),'Changed account/list settings block cleanup');
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($config))." WHERE element='com_intercom' AND type='component'");
    $gateway->failure=true;$throws(fn()=>$service->apply($preview['fingerprint'],true,'Fixture cleanup',436),'Failed catalogue access cannot retire anything');$gateway->failure=false;
    $gateway->mailings=[880202];$throws(fn()=>$service->preview(),'A known mailing still present blocks cleanup');$gateway->mailings=[];
    $gateway->filterExists=true;$throws(fn()=>$service->preview(),'Individual filter lookup must also establish absence');$gateway->filterExists=false;
    $gateway->present=[880102];$throws(fn()=>$service->apply($preview['fingerprint'],true,'Fixture cleanup',436),'A filter reappearing after preview invalidates cleanup');$gateway->present=[];
    $s->execute("UPDATE #__intercom_drafts SET lease_generation=lease_generation+1 WHERE id={$ids[1]}");
    $throws(fn()=>$service->apply($preview['fingerprint'],true,'Fixture cleanup',436),'A changed reservation generation invalidates the reviewed snapshot');
    foreach(['testing','estimating','releasing','scheduled','abandoning'] as $active){
        $s->execute("UPDATE #__intercom_drafts SET state='$active' WHERE id={$ids[1]}");
        $throws(fn()=>$service->preview(),"Active $active operation cannot be cleaned up");
    }
    $s->execute("UPDATE #__intercom_drafts SET state='uncertain' WHERE id={$ids[1]}");
    $s->execute("UPDATE #__intercom_filter_creations SET state='pending' WHERE filter_id=880101");
    $throws(fn()=>$service->preview(),'An in-flight creation cannot be retired');
    $s->execute("UPDATE #__intercom_filter_creations SET state='created' WHERE filter_id=880101");
    check((int)$s->row('SELECT COUNT(*) n FROM #__intercom_filters WHERE group_id=880001 AND managed=1')['n']===4,'Every blocked attempt leaves all filters managed');
    $preview=$service->preview();
    $archiveSends=0;
    $archive=new \FKT\Component\Intercom\Administrator\Service\Archive($s,'fixture@example.invalid',static fn()=>true,
        function()use($service,$preview,$s,$ids,&$archiveSends,$throws):bool{
            check($s->row("SELECT state FROM #__intercom_archives WHERE draft_id={$ids[2]}")['state']==='sending','Archive has claimed its send before cleanup runs');
            $throws(fn()=>$service->apply($preview['fingerprint'],true,'Already-claimed archive fixture',436),'An already-claimed archive blocks cleanup');
            check((int)$s->row('SELECT COUNT(*) n FROM #__intercom_filters WHERE group_id=880001 AND managed=1')['n']===4,'Archive race rejection leaves all reservations intact');
            $archiveSends++;return true;
        });
    $s->execute("UPDATE #__intercom_archives SET payload=".$s->q(json_encode(['mailing_id'=>880202]))." WHERE draft_id={$ids[2]}");
    $archive->maintain();
    check($archiveSends===1,'Existing archive worker completes before any cleanup can succeed');
    $throws(fn()=>$service->apply($preview['fingerprint'],true,'Changed archive fixture',436),'Archive completion invalidates the old preview');
    $s->execute("UPDATE #__intercom_archives SET state='pending' WHERE draft_id={$ids[2]}");
    $preview=$service->preview();
    check($service->apply($preview['fingerprint'],true,'Manually removed obsolete provider records',436)===4,'Explicit cleanup retires all reviewed deleted filters');
    check($service->apply($preview['fingerprint'],true,'Manually removed obsolete provider records',436)===0,'Repeated submission is idempotent');
    check($s->row("SELECT state,filter_id,mailing_id FROM #__intercom_drafts WHERE id={$ids[1]}")===['state'=>'cancelled','filter_id'=>null,'mailing_id'=>0],'Failed obsolete draft is cancelled and detached');
    foreach([2,3] as $suffix){
        $id=$ids[$suffix];$d=$s->row("SELECT * FROM #__intercom_drafts WHERE id=$id");$h=$s->row("SELECT * FROM #__intercom_history WHERE draft_id=$id");
        check($d['state']==='closed_unverified' && $d['filter_id']===null && (int)$d['mailing_id']===0,'Submitted obsolete message is closed without retry');
        check($h['state']==='closed_unverified' && (int)$h['filter_id']===880100+$suffix && (int)$h['mailing_id']===880200+$suffix && $h['requested_at']!==null && $h['finished_at']===null,'History preserves provider IDs and submission evidence without inventing delivery');
    }
    check($s->row("SELECT * FROM #__intercom_drafts WHERE id={$ids[4]}")===$completed && $s->row("SELECT * FROM #__intercom_history WHERE draft_id={$ids[4]}")===$completedHistory,'Successfully completed message and history stay byte-for-byte unchanged');
    check($s->row("SELECT state FROM #__intercom_archives WHERE draft_id={$ids[2]}")['state']==='uncertain','Pending archive cannot be sent after cleanup');
    check((new Mailbox($s,4242))->page('closed','',1,'closed_unverified')['total']===2,'Closed unverified emails appear in the closed mailbox');
    check((int)$s->row("SELECT COUNT(*) n FROM #__intercom_filter_creations WHERE group_id=880001 AND state!='retired'")['n']===0,'Retired creations free pool capacity');
    // Exercise actual allocation, not just a count: no email/provider write is made.
    $creates=0;
    $allocation=new CleverReachGateway(fn()=>'fixture-no-network',$config,static function($method,$path,$data)use(&$creates){
        check($method==='POST' && str_contains($path,'/filters'),'The next allocation only creates a fresh filter');$creates++;return ['id'=>'880199'];
    });
    $workflow=new Workflow($s,$allocation,new Policy([],[]),436,null,null,null,$config);
    (new ReflectionMethod(Workflow::class,'ensureCapacity'))->invoke($workflow);
    check($creates===1 && (int)$s->row('SELECT managed FROM #__intercom_filters WHERE filter_id=880199')['managed']===1,'Next allocation creates a new filter rather than reusing a deleted ID');
    check($service->apply($preview['fingerprint'],true,'Manually removed obsolete provider records',436)===0 && (int)$s->row('SELECT managed FROM #__intercom_filters WHERE filter_id=880199')['managed']===1,'A replay cannot retire a newly allocated filter');
}finally{$s->rollback();}
echo "POOL CLEANUP OK\n";

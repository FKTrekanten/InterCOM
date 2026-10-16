<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Recovery checks require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Domain\AudienceGateway;
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\PreparationGateway;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Service\TestDelivery;
use FKT\Component\Intercom\Administrator\Service\TestOperations;
use FKT\Component\Intercom\Administrator\Service\TestRecovery;
use FKT\Component\Intercom\Administrator\Service\Mailbox;

final class RecoveryTestGateway implements AudienceGateway, DeliveryGateway, PreparationGateway {
    public string $failure='';
    public bool $failCount=false;
    public array $tagCalls=[];
    public int $prepares=0,$releases=0;
    public function mode():string{return 'live';}
    public function tags(string $origin):array{
        $this->tagCalls[]=$origin;
        if($this->failure==='tags')throw new RuntimeException('Synthetic lookup failure');
        return ['group.Youth'];
    }
    public function updateAudience(int $id,array $rules):void{}
    public function assertAudience(int $id,array $rules):void{}
    public function statistics(int $id):int{if($this->failCount)throw new RuntimeException('Synthetic count failure');return 39;}
    public function prepare(array $m,int $filter,int $mailing):int {return $this->prepareWithProgress($m,$filter,$mailing,static function(){});}
    public function prepareWithProgress(array $m,int $filter,int $mailing,callable $progress):int {
        $this->prepares++;
        foreach(['provider_checks','filter_write','filter_confirmed','mailing_write','mailing_confirmed'] as $stage){
            $progress($stage,$stage==='mailing_confirmed'?845678:0);
            if($this->failure===$stage)throw new RuntimeException('Synthetic boundary failure');
        }
        return 845678;
    }
    public function preview(int $mailing,string $email):void{throw new RuntimeException('Native test transport expected');}
    public function release(int $mailing,int $timestamp):void{$this->releases++;}
}
$r=$app->bootComponent('com_intercom')->runtime;$s=$r->store;
$gateway=new RecoveryTestGateway();
$policy=new Policy(['access'=>true,'compose'=>true,'offer'=>true,'send'=>false],['all'=>true,'tags'=>[]]);
$accepted=[];$failLanguage='';
$delivery=new TestDelivery(static function($address,$payload)use(&$accepted,&$failLanguage){
    if($payload['language']===$failLanguage) return false;
    $accepted[]=$payload['language']; return true;
});
$w=new Workflow($s,$gateway,$policy,4242,null,null,null,null,$delivery);
$message=['type'=>'offer','sender'=>'Club','subject_da'=>'Aldersfilter','subject_en'=>'Age filter','body_da'=>'<p>Dansk</p>','body_en'=>'<p>English</p>','format'=>'html','tags'=>[],'disciplines'=>[],'memberships'=>[],'age_from'=>6,'age_to'=>10];
$throws=static function(callable $call){try{$call();}catch(RuntimeException $e){return $e;}throw new RuntimeException('Expected failure');};
$s->begin();
try{
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode(['mode'=>'live','group_id'=>787877]))." WHERE element='com_intercom'");
    for($i=0;$i<35;$i++)$s->execute('INSERT INTO #__intercom_filters(filter_id,group_id,managed) VALUES('.(3977000000+$i).',787877,1)');
    $age=$w->save($message);$id=(int)$age['id'];
    $age=$w->preview($id,1,'composer@example.invalid');
    check($age['state']==='tested' && $gateway->tagCalls===[] && $accepted===['da','en'],'Age-only workflow needs no optional tag lookups and accepts two tests');
    $status=$w->status($id);
    check($status['test_operation']['outcome']==='accepted' && (int)$status['test_operation']['accepted_da']===1 && (int)$status['test_operation']['accepted_en']===1,'Both language outcomes persist for reload');

    $gateway->failCount=true;
    $throws(fn()=>$w->preview($id,1,'composer@example.invalid'));
    $countFailure=$w->status($id);
    check($countFailure['state']==='draft' && $countFailure['tested_revision']===null && $countFailure['status_detail']==='STATUS_COUNT_FAILED' && $countFailure['test_operation']['stage']==='recipients','Pre-test count failure persists after reload and invalidates old approval without claiming another test');
    $gateway->failCount=false;$w->preview($id,1,'composer@example.invalid');
    $tagged=$w->save(array_merge($message,['tags'=>['group.Youth']]));$tid=(int)$tagged['id'];
    $gateway->failure='tags';$before=$gateway->prepares;
    $throws(fn()=>$w->preview($tid,1,'composer@example.invalid'));
    $tagged=$w->status($tid);
    check($tagged['state']==='draft' && (int)$tagged['mailing_attempted']===0 && $gateway->prepares===$before,'Tag lookup failure stays editable and cannot reach preparation');
    check(!$s->row("SELECT draft_id FROM #__intercom_history WHERE draft_id=$tid") && $tagged['status_detail']==='STATUS_PREPARATION_FAILED','Pre-history failure survives reload with an accurate explanation');
    foreach(['provider_checks'=>'draft','filter_write'=>'uncertain','filter_confirmed'=>'draft','mailing_write'=>'uncertain','mailing_confirmed'=>'draft'] as $stage=>$expected){
        $gateway->failure=$stage;$d=$w->save($message);$did=(int)$d['id'];
        $throws(fn()=>$w->preview($did,1,'composer@example.invalid'));
        $d=$w->status($did);
        check($d['state']===$expected,"$stage preserves the correct safe/uncertain boundary");
        if($stage==='mailing_confirmed')check((int)$d['mailing_id']===845678,'Known mailing ID is durable before subsequent failure');
        if($expected==='uncertain'){
            $before=$gateway->prepares;
            $throws(fn()=>$w->preview($did,1,'composer@example.invalid'));
            check($gateway->prepares===$before,'An uncertain write cannot be blindly repeated');
        }
    }
    $gateway->failure='';$failLanguage='en';$accepted=[];
    $partial=$w->save($message);$pid=(int)$partial['id'];
    $throws(fn()=>$w->preview($pid,1,'composer@example.invalid'));
    $partial=$w->status($pid);
    check($partial['state']==='draft' && (int)$partial['mailing_id']===845678 && $partial['tested_revision']===null && (int)$partial['test_operation']['accepted_da']===1 && (int)$partial['test_operation']['accepted_en']===0,'Partial bilingual SMTP acceptance is durable without granting send approval');
    check($partial['status_detail']==='STATUS_TEST_TRANSPORT' && $gateway->releases===0,'SMTP failure explains test-only retry without member delivery');

    // Simulate interruption at a known safe point and a lost remote write.
    $ops=new TestOperations($s);
    foreach(['validation'=>'draft','mailing_write'=>'uncertain'] as $stage=>$expected){
        $d=$w->save($message);$did=(int)$d['id'];
        $s->execute("UPDATE #__intercom_drafts SET state='testing',lease_generation=1,mailing_attempted=1 WHERE id=$did");
        $claim=$s->draft($did,4242);$claim['mailing_attempted']=0;
        $token=$ops->claim($claim,787877);$ops->progress($did,$token,$stage);
        $ops->interrupted($did);
        check($s->draft($did,4242)['state']===$expected,"Interrupted $stage uses durable stage evidence");
        $throws(fn()=>$ops->progress($did,$token,'mailing_confirmed',999999));
        check((int)$s->draft($did,4242)['mailing_id']===0,'A late callback cannot change a finished or recovered operation');
    }

    $failLanguage='';$copy=$w->copy($id,str_repeat('a',32));$cid=(int)$copy['id'];
    check($cid!==$id && $copy['state']==='draft' && (int)$copy['copy_source_id']===$id && !$copy['filter_id'] && (int)$copy['mailing_id']===0 && !$copy['tested_revision'] && !$copy['estimate_count'],'Copy is an independent untested draft without remote IDs or estimates');
    check(json_decode($copy['content'],true)['age_from']===6 && $w->copy($id,str_repeat('a',32))['id']===$copy['id'],'Copy preserves audience restrictions and deduplicates repeated requests');
    $restricted=new Workflow($s,$gateway,new Policy(['access'=>true,'compose'=>true,'offer'=>false],['all'=>true]),4242);
    check($throws(fn()=>$restricted->copy($id,str_repeat('d',32)))->getMessage()==='COM_INTERCOM_COPY_TARGETING_REVIEW','Copying cannot bypass changed type permissions or silently broaden targeting');
    $other=new Workflow($s,$gateway,$policy,4243);
    $throws(fn()=>$other->copy($id,str_repeat('b',32)));
    $s->execute("UPDATE #__intercom_drafts SET state='submitted' WHERE id=$id");
    $s->execute("UPDATE #__intercom_drafts SET state='scheduled',send_at=2000000000 WHERE id=$cid");
    for($i=0;$i<12;$i++)$w->save($message);
    $mailbox=new Mailbox($s,4242);
    $draftRows=$mailbox->page('drafts');$sentRows=$mailbox->page('sent');$reviewRows=$mailbox->page('attention');
    check(!array_intersect([$id,$cid],array_map('intval',array_column($draftRows['rows'],'id'))) && count($sentRows['rows'])===2,'Submitted and scheduled mailings leave Drafts and appear under Sent / Scheduled');
    check(count($reviewRows['rows'])>=2 && (new Mailbox($s,4243))->page('sent')['total']===0,'Uncertain operations stay visible and mailbox records remain owner-scoped');
    check($mailbox->page('sent','Age filter')['total']===2 && $mailbox->page('sent','',1,'scheduled')['total']===1,'Mailbox supports subject search and precise status filtering');
    check(count($mailbox->page('drafts','',1)['rows'])===10 && $mailbox->page('drafts','',2)['page']===2,'Mailbox pagination exposes more than the first page');

    // Synthetic equivalent of #10; no production IDs or content enter CI.
    $legacy=$w->save($message);$lid=(int)$legacy['id'];
    $filter=(int)$s->row('SELECT filter_id FROM #__intercom_filters WHERE group_id=787877 AND draft_id IS NULL ORDER BY filter_id LIMIT 1')['filter_id'];
    $s->execute("UPDATE #__intercom_filters SET draft_id=$lid WHERE filter_id=$filter");
    $s->execute("UPDATE #__intercom_drafts SET state='uncertain',filter_id=$filter,mailing_attempted=1,lease_generation=1 WHERE id=$lid");
    $s->audit(4242,'preview.intent',$lid,['revision'=>1]);$s->audit(4242,'preview.failed',$lid);
    $s->execute("UPDATE #__intercom_audit SET created_at=".$s->q($legacy['updated_at'])." WHERE draft_id=$lid");
    $recovery=new TestRecovery($s);
    check($recovery->eligible($s->draft($lid,4242)),'Complete legacy pre-history evidence qualifies for explicit administrator assessment');
    $throws(fn()=>$recovery->recover($lid,4242,1,false));
    $recovery->recover($lid,4242,1,true);
    $recovery->recover($lid,4242,1,true);
    $recovered=$s->draft($lid,4242);
    check($recovered['state']==='draft' && (int)$recovered['revision']===2 && (int)$recovered['mailing_attempted']===0 && (int)$recovered['filter_id']===$filter,'Verified legacy recovery restores editing while keeping its managed reservation');
    $s->execute("UPDATE #__intercom_drafts SET state='uncertain' WHERE id=$lid");
    check(!$recovery->eligible($s->draft($lid,4242)),'Incomplete, repeated or changed legacy evidence cannot unlock another operation');
}finally{$s->rollback();}
echo "TEST RECOVERY AND MAILBOX OK\n";

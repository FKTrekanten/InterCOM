<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Abandonment fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Service\Abandonment;
use FKT\Component\Intercom\Administrator\Domain\RetirementGateway;
use FKT\Component\Intercom\Administrator\Service\ReleaseApproval;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway;
$r=$app->bootComponent('com_intercom')->runtime; $s=$r->store;
$s->begin();
try {
    $config=array_replace($r->config,['mode'=>'live','group_id'=>758666]);
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($config))." WHERE element='com_intercom'");
    $s->execute("UPDATE #__intercom_connections SET account_id='231113' WHERE provider='cleverreach'");
    $gateway=new class implements RetirementGateway {
        public string $identity='231113'; public bool $present=true; public bool $draftSafe=true; public bool $fail=false; public $afterCheck=null; public int $reads=0;
        public function account(): string {return $this->identity;}
        public function assertDraft(int $mailing,int $group,int $filter): void {if(!$this->draftSafe) throw new RuntimeException('COM_INTERCOM_ABANDON_UNSAFE',409);}
        public function assertAbsent(int $mailing,int $group,int $filter): void {
            $this->reads++;
            if($this->fail) throw new TypeError('Injected interruption after provider retirement');
            if($this->present) throw new RuntimeException('COM_INTERCOM_ABANDON_EXISTS',409);
            if($this->afterCheck) ($this->afterCheck)();
        }
    };
    $service=new Abandonment($s,$gateway,$config);
    $message=\FKT\Component\Intercom\Administrator\Domain\Message::validate(['type'=>'club','sender'=>'Club','subject_da'=>'DA','subject_en'=>'EN','body_da'=>'Hej','body_en'=>'Hello']);
    $seed=static function(int $filter,int $mailing,string $state='deleted') use($s,$message): int {
        $s->execute("INSERT INTO #__intercom_drafts(owner_id,delivery_mode,content,state,revision,filter_id,mailing_id,mailing_attempted,lease_generation,created_at,updated_at) VALUES(42,'live',".$s->q(json_encode($message)).",'$state',2,$filter,$mailing,1,3,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $id=(int)$s->db->insertid();
        $s->execute("INSERT INTO #__intercom_filters(filter_id,draft_id,group_id,managed) VALUES($filter,$id,758666,1)");
        $s->execute("INSERT INTO #__intercom_history(draft_id,revision,actor_id,delivery_mode,account_id,group_id,type_key,state,filter_id,mailing_id,created_at) VALUES($id,1,42,'live','231113',758666,'club','prepared',$filter,$mailing,UTC_TIMESTAMP())");
        return $id;
    };
    $expect=static function(callable $run,string $key) {try {$run();throw new LogicException('Expected rejection');}catch(RuntimeException $e){check($e->getMessage()===$key,'Guard rejects: '.$key);}};
    $blockedGateway=new class implements \FKT\Component\Intercom\Administrator\Domain\DeliveryGateway {
        public function mode(): string {return 'live';}
        public function tags(string $origin): array {throw new LogicException('Claimed draft must not read provider tags');}
        public function prepare(array $message,int $filterId,int $mailingId): int {throw new LogicException('Claimed draft must not prepare a mailing');}
        public function preview(int $mailingId,string $email): void {throw new LogicException('Claimed draft must not send tests');}
        public function release(int $mailingId,int $timestamp): void {throw new LogicException('Claimed draft must not send mail');}
    };
    $blockedWorkflow=new Workflow($s,$blockedGateway,new Policy(['access'=>true,'compose'=>true,'send'=>true,'club'=>true],['all'=>true]),42);
    $ids=[];
    foreach([783416=>17459483,783415=>17459494] as $filter=>$mailing) {
        $id=$seed($filter,$mailing);$ids[$filter]=$id;
        $op=$service->inspect($filter,2,3,77,'Abandoned test-only mailing');
        check($s->row("SELECT state FROM #__intercom_drafts WHERE id=$id")['state']==='abandoning','Deleted test-only draft is claimed before provider checks');
        check($s->hasLiveReservations(),'Abandonment retains account/list protection');
        $expect(fn()=>$blockedWorkflow->preview($id,2,'fixture@example.test'),'COM_INTERCOM_CONFLICT');
        $expect(fn()=>$blockedWorkflow->release($id,2,0),'COM_INTERCOM_CONFLICT');
        $expect(fn()=>$blockedWorkflow->restore($id,2),'COM_INTERCOM_CONFLICT');
        $expect(fn()=>$blockedWorkflow->delete($id,2),'COM_INTERCOM_DELETE_DENIED');
        $expect(fn()=>$blockedWorkflow->saveDraft($message,$id,2),'COM_INTERCOM_CONFLICT');
        check($service->inspect($filter,2,3,77,'Repeat confirmation')===$op,'Duplicate inspection resumes the same operation');
        $expect(fn()=>$service->verify($op,77,false),'COM_INTERCOM_ABANDON_CONFIRM_REQUIRED');
        $gateway->present=true;
        $expect(fn()=>$service->verify($op,77,true),'COM_INTERCOM_ABANDON_EXISTS');
        check((int)$s->row("SELECT draft_id FROM #__intercom_filters WHERE filter_id=$filter")['draft_id']===$id,'Accessible provider mailing retains its reservation');
        $gateway->present=false;$gateway->fail=true;
        try {$service->verify($op,77,true);throw new LogicException('Expected interruption');}catch(TypeError $e) {}
        check($s->row("SELECT state FROM #__intercom_abandonments WHERE id=$op")['state']==='unverified','Interrupted verification persists a recoverable operation');
        $gateway->fail=false;
        $service->verify($op,77,true);
        $row=$s->row("SELECT * FROM #__intercom_drafts WHERE id=$id");
        check($row['state']==='deleted' && !$row['filter_id'] && (int)$row['mailing_id']===0 && $row['tested_revision']===null,'Released deleted draft stays deleted with detached operational IDs');
        check($s->row("SELECT * FROM #__intercom_filters WHERE filter_id=$filter")['reconciliation_status']==='abandoned','Abandonment is distinguished from completed delivery');
        check($s->row("SELECT state FROM #__intercom_history WHERE draft_id=$id")['state']==='abandoned','Prepared history becomes abandoned without fabricated delivery');
        $operation=$s->row("SELECT * FROM #__intercom_abandonments WHERE id=$op");
        check((int)$operation['mailing_id']===$mailing && (int)$operation['filter_id']===$filter && (int)$operation['verified_by']===77 && $operation['reason']==='Abandoned test-only mailing','Historical IDs and operator reason remain available');
        $reads=$gateway->reads;$service->verify($op,77,true);
        check($gateway->reads===$reads,'Repeated verification performs no provider request or lease mutation');
    }
    $s->execute("INSERT INTO #__intercom_filters(filter_id,group_id,managed,reconciliation_status) VALUES(783417,758666,1,'released')");
    check($s->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id=783417')['draft_id']===null,'Already available production-equivalent filter remains available');
    // A currently valid proof on a different draft must survive abandonment.
    $approval=new ReleaseApproval($s);
    $proof=$seed(790001,17900001,'tested');
    $s->execute("INSERT INTO #__intercom_acceptance(draft_id,actor_id,fingerprint,recipient_hash,state,created_at) VALUES($proof,42,".$s->q($approval->fingerprint()).",'fixture','verified',UTC_TIMESTAMP())");
    check($approval->valid(),'Unrelated acceptance is valid before abandonment');
    $retired=$seed(790002,17900002,'tested');
    $s->execute("INSERT INTO #__intercom_acceptance(draft_id,actor_id,fingerprint,recipient_hash,state,created_at) VALUES($retired,42,'old','fixture','prepared',UTC_TIMESTAMP())");
    $op=$service->inspect(790002,2,3,77,'Obsolete acceptance test');$service->verify($op,77,true);
    check($s->row("SELECT state FROM #__intercom_acceptance WHERE draft_id=$retired")['state']==='retired' && $approval->valid(),'Incomplete acceptance retires without changing unrelated valid approval');
    check($s->row("SELECT state FROM #__intercom_drafts WHERE id=$retired")['state']==='cancelled','Abandoned active draft becomes cancelled');
    // Refuse unsafe local records without creating a claim.
    foreach(['scheduled','submitted','uncertain','releasing','completed'] as $i=>$state) {
        $filter=791000+$i;$seed($filter,17910000+$i,$state);
        $expect(fn()=>$service->inspect($filter,2,3,77,'Unsafe fixture'),'COM_INTERCOM_ABANDON_UNSAFE');
        check(!$s->row("SELECT id FROM #__intercom_abandonments WHERE filter_id=$filter"),'Unsafe draft creates no abandonment operation');
    }
    $sent=$seed(792000,17920000);$s->execute("UPDATE #__intercom_history SET requested_at=UTC_TIMESTAMP() WHERE draft_id=$sent");
    $expect(fn()=>$service->inspect(792000,2,3,77,'Submitted fixture'),'COM_INTERCOM_ABANDON_UNSAFE');
    $expect(fn()=>$service->inspect(790001,2,3,77,'Verified acceptance'),'COM_INTERCOM_ABANDON_UNSAFE');
    $stale=$seed(792001,17920001);$op=$service->inspect(792001,2,3,77,'Stale operation');
    $gateway->identity='999';$expect(fn()=>$service->verify($op,77,true),'COM_INTERCOM_ACCOUNT_MISMATCH');$gateway->identity='231113';
    $gateway->afterCheck=static function() use($s,$stale) {$s->execute("UPDATE #__intercom_drafts SET lease_generation=lease_generation+1 WHERE id=$stale");};
    $expect(fn()=>$service->verify($op,77,true),'COM_INTERCOM_CONFLICT');$gateway->afterCheck=null;
    check((int)$s->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id=792001')['draft_id']===$stale,'Stale provider result never releases a changed lease');
    // Missing provider evidence on initial inspection cannot become a release.
    $unsafe=$seed(792002,17920002);$gateway->draftSafe=false;
    $expect(fn()=>$service->inspect(792002,2,3,77,'Unverified baseline'),'COM_INTERCOM_ABANDON_UNSAFE');$gateway->draftSafe=true;
    $op=(int)$s->row('SELECT id FROM #__intercom_abandonments WHERE filter_id=792002')['id'];
    $expect(fn()=>$service->verify($op,77,true),'COM_INTERCOM_ABANDON_UNVERIFIED');
    $row=$s->row("SELECT state,lease_generation,filter_id FROM #__intercom_drafts WHERE id=$unsafe");
    check($row['state']==='deleted' && (int)$row['lease_generation']===4 && (int)$row['filter_id']===792002,'Failed initial inspection restores the original state and retains its lease with a new generation');
    check($s->row("SELECT state FROM #__intercom_abandonments WHERE id=$op")['state']==='cancelled','Unverified initial inspection is cancelled without blocking normal reconciliation');
    $retry=$service->inspect(792002,2,4,77,'Retry verified inspection');
    check($retry!==$op,'A failed initial inspection can be retried with fresh evidence');
    $service->verify($retry,77,true);
    // Restoration starts over, and preparing the next snapshot replaces the
    // current history without erasing the durable abandoned provider references.
    $id=$ids[783415];
    $s->execute('UPDATE #__intercom_drafts SET content='.$s->q(json_encode($message))." WHERE id=$id");
    $workflow=new Workflow($s,new FakeGateway(),new Policy(['access'=>true,'compose'=>true,'send'=>true,'club'=>true],['all'=>true]),42);
    $restored=$workflow->restore($id,3);
    check($restored['state']==='draft' && !$restored['mailing_id'] && !$restored['filter_id'] && $restored['tested_revision']===null,'Restoration requires a fresh mailing, audience and test');
    (new \FKT\Component\Intercom\Administrator\Service\History($s))->prepare($restored,$message);
    check($s->row("SELECT state FROM #__intercom_history WHERE draft_id=$id")['state']==='prepared','Restored abandoned draft can prepare a new history snapshot');
    check((int)$s->row("SELECT mailing_id FROM #__intercom_abandonments WHERE draft_id=$id")['mailing_id']===17459494,'Restoration preserves historical abandoned mailing ID');
    check(!$s->row("SELECT id FROM #__intercom_audit WHERE draft_id IN (".implode(',',$ids).") AND event IN ('mailing.completed','release.accepted')"),'Abandonment records no completed send or member release');
} finally {$s->rollback();}
echo "NATIVE ABANDONMENT OK\n";

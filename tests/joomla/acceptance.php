<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Acceptance fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Service\ReleaseApproval;
use FKT\Component\Intercom\Administrator\Domain\Message;
$r = $app->bootComponent('com_intercom')->runtime; $s = $r->store;
$s->begin();
try {
    $config=['mode'=>'live','group_id'=>758666,'sender_name'=>'Club','sender_email'=>'club@example.org','unsubscribe_form_id'=>'123','acceptance_recipient'=>'approved@example.org','release_verified'=>true];
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($config))." WHERE element='com_intercom'");
    $s->execute("UPDATE #__intercom_connections SET account_id='123456' WHERE provider='cleverreach'");
    $approval=new ReleaseApproval($s);
    check(!$approval->valid(),'Old editable checkbox never grants release approval');
    try {$approval->authorize(123); throw new Exception('Expected unverified rejection');} catch(RuntimeException $e) {check($e->getMessage()==='COM_INTERCOM_RELEASE_NOT_VERIFIED','Normal releases require server approval');}
    $message=Message::validate(['type'=>'club','sender'=>'Club','subject_da'=>'DA','subject_en'=>'EN','body_da'=>'Hej','body_en'=>'Hello']);
    $message['acceptance_email']=$config['acceptance_recipient'];$message['definition']=['category_id'=>0];
    $rules=CleverReachGateway::filterRules($message);
    $s->execute("INSERT INTO #__intercom_drafts(owner_id,content,revision,state,delivery_mode,filter_id,mailing_id,audience_rules,created_at,updated_at) VALUES(42,".$s->q(json_encode($message)).",1,'tested','live',3988888887,838383,".$s->q(json_encode($rules)).",UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $id=$s->db->insertid(); $d=$s->draft($id,42);
    $s->execute("INSERT INTO #__intercom_filters(filter_id,group_id,managed,draft_id) VALUES(3988888887,758666,1,$id)");
    $approval->prepared($d,$config['acceptance_recipient'],42);
    $s->execute("UPDATE #__intercom_acceptance SET state='prepared' WHERE draft_id=$id");
    $completed=false;
    $g=new CleverReachGateway(fn()=>'fixture',$config,static function($method,$path) use($rules,&$completed) {
        check($method==='GET','Acceptance verification never writes to provider or releases members');
        if(str_ends_with($path,'/stats')) return ['active_count'=>1];
        if(str_contains($path,'/receivers?')) return [['email'=>'approved@example.org','group_id'=>'758666','activated'=>123,'deactivated'=>0,'bounced'=>0]];
        if(str_starts_with($path,'/v3/blacklist/')) throw new RuntimeException('Absent',404);
        if(str_ends_with($path,'/blacklist')) return [];
        if(str_contains($path,'/filters/')) return ['id'=>3988888887,'rules'=>$rules];
        return ['id'=>838383,'sender_name'=>'Club','category_id'=>0,'is_mailing'=>true,'is_campaign'=>false,'is_dynamic'=>false,'mailing_groups'=>['group_ids'=>['758666']],'started'=>$completed?time()-60:0,'finished'=>$completed?time()-30:0];
    });
    $approval->check($id,42,$config,$g);
    try{$approval->check($id,43,$config,$g);throw new Exception('Expected owner rejection');}catch(RuntimeException $e){check($e->getCode()===403 || $e->getCode()===404,'Another administrator cannot use this owner acceptance draft');}
    $s->execute("UPDATE #__intercom_acceptance SET state='checking' WHERE draft_id=$id");
    $approval->authorize(838383,$id,42);
    check(!$approval->valid(),'One-recipient exception does not unlock ordinary member sends');
    $s->execute("UPDATE #__intercom_acceptance SET state='submitted' WHERE draft_id=$id");
    try{$approval->verify($id,42,$config,$g);throw new Exception('Expected wait');}catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_ACCEPTANCE_WAITING','Acceptance cannot be verified before provider completion');}
    $completed=true;$approval->verify($id,42,$config,$g);
    check($approval->valid(),'Human receipt plus provider completion records server approval');
    $approval->authorize(838383);
    $event=$s->row("SELECT context FROM #__intercom_audit WHERE event='acceptance.verified' AND draft_id=$id");
    check(!str_contains($event['context'],'approved@example.org'),'Acceptance audit omits recipient address');
    foreach(['group_id'=>999,'sender_name'=>'Changed','sender_email'=>'other@example.org','unsubscribe_form_id'=>'456','acceptance_recipient'=>'other@example.org'] as $key=>$value) {
        $changed=array_replace($config,[$key=>$value]);
        $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($changed))." WHERE element='com_intercom'");
        check(!$approval->valid(),'Changing '.$key.' invalidates release acceptance');
    }
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($config))." WHERE element='com_intercom'");
    $s->execute("UPDATE #__intercom_connections SET account_id='999999' WHERE provider='cleverreach'");
    check(!$approval->valid(),'Changing verified customer account invalidates acceptance');
} finally {$s->rollback();}
echo "NATIVE ACCEPTANCE OK\n";

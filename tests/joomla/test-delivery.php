<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Test delivery requires disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Domain\AudienceGateway;
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Service\TestDelivery;
$r=$app->bootComponent('com_intercom')->runtime;$s=$r->store;
$transport=$r->testDelivery();
$gateway=new class implements AudienceGateway,DeliveryGateway {
    public int $providerPreviews=0,$releases=0;
    public function mode():string{return 'live';}
    public function tags(string $origin):array{return $origin==='group'?['group.Youth']:[];}
    public function updateAudience(int $id,array $rules):void{}
    public function assertAudience(int $id,array $rules):void{}
    public function statistics(int $id):int{return 1;}
    public function prepare(array $message,int $filter,int $mailing):int{return 838383;}
    public function preview(int $mailing,string $email):void{$this->providerPreviews++;throw new RuntimeException('CR account user restriction');}
    public function release(int $mailing,int $timestamp):void{$this->releases++;}
};
// No send permission: delegated composer can inspect tests but cannot release members.
$policy=new Policy(['access'=>true,'compose'=>true,'class'=>true,'send'=>false],['all'=>false,'tags'=>['group.Youth']]);
$w=new Workflow($s,$gateway,$policy,42,null,null,null,null,$transport);
$s->begin();
try {
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode(['mode'=>'live','group_id'=>787878]))." WHERE element='com_intercom'");
    $s->execute('INSERT INTO #__intercom_filters(filter_id,group_id,managed) VALUES(3988888888,787878,1)');
    $m=['type'=>'class','sender'=>'Club','subject_da'=>'CI delegated dansk','subject_en'=>'CI delegated English','body_da'=>'Hej {FIRSTNAME[std:Medlem]}','body_en'=>'Hello {FIRSTNAME[std:Member]}','tags'=>['group.Youth']];
    $d=$w->save($m);$id=(int)$d['id'];
    $d=$w->preview($id,1,'delegated-coach@example.invalid');
    check($d['state']==='tested' && $gateway->providerPreviews===0 && $gateway->releases===0,'Delegated composer tests through native Joomla mailer without CR preview eligibility or member release');
    $event=json_decode($s->row("SELECT context FROM #__intercom_audit WHERE event='preview.accepted' AND draft_id=$id ORDER BY id DESC LIMIT 1")['context'],true);
    check($event['transport']==='joomla' && $event['inbox_confirmed']===false,'Audit records SMTP acceptance, not inbox confirmation');
    $mailbox=json_decode(file_get_contents('http://mailpit:8025/api/v1/messages'),true);
    $messages=array_values(array_filter($mailbox['messages'],static fn($m)=>str_contains($m['Subject'],'CI delegated')));
    check(count($messages)===2,'Both language tests reached the local SMTP mailbox');
    foreach($messages as $mail) {
        $v=json_decode(file_get_contents('http://mailpit:8025/api/v1/message/'.$mail['ID']),true);
        check(count($v['To'])===1 && $v['To'][0]['Address']==='delegated-coach@example.invalid','Test mailbox contains only the delegated composer recipient');
        check(!str_contains($v['HTML'],'{UNSUBSCRIBE}') && !str_contains($v['HTML'],'{FIRSTNAME'),'Received test has example member data and no active personal links');
        check(str_contains($v['HTML'],'prefers-color-scheme:dark'),'Received test preserves dark-mode styles');
        check(str_contains($v['Text'],'Medlem') || str_contains($v['Text'],'Member'),'Received multipart mail contains the proper plain-text alternative');
    }
    try{$w->release($id,1,0,1);throw new Exception('Expected send denial');}
    catch(RuntimeException $e){check($e->getCode()===403,'Successful delegated preview does not grant send permission');}
    $fail=new TestDelivery(static fn()=>false);
    $bad=new Workflow($s,$gateway,$policy,42,null,null,null,null,$fail);
    try{$bad->preview($id,1,'delegated-coach@example.invalid');throw new Exception('Expected mail failure');}
    catch(RuntimeException $e){check($e->getMessage()==='COM_INTERCOM_TEST_DELIVERY_ERROR','SMTP failure does not claim successful test');}
    check($s->draft($id,42)['state']==='draft' && $gateway->releases===0,'Failed SMTP test requires another manual test and never sends members');
} finally {$s->rollback();}
echo "NATIVE TEST DELIVERY OK\n";

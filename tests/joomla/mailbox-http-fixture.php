<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Mailbox fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\PreparationGateway;
use FKT\Component\Intercom\Administrator\Service\Workflow;
$r=$app->bootComponent('com_intercom')->runtime;$s=$r->store;
if(($r->config['mode']??'fake')!=='fake')throw new RuntimeException('Mailbox fixture requires simulation');
$owner=(int)$s->row("SELECT id FROM #__users WHERE username='intercom'")['id'];
$user=\Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($owner);
$w=$r->workflow($user);
$message=['type'=>'offer','sender'=>'Trekanten Fencing','subject_da'=>'Fægtelejr i efterårsferien','subject_en'=>'Autumn fencing camp','body_da'=>'<p>Kære medlem</p><p>Vi inviterer til træning og fællesskab i efterårsferien.</p>','body_en'=>'<p>Dear member</p><p>Join us for training and time together during the autumn break.</p>','format'=>'html','tags'=>[],'age_from'=>6,'age_to'=>10];
$failed=$w->save($message);$id=(int)$failed['id'];
$gateway=new class implements DeliveryGateway,PreparationGateway {
    public function mode():string{return 'fake';}
    public function tags(string $origin):array{return [];}
    public function prepare(array $m,int $filter,int $mailing):int{throw new RuntimeException('Fixture');}
    public function prepareWithProgress(array $m,int $filter,int $mailing,callable $progress):int{$progress('provider_checks');throw new RuntimeException('Fixture');}
    public function preview(int $mailing,string $email):void{}
    public function release(int $mailing,int $timestamp):void{}
};
$failure=new Workflow($s,$gateway,$r->policy($user),$owner);
// Allocate a simulated filter through the real audience path first.
$w->estimate($id,1);
try{$failure->preview($id,1,'composer@example.invalid');}catch(RuntimeException $e){if($e->getMessage()!=='Fixture')throw $e;}
$ids=['failed'=>$id];
foreach(['draft','submitted','scheduled','completed'] as $state){
    $m=$message;$m['subject_en']='Mailbox fixture — '.$state;$m['subject_da']='Mailoversigt — '.$state;
    $d=$w->save($m);$did=(int)$d['id'];
    if($state!=='draft'){
        $w->preview($did,1,'composer@example.invalid');
        $w->release($did,1,$state==='scheduled'?time()+3600:0,1);
        if($state==='completed'){
            $s->execute("UPDATE #__intercom_drafts SET state='completed' WHERE id=$did");
            $s->execute("UPDATE #__intercom_history SET state='completed',finished_at=UTC_TIMESTAMP() WHERE draft_id=$did");
        }
    }
    $ids[$state]=$did;
}
echo json_encode($ids)."\n";

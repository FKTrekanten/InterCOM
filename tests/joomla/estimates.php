<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Estimate fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Domain\AudienceGateway;
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\FilterCreator;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Service\History;
$s=$app->bootComponent('com_intercom')->runtime->store;
$gateway=new class($s) implements AudienceGateway,DeliveryGateway,FilterCreator {
    public int $writes=0,$prepares=0,$releases=0,$count=1,$last=3899999000;
    public bool $failWrite=false,$failRead=false;
    public ?Closure $duringWrite=null;
    public function __construct(private $store) {}
    public function mode(): string {return 'live';}
    public function tags(string $origin): array {return $origin==='group'?['group.Youth','group.Adult']:['membership.A'];}
    public function createFilter(int $groupId,string $name): int {return ++$this->last;}
    public function updateAudience(int $filterId,array $rules): void {$this->writes++;if($this->duringWrite)($this->duringWrite)();if($this->failWrite)throw new RuntimeException('Timeout');}
    public function assertAudience(int $filterId,array $rules): void {}
    public function statistics(int $filterId): int {if($this->failRead)throw new RuntimeException('Offline');return $this->count;}
    public function prepare(array $message,int $filterId,int $mailingId): int {
        $this->prepares++;check(isset($message['prepared_html']) && str_contains($message['prepared_html'],'{UNSUBSCRIBE}'),'Exact provider HTML includes required unsubscribe');return 888888;
    }
    public function preview(int $mailingId,string $email): void {}
    public function release(int $mailingId,int $timestamp): void {
        $this->releases++;
        $h=$this->store->row("SELECT snapshot FROM #__intercom_history WHERE mailing_id=$mailingId AND state='releasing'");
        check($h!==null && str_contains($h['snapshot'],'English'),'Frozen history is durable before provider release');
    }
};
$policy=new Policy(['access'=>true,'compose'=>true,'send'=>true,'class'=>true],['all'=>true]);
$w=new Workflow($s,$gateway,$policy,42);
$s->begin();
try {
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode(['mode'=>'live','group_id'=>987651,'max_filters'=>10]))." WHERE element='com_intercom'");
    $input=['type'=>'class','sender'=>'Club','tags'=>['group.Youth']];
    $d=$w->saveAudience($input);$id=(int)$d['id'];
    check(json_decode($d['content'],true)['body_en']==='','Audience-only draft has no invented body');
    $stale=new Workflow($s,$gateway,$policy,42,null,null,null,['mode'=>'live','group_id'=>123]);
    try {$stale->estimate($id,1);throw new Exception('Expected stale configuration');}
    catch(RuntimeException $e) {check($e->getCode()===409,'Stale provider configuration rejected before requests');}
    $d=$w->estimate($id,1);
    check((int)$d['estimate_count']===1 && $gateway->prepares===0 && $gateway->releases===0,'Step transition counts audience without mailing or delivery: '.json_encode(array_intersect_key($d,array_flip(['state','estimate_count','estimate_error','filter_id']))));
    check((int)$d['mailing_attempted']===0 && $d['lease_until']!==null,'Audience lease is explicitly proved mailing-free');
    try {$w->preview($id,1,'one@example.invalid');throw new Exception('Expected incomplete message guard');}
    catch(RuntimeException $e) {check($e->getCode()===422,'Incomplete audience draft cannot prepare mailing');}
    check((int)$s->draft($id,42)['mailing_attempted']===0,'Content validation failure does not mark mailing attempted');
    $writes=$gateway->writes;$w->estimate($id,1);
    check($gateway->writes===$writes,'Fresh identical audience uses cached estimate');
    try {(new Workflow($s,$gateway,$policy,99))->estimate($id,1);throw new Exception('Expected ownership');}
    catch(RuntimeException $e) {check($e->getCode()===404,'Estimate rejects another owner');}
    try {$w->saveAudience($input,$id,0);throw new Exception('Expected revision');}
    catch(RuntimeException $e) {check($e->getCode()===409,'Audience save rejects stale tab');}
    $full=array_merge($input,['subject_da'=>'DA','subject_en'=>'EN','body_da'=>'Dansk','body_en'=>'English']);
    $d=$w->save($full,$id,1);
    check((int)$d['estimate_count']===1,'Body-only save preserves audience estimate');
    $d=$w->saveAudience(array_merge($input,['gender'=>'female']),$id,2);
    check(json_decode($d['content'],true)['body_en']==='English' && $d['estimate_count']===null,'Audience change preserves body and invalidates count');
    $d=$w->estimate($id,3,true);
    $d=$w->preview($id,3,'one@example.invalid');
    check((int)$d['mailing_attempted']===1 && $d['state']==='tested','Mailing preparation permanently protects lease');
    $gateway->count=0;
    try {$w->release($id,3,0,1);throw new Exception('Expected empty audience');}
    catch(RuntimeException $e) {check($e->getMessage()==='COM_INTERCOM_NO_RECIPIENTS','Zero count blocks live release');}
    check($s->draft($id,42)['state']==='tested' && $gateway->releases===0,'Failed read-only preflight does not become uncertain send');
    $gateway->count=2;
    try {$w->release($id,3,0,1);throw new Exception('Expected count confirmation');}
    catch(RuntimeException $e) {check($e->getMessage()==='COM_INTERCOM_COUNT_CHANGED','Count change requires renewed confirmation');}
    check((int)$s->draft($id,42)['estimate_count']===2,'Changed count is available for review');
    $d=$w->release($id,3,0,2);
    check($gateway->releases===1 && $d['state']==='submitted','Confirmed updated estimate allows one release');
    $h=new History($s);$history=$h->detail($id);$frozen=$history['snapshot'];
    check((int)json_decode($frozen,true)['estimate']===1,'History preserves the count at preparation independently of final submission');
    $s->execute("UPDATE #__intercom_drafts SET updated_at=UTC_TIMESTAMP()-INTERVAL 31 DAY WHERE id=$id");
    $s->execute("UPDATE #__intercom_history SET requested_at=UTC_TIMESTAMP()-INTERVAL 31 DAY WHERE draft_id=$id");
    $w->maintain(30);
    check($s->draft($id,42)['content']!=='{}' && $h->detail($id)['snapshot']===$frozen,'A live submission awaiting provider completion retains current message and history after the retention window');
    $h->completed($id,['started'=>time()-50,'finished'=>time()-30]);
    check($h->page([],5,0,true)['rows'][0]['draft_id']===$d['id'],'Confirmed send appears on dashboard');
    $h->outcome($id,'uncertain');
    check($h->detail($id)['state']==='completed','Late uncertain outcome cannot undo confirmed completion');
    check($h->detail($id)['snapshot']===$frozen,'Outcome transitions cannot mutate frozen message content');
    $s->execute("UPDATE #__intercom_drafts SET state='completed' WHERE id=$id");
    $s->execute("UPDATE #__intercom_history SET requested_at=UTC_TIMESTAMP()-INTERVAL 31 DAY,started_at=UTC_TIMESTAMP()-INTERVAL 31 DAY,finished_at=UTC_TIMESTAMP()-INTERVAL 31 DAY WHERE draft_id=$id");
    $h->maintain(30);
    check($h->detail($id)['snapshot']===null,'History content expiry preserves only operational metadata');
    $a=$w->saveAudience($input);$a=$w->estimate((int)$a['id'],1);$aid=(int)$a['id'];$filter=(int)$a['filter_id'];
    $s->execute("UPDATE #__intercom_drafts SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE id=$aid");
    $w->maintain(30);
    check($s->draft($aid,42)['filter_id']===null && $s->row("SELECT draft_id FROM #__intercom_filters WHERE filter_id=$filter")['draft_id']===null,'Only proved expired audience lease is reusable');
    $a=$w->estimate($aid,1,true);
    $gateway->failRead=true;$a=$w->estimate($aid,1,true);$gateway->failRead=false;
    check($a['state']==='draft' && $a['estimate_count']===null && isset($a['estimate_error']),'Count failure is unavailable, not zero or a send uncertainty');
    $a=$w->saveAudience(array_merge($input,['gender'=>'female']),$aid,1);
    $gateway->failWrite=true;$a=$w->estimate($aid,2,true);$gateway->failWrite=false;
    check($a['state']==='uncertain','Timed-out rule write stays protected');
    $s->execute("UPDATE #__intercom_drafts SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 DAY WHERE id=$aid");$w->maintain(30);
    check($s->draft($aid,42)['filter_id']!==null,'Age cannot free uncertain provider writes');
    $late=$w->saveAudience($input);$lateId=(int)$late['id'];
    $gateway->duringWrite=static function() use($s,$lateId) {$s->execute("UPDATE #__intercom_drafts SET state='uncertain',lease_generation=lease_generation+1 WHERE id=$lateId");};
    $late=$w->estimate($lateId,1,true);$gateway->duringWrite=null;
    check($late['state']==='uncertain' && $late['estimate_count']===null,'Delayed reply cannot overwrite newer operation state');
    // Dashboard ordering uses provider start time, excludes pending/future/simulated rows.
    for($i=1;$i<=7;$i++) {
        $s->execute("INSERT INTO #__intercom_history(draft_id,revision,actor_id,delivery_mode,type_key,state,started_at,created_at) VALUES(".(3900000000+$i).",1,42,'live','class','completed',UTC_TIMESTAMP()-INTERVAL $i HOUR,UTC_TIMESTAMP())");
    }
    $latest=$h->page([],5,0,true);
    check(count($latest['rows'])===5 && (int)$latest['rows'][0]['draft_id']===3900000001 && (int)$latest['rows'][4]['draft_id']===3900000005,'Exactly five latest confirmed provider sends ordered by sending time');
    $page1=$h->page(['state'=>'completed'],5,0);$page2=$h->page(['state'=>'completed'],5,5);
    check(!array_intersect(array_column($page1['rows'],'draft_id'),array_column($page2['rows'],'draft_id')),'History pagination has distinct bounded pages');
    check($h->page(['actor'=>'99999'],20,0)['total']===0,'Actor filter applies before pagination');
} finally {$s->rollback();}
echo "ESTIMATES AND HISTORY OK\n";

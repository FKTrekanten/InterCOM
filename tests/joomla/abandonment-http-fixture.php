<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Requires disposable CI'); }
require __DIR__.'/bootstrap.php';
$s=$app->bootComponent('com_intercom')->runtime->store;
$statePath='/tmp/intercom-abandonment-http-state.json';
$action=$argv[1]??'';
if($action==='seed') {
    if(file_exists($statePath)) throw new RuntimeException('Fixture already active');
    $params=$s->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'")['params'];
    $envelope=$s->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'")['envelope'];
    $originalAccount=$s->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'];
    $owner=(int)$db->setQuery("SELECT id FROM #__users WHERE username='intercom'")->loadResult();
    $config=array_replace(json_decode($params,true),['mode'=>'live','group_id'=>799998]);
    $s->execute('UPDATE #__extensions SET params='.$s->q(json_encode($config))." WHERE element='com_intercom' AND type='component'");
    // No credentials: authenticated HTTP checks can never contact CleverReach.
    $s->execute("UPDATE #__intercom_connections SET envelope='',account_id='231113' WHERE provider='cleverreach'");
    $s->execute("INSERT INTO #__intercom_drafts(owner_id,delivery_mode,content,state,revision,filter_id,mailing_id,mailing_attempted,lease_generation,created_at,updated_at) VALUES($owner,'live','{}','deleted',2,799999,17999999,1,3,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $id=(int)$db->insertid();
    $account=$s->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'];
    $s->execute("INSERT INTO #__intercom_filters(filter_id,draft_id,group_id,managed) VALUES(799999,$id,799998,1)");
    $s->execute("INSERT INTO #__intercom_history(draft_id,revision,actor_id,delivery_mode,account_id,group_id,type_key,state,filter_id,mailing_id,created_at) VALUES($id,1,$owner,'live',".$s->q($account).",799998,'club','prepared',799999,17999999,UTC_TIMESTAMP())");
    file_put_contents($statePath,json_encode(['draft'=>$id,'params'=>$params,'envelope'=>$envelope,'account'=>$originalAccount]));chmod($statePath,0600);
    echo json_encode(['draft'=>$id,'filter'=>799999,'mailing'=>17999999]);
} else {
    $state=json_decode(file_get_contents($statePath),true);$id=(int)$state['draft'];
    if($action==='baseline') {
        $op=$s->row("SELECT * FROM #__intercom_abandonments WHERE draft_id=$id");
        $draft=$s->row("SELECT state,lease_generation,filter_id FROM #__intercom_drafts WHERE id=$id");
        check($op && $op['state']==='cancelled' && (int)$op['baseline_verified']===0 && $draft['state']==='deleted' && (int)$draft['filter_id']===799999,'Failed initial inspection restores the draft and keeps its reservation');
        // Simulate a successful read-only baseline, without provider credentials.
        $generation=(int)$draft['lease_generation'];
        $s->execute("UPDATE #__intercom_abandonments SET baseline_verified=1,state='awaiting_removal',lease_generation=$generation WHERE draft_id=$id");
        $s->execute("UPDATE #__intercom_drafts SET state='abandoning' WHERE id=$id");
    } elseif($action==='complete') {
        $operation=$s->row("SELECT * FROM #__intercom_abandonments WHERE draft_id=$id");
        $gateway=new class($operation['account_id']) implements \FKT\Component\Intercom\Administrator\Domain\RetirementGateway {
            public function __construct(private string $identity) {}
            public function account(): string {return $this->identity;}
            public function assertDraft(int $mailing,int $group,int $filter): void {throw new LogicException('Baseline already established');}
            public function assertAbsent(int $mailing,int $group,int $filter): void {} // Disposable fixture: permanent retirement verified.
        };
        (new \FKT\Component\Intercom\Administrator\Service\Abandonment($s,$gateway,$app->bootComponent('com_intercom')->runtime->config))->verify((int)$operation['id'],(int)$operation['actor_id'],true);
    } elseif($action==='reset') {
        $s->execute('UPDATE #__extensions SET params='.$s->q($state['params'])." WHERE element='com_intercom' AND type='component'");
        $s->execute('UPDATE #__intercom_connections SET envelope='.$s->q($state['envelope']).',account_id='.$s->q($state['account'])." WHERE provider='cleverreach'");
        $s->execute("DELETE FROM #__intercom_abandonments WHERE draft_id=$id");
        $s->execute('DELETE FROM #__intercom_filters WHERE filter_id=799999');
        $s->execute("DELETE FROM #__intercom_history WHERE draft_id=$id");
        $s->execute("DELETE FROM #__intercom_drafts WHERE id=$id");
        $s->execute("DELETE FROM #__intercom_audit WHERE draft_id=$id");
        unlink($statePath);
    } else {throw new RuntimeException('Unknown fixture action');}
}

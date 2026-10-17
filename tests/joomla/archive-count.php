<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Archive count checks require disposable CI'); }
require __DIR__ . '/bootstrap.php';

use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Service\Archive;

$store=$app->bootComponent('com_intercom')->runtime->store;
$store->begin();
try {
    $message=Message::validate(['type'=>'club','sender'=>'Club','subject_da'=>'DA','subject_en'=>'EN','body_da'=>'Dansk','body_en'=>'English','tags'=>[]]);
    $archive=new Archive($store,'fixture@example.invalid',static fn()=>false,static function(){throw new LogicException('No mail transport expected');});
    foreach([39,0,null] as $index=>$count){
        $id=8899900+$index;
        $archive->queue(['id'=>$id,'revision'=>1,'mailing_id'=>8899990,'content'=>json_encode($message),'estimate_count'=>$count],0,42);
        $payload=json_decode($store->row("SELECT payload FROM #__intercom_archives WHERE draft_id=$id")['payload'],true);
        $expected='Expected recipients: '.($count===null?'Unavailable':$count);
        check(str_contains($payload['html'],$expected) && str_contains($payload['text'],$expected),'HTML and plain-text copies include the '.($count===null?'unavailable':$count).' recipient estimate');
    }
}finally{$store->rollback();}
echo "ARCHIVE COUNTS OK\n";

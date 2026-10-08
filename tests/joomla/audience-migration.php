<?php
require __DIR__ . '/bootstrap.php';
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Disposable CI only'); }
$r = $app->bootComponent('com_intercom')->runtime;
$s = $r->store;
use FKT\Component\Intercom\Administrator\Service\AudienceMigration;
use FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway;
use FKT\Component\Intercom\Administrator\Domain\Policy;
$s->begin();
try {
    $params = json_decode($s->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true);
    unset($params['member_audience_version']);
    $params['audience_rules'] = json_encode([['group' => 2, 'all' => false, 'tags' => ['group.epee','group.Youth']]]);
    $s->execute('UPDATE #__extensions SET params=' . $s->q(json_encode($params)) . " WHERE element='com_intercom'");
    foreach ([0,609312,758666] as $list) {
        $s->execute("INSERT IGNORE INTO #__intercom_catalogues (list_id) VALUES ($list)");
        foreach (['epee','foil','sabre'] as $discipline) {
            $s->execute("REPLACE INTO #__intercom_tags (list_id,tag,enabled,available,ordering,labels) VALUES ($list,'group.$discipline',1,1,7,'{\"da-DK\":\"Kårde\",\"en-GB\":\"Epee override\"}')");
        }
    }
    $s->execute("INSERT INTO #__intercom_drafts (owner_id,delivery_mode,content,state,revision,tested_revision,tested_fingerprint,audience_fingerprint,estimate_count,created_at,updated_at) VALUES (42,'fake','{\"type\":\"club\",\"tags\":[\"group.epee\",\"group.Youth\"]}','tested',5,5,'old','old',34,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $id = (int) $s->db->insertid();
    $s->execute("INSERT INTO #__intercom_drafts (owner_id,delivery_mode,content,state,revision,tested_revision,created_at,updated_at) VALUES (42,'fake','{\"type\":\"club\",\"age_from\":8,\"age_to\":12}','tested',3,3,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $ageId = (int) $s->db->insertid();
    $s->execute("INSERT INTO #__intercom_drafts (owner_id,delivery_mode,content,state,revision,created_at,updated_at) VALUES (42,'live','{\"type\":\"club\",\"tags\":[\"group.epee\"]}','submitted',2,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $sentId = (int) $s->db->insertid();
    AudienceMigration::run($s);
    foreach ([0,609312,758666] as $list) {
        $tag = $s->row("SELECT * FROM #__intercom_tags WHERE list_id=$list AND tag='discipline.epee'");
        check((int) $tag['enabled']===1 && (int) $tag['ordering']===7 && json_decode($tag['labels'],true)['da-DK']==='Kårde', 'Migration preserves visibility, order and translations on every list');
        check(!$s->row("SELECT tag FROM #__intercom_tags WHERE list_id=$list AND tag='group.epee'"), 'Old catalogue name removed');
    }
    $draft = $s->row("SELECT * FROM #__intercom_drafts WHERE id=$id");
    check(json_decode($draft['content'],true)['disciplines']===['discipline.epee'], 'Saved draft keeps its discipline');
    check($draft['state']==='draft' && (int)$draft['revision']===6 && $draft['tested_revision']===null && $draft['audience_fingerprint']===null && $draft['estimate_count']===null, 'Migrated audience requires a new estimate and test');
    check($s->row("SELECT state FROM #__intercom_drafts WHERE id=$ageId")['state']==='draft', 'Age audience invalidates old approval');
    check(json_decode($s->row("SELECT content FROM #__intercom_drafts WHERE id=$sentId")['content'],true)['tags']===['group.epee'], 'Submitted evidence remains frozen');
    $updated = json_decode($s->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'],true);
    $scope = Policy::audienceScope([2],json_decode($updated['audience_rules'],true));
    check($scope['tags']===['discipline.epee','group.Youth'], 'Audience access grants migrate');
    AudienceMigration::run($s);
    check($s->row("SELECT * FROM #__intercom_drafts WHERE id=$id")===$draft, 'Migration is idempotent');
    $s->execute("INSERT IGNORE INTO #__intercom_tags (list_id,tag,enabled) VALUES (0,'member.female_9',1)");
    check(!in_array('member.female_9',array_column($r->catalog->tags(false),'tag'),true), 'Member family never appears in catalogue');
    $calls = [];
    $gateway = new \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway(fn () => 'stub', [], static function ($method, $path) use (&$calls) {
        $calls[] = [$method, $path];
        return [['origin'=>'group','tag'=>'Youth'],['origin'=>'discipline','tag'=>'epee'],['origin'=>'membership','tag'=>'Active'],['origin'=>'member','tag'=>'female_9']];
    });
    $r->catalog->refreshTags($gateway,42);
    check(count($calls)===3 && count(array_filter($calls,static fn($call)=>$call[0]==='GET'))===3, 'Refresh reads all three composer families without writing receivers');
    check(!in_array('member.female_9',array_column($r->catalog->tags(false),'tag'),true), 'Unknown member family stays excluded after refresh');
    $tag = $s->row("SELECT * FROM #__intercom_tags WHERE list_id=0 AND tag='discipline.epee'");
    check((int)$tag['enabled']===1 && (int)$tag['available']===1 && (int)$tag['ordering']===7, 'Refresh preserves migrated administration settings');
} finally {
    $s->rollback();
}
echo "AUDIENCE MIGRATION OK\n";

<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Feature fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway;
use FKT\Component\Intercom\Administrator\Service\Catalog;
use FKT\Component\Intercom\Administrator\Service\CatalogMigration;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use FKT\Component\Intercom\Administrator\Service\Archive;
$r = $app->bootComponent('com_intercom')->runtime;
$store = $r->store;
$catalog = new Catalog($store, $r->config);
$catalog->refreshTags(new FakeGateway(), 42);
check($catalog->tags() === [], 'Newly discovered tags start hidden');
$meta = $store->row('SELECT revision FROM #__intercom_catalogues WHERE list_id=0');
$available = array_column($catalog->tags(false), 'tag');
$catalog->saveTags($available, [], (int)$meta['revision'], 42);
check(count($catalog->tags()) === count($available), 'Explicit visibility save enables tags');
try { $catalog->saveTags([], [], (int)$meta['revision'], 42); throw new Exception('Expected conflict'); }
catch (RuntimeException $e) { check($e->getCode() === 409, 'Stale catalogue save rejected'); }
$store->begin();
try {
    // Independently exercise type creation, native assets and type deletion.
    $input = ['type_key'=>'custom', 'suppression'=>'custom-optout', 'state'=>1,
        'translations'=>['en-GB'=>['name'=>'Custom'], 'da-DK'=>['name'=>'Særlig']], 'rules'=>[]];
    $id = $catalog->saveType($input, 42);
    check(!\Joomla\CMS\Access\Access::checkGroup(2, 'intercom.type.compose', 'com_intercom.communication.'.$id), 'New groups do not inherit global compose grants');
    $catalog->deleteType($id, 1, 42);
    check(!$store->row('SELECT id FROM #__intercom_types WHERE id='.$id), 'Unused communication can be deleted');
    // Removing a default must survive the next package postflight.
    $offer = $catalog->types()['offer'];
    $catalog->deleteType($offer['id'], $offer['revision'], 42);
    CatalogMigration::run($store);
    check(!isset($catalog->types()['offer']), 'Subsequent postflight does not resurrect a deleted default');
    $class = $catalog->types()['class'];
    $policy = new Policy(['access'=>true,'compose'=>true,'send'=>true,'class'=>true,'send_class'=>true], ['all'=>true], $catalog->types());
    $workflow = new Workflow($store, new FakeGateway(), $policy, 42, $catalog);
    $inputMessage = ['type'=>'class','sender'=>'Club','format'=>'html','subject_da'=>'DA','subject_en'=>'EN',
        'body_da'=>'<p>Hej {FIRSTNAME[std:Medlem]}</p>','body_en'=>'<p>Hello</p>','tags'=>['group.Youth']];
    $store->execute('UPDATE #__intercom_filters SET draft_id=NULL WHERE group_id=0 AND managed=1');
    $draft = $workflow->save($inputMessage);
    check(json_decode($draft['content'],true)['definition']['suppression']==='class', 'Draft snapshots suppression and translation definition');
    $draft = $workflow->preview((int)$draft['id'],1,'one@example.invalid');
    check(strlen($draft['tested_fingerprint']) === 64, 'Preview binds exact current settings and template');
    $updated = array_merge($class, ['id'=>$class['id'],'type_key'=>'class','suppression'=>'team-updates','state'=>1]);
    $catalog->saveType($updated,42);
    check($store->draft((int)$draft['id'],42)['state'] === 'draft', 'Changing group settings invalidates tested drafts');
    try { $workflow->release((int)$draft['id'],1,0); throw new Exception('Expected definition change'); }
    catch (RuntimeException $e) { check($e->getMessage()==='COM_INTERCOM_DEFINITION_CHANGED','Changed suppression cannot silently release old tested content'); }
    $new = $workflow->save($inputMessage,(int)$draft['id'],1);
    $new = $workflow->preview((int)$new['id'],2,'one@example.invalid');
    $meta = $store->row('SELECT revision FROM #__intercom_catalogues WHERE list_id=0');
    $catalog->saveTags(array_values(array_diff($available,['group.Youth'])), [], (int)$meta['revision'], 42);
    try { $workflow->release((int)$new['id'],2,0); throw new Exception('Expected hidden tag'); }
    catch (RuntimeException $e) { check($e->getCode()===403,'A disabled tag blocks release rather than broadening audience'); }
    try { $catalog->deleteType($class['id'],$class['revision']+1,42); throw new Exception('Expected used group'); }
    catch (RuntimeException $e) { check($e->getMessage()==='COM_INTERCOM_TYPE_IN_USE','Used group retains its identity'); }
    // SMTP is injected: no real email can be emitted from automatic CI.
    $sent = 0; $finished = false;
    $archive = new Archive($store,'board@example.invalid',static function () use (&$finished): bool {return $finished;},
        static function ($address,$payload) use (&$sent): bool {
            $sent++; check($address==='board@example.invalid','Only configured board address receives archive');
            check(str_contains($payload['html'],'Hej Medlem') && str_contains($payload['html'],'Hello'), 'Archive contains approved bilingual message');
            check(!str_contains($payload['html'],'{FIRSTNAME'),'Archive contains no unresolved member placeholders');
            return true;
        });
    $archive->queue($new, 0, 42);
    $archive->maintain(); check($sent===0,'Archive waits for provider completion');
    $finished=true; $archive->maintain(); $archive->maintain();
    check($sent===1,'Archive accepts exactly one send and never resends members');
    $failed = $new;
    $failed['id'] = (int)$new['id'] + 100000;
    $failedArchive = new Archive($store, 'board@example.invalid', static fn()=>true,
        static function () use (&$sent): bool { $sent++; throw new RuntimeException('SMTP timeout'); });
    $failedArchive->queue($failed,0,42);
    $failedArchive->maintain(); $failedArchive->maintain();
    check($sent===2 && $store->row('SELECT state FROM #__intercom_archives WHERE draft_id='.$failed['id'])['state']==='uncertain','Uncertain SMTP outcome is audited without automatic resending');
    $params = json_decode($store->row("SELECT params FROM #__extensions WHERE element='com_intercom'")['params'], true);
    $newRules = json_encode([['group'=>2, 'all'=>false, 'tags'=>['group.Youth']]]);
    $params['audience_rules'] = $newRules;
    $store->execute('UPDATE #__extensions SET params='.$store->q(json_encode($params))." WHERE element='com_intercom'");
    $saved = (new \FKT\Component\Intercom\Administrator\Service\Settings($r))->save(['mode'=>'fake','retention_days'=>30,'max_filters'=>2],42);
    check($saved['audience_rules'] === $newRules, 'Options preserves audience grants changed after Runtime was constructed');

    $clubPolicy = new Policy(['access'=>true,'compose'=>true,'send'=>true,'club'=>true,'send_club'=>true], ['all'=>true], $catalog->types());
    $clubWorkflow = new Workflow($store, new FakeGateway(), $clubPolicy, 42, $catalog);
    $designDraft = $clubWorkflow->save(array_merge($inputMessage, ['type'=>'club', 'tags'=>[]]));
    $designDraft = $clubWorkflow->preview((int)$designDraft['id'], 1, 'one@example.invalid');
    $designService = new \FKT\Component\Intercom\Administrator\Service\Design($store);
    $beforeDesign = $designService->snapshot();
    $designService->save(array_merge($beforeDesign['settings'], ['heading_font'=>'system']), $beforeDesign['revision'], 42);
    check($store->draft((int)$designDraft['id'],42)['state']==='draft', 'Design change invalidates tested draft');
    try { $clubWorkflow->release((int)$designDraft['id'],1,0); throw new Exception('Expected changed design'); }
    catch (RuntimeException $e) { check($e->getMessage()==='COM_INTERCOM_DEFINITION_CHANGED','Old design cannot be released'); }
    try { $designService->save([], $beforeDesign['revision'],42); throw new Exception('Expected design conflict'); }
    catch (RuntimeException $e) { check($e->getCode()===409,'Stale design save rejected'); }
    $resaved = $clubWorkflow->save(array_merge($inputMessage,['type'=>'club','tags'=>[]]),(int)$designDraft['id'],1);
    $resaved = $clubWorkflow->preview((int)$resaved['id'],2,'one@example.invalid');
    check($resaved['state']==='tested' && $resaved['tested_fingerprint']!==$designDraft['tested_fingerprint'], 'New test binds updated design revision');
    $savedDesign = $designService->snapshot();
    $designService->save([], $savedDesign['revision'],42,true);
    check($designService->snapshot()['settings']===\FKT\Component\Intercom\Administrator\Domain\EmailDesign::defaults(),'Reset restores design defaults');
    $store->execute('INSERT INTO #__intercom_filters (filter_id,group_id,managed) VALUES (3999999000,987654,1)');
    $activity = new \FKT\Component\Intercom\Administrator\Service\Activity($store, $r->config);
    $currentFilters = $activity->page('filters',['scope'=>'current'],100,0);
    check(!in_array('3999999000',array_column($currentFilters['rows'],'filter_id')),'Current pool excludes historical lists');
    check(in_array('3999999000',array_column($activity->page('filters',['scope'=>'historical'],100,0)['rows'],'filter_id')),'Historical filters remain inspectable');
    $expected = (int)$store->row('SELECT COUNT(*) n FROM #__intercom_filters WHERE managed=1 AND group_id=0')['n'];
    check((int)$activity->overview()['pool']['total']===$expected,'Dashboard pool totals match current list');
    $store->audit(42,'design.test_marker');
    $specific = $activity->page('audit',['event'=>'design.test_marker','actor'=>'42'],10,0);
    check($specific['total']===1 && count($specific['rows'])===1,'Audit actor/event filters apply before pagination');
    check($activity->page('audit',[],10,999999)['start']<999999,'Out-of-range pagination clamps to the last page');
} finally { $store->rollback(); }
check(isset($catalog->types()['offer']), 'Transaction rollback restores communication and native asset tree');
check(in_array('group.Youth', array_column($catalog->tags(), 'tag')), 'Transaction rollback preserves catalogue visibility');

// Explicitly model a delegated backend role in this disposable database.
$managerAsset = new \Joomla\CMS\Table\Asset($db);
$managerAsset->loadByName('com_intercom');
$managerRules = json_decode($managerAsset->rules, true);
$managerRules['core.manage'][6] = 1;
$managerRules['core.admin'][6] = 0;
$managerRules['intercom.audit'][6] = 0;
$managerAsset->rules = json_encode($managerRules);
check($managerAsset->store(), 'Configure disposable delegated Manager permissions');
\Joomla\CMS\Access\Access::clearStatics();
$manager = new \Joomla\CMS\User\User();
$managerData = ['name'=>'CI manager','username'=>'ci-manager','email'=>'ci-manager@example.invalid',
    'password'=>getenv('INTERCOM_ADMIN_PASSWORD'),'password2'=>getenv('INTERCOM_ADMIN_PASSWORD'),'groups'=>[6],'block'=>0];
check($manager->bind($managerData),'Bind disposable manager fixture');
$managerTable = new \Joomla\CMS\Table\User($db);
$managerRecord = $manager->getProperties();
$managerRecord['params'] = '{}';
check($managerTable->bind($managerRecord) && $managerTable->check() && $managerTable->store(),'Save disposable manager fixture without registration notifications');
$manager = new \Joomla\CMS\User\User((int)$managerTable->id);
check($manager->authorise('core.manage','com_intercom') && !$manager->authorise('intercom.audit','com_intercom') && !$manager->authorise('core.admin','com_intercom'),'Manager fixture has component access without audit/admin grants');
echo "FEATURES OK\n";

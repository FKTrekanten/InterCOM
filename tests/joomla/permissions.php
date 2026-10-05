<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Permission fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use FKT\Component\Intercom\Administrator\Service\CommunicationPermissions;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Language\Text;
$r = $app->bootComponent('com_intercom')->runtime;
$store = $r->store;
$permissions = new CommunicationPermissions($store);
$compose = 'intercom.type.compose';
$send = 'intercom.type.send';
$badge = static fn(array $preview, string $action, int $group): array => $preview['jform_rules_'.$action.'_'.$group];
$rules = [];
foreach ($store->rows('SELECT id FROM #__usergroups') as $group) {
    foreach ([$compose, $send] as $action) { $rules[$action][(int)$group['id']] = ''; }
}
$rules[$compose][2] = '1';
$rules[$send][3] = '0';
$input = ['type_key'=>'permission_regression', 'suppression'=>'permission-regression-optout', 'state'=>1,
    'translations'=>['en-GB'=>['name'=>'Permission regression'], 'da-DK'=>['name'=>'Tilladelsestest']], 'rules'=>$rules];
$store->begin();
try {
    $before = [$store->rows('SELECT id,rules FROM #__assets ORDER BY id'),
        $store->rows('SELECT id,revision FROM #__intercom_types ORDER BY id'), $store->row('SELECT COUNT(*) n FROM #__intercom_audit')];
    $parentPermission = Access::checkGroup(2, $compose, 'com_intercom');
    $preview = $permissions->preview($input);
    check($badge($preview,$compose,2)['text']===Text::_('JLIB_RULES_ALLOWED'), 'Unsaved Registered grant calculates Allowed for a new record');
    check($badge($preview,$compose,3)['text']===Text::_('JLIB_RULES_ALLOWED_INHERITED'), 'Unsaved grant updates descendant calculated settings');
    check($badge($preview,$send,3)['text']===Text::_('JLIB_RULES_NOT_ALLOWED'), 'Explicit sending denial remains separate from composition');
    check($badge($preview,$compose,8)['text']===Text::_('JLIB_RULES_ALLOWED_ADMIN'), 'Preview preserves the native Super User exception');
    check(Access::checkGroup(2,$compose,'com_intercom')===$parentPermission, 'Preview does not mutate cached parent ACL rules');
    check($before===[$store->rows('SELECT id,rules FROM #__assets ORDER BY id'),
        $store->rows('SELECT id,revision FROM #__intercom_types ORDER BY id'), $store->row('SELECT COUNT(*) n FROM #__intercom_audit')], 'Preview creates no assets, revisions or audit writes');
    $id = $r->catalog->saveType($input, 42);
    $assetName = 'com_intercom.communication.'.$id;
    $asset = $store->row('SELECT * FROM #__assets WHERE name='.$store->q($assetName));
    $saved = json_decode($asset['rules'],true);
    check(!array_key_exists(1,$saved[$compose]) && !array_key_exists(1,$saved[$send]), 'Browser Inherited fields do not become explicit Public denials');
    check(Access::checkGroup(2,$compose,$assetName)===true && Access::checkGroup(3,$compose,$assetName)===true, 'Saved allow survives inherited Public and reaches child groups');
    check(Access::checkGroup(3,$send,$assetName)===false && $saved[$send][3]===0, 'Intentional Denied survives normalization and saving');
    $input['id'] = $id; $input['revision'] = 1;
    $rules[$compose][1] = '0';
    $input['rules'] = $rules;
    $preview = $permissions->preview($input);
    check($badge($preview,$compose,2)['locked'] && $badge($preview,$compose,2)['text']===Text::_('JLIB_RULES_NOT_ALLOWED_LOCKED'), 'Staged ancestor denial locks a descendant Allowed selection');
    check(Access::checkGroup(2,$compose,$assetName)===true && $store->row('SELECT rules FROM #__assets WHERE id='.(int)$asset['id'])['rules']===$asset['rules'], 'Locked preview does not change persisted access');
    $r->catalog->saveType($input,42);
    check(Access::checkGroup(2,$compose,$assetName)===false, 'A saved explicit ancestor denial still overrides Allowed');
    $input['revision'] = 2; $input['rules'][$compose][1] = '';
    $preview = $permissions->preview($input);
    check(!$badge($preview,$compose,2)['locked'] && $badge($preview,$compose,2)['text']===Text::_('JLIB_RULES_ALLOWED'), 'Changing ancestor Denied back to Inherited unlocks the preview');
    $r->catalog->saveType($input,42);
    check(Access::checkGroup(2,$compose,$assetName)===true, 'Saving Inherited removes an existing ancestor denial');
    $input['revision'] = 3;
    $component = $store->row("SELECT id,rules FROM #__assets WHERE name='com_intercom'");
    $componentRules = json_decode($component['rules'],true);
    $componentRules[$compose][2] = 0;
    $store->execute('UPDATE #__assets SET rules='.$store->q(json_encode($componentRules)).' WHERE id='.(int)$component['id']);
    Access::clearStatics();
    $preview = $permissions->preview($input);
    check($badge($preview,$compose,2)['locked'] && $badge($preview,$compose,2)['text']===Text::_('JLIB_RULES_NOT_ALLOWED_LOCKED'), 'Preview preserves explicit parent asset denials');
    foreach ([['id'=>$id,'revision'=>2], ['id'=>999999999,'revision'=>1]] as $stale) {
        try { $permissions->preview(array_merge($input,$stale)); throw new Exception('Expected stale revision'); }
        catch (RuntimeException $e) { check($e->getCode()===409,'Missing or stale records reject permission previews'); }
    }
    foreach ([['core.admin'=>[2=>'1']], [$compose=>[999999999=>'1']], [$compose=>[2=>'invalid']], [$compose=>[2=>[]]]] as $invalid) {
        try { $permissions->preview(array_merge($input,['rules'=>$invalid])); throw new Exception('Expected invalid permissions'); }
        catch (RuntimeException $e) { check($e->getCode()===422,'Preview rejects unknown actions/groups and malformed rule values'); }
    }
    check(!$store->row("SELECT id FROM #__assets WHERE name LIKE 'com_intercom.settings.%'"), 'Record permission work never creates a settings-view asset');
    $audit = $store->row("SELECT context FROM #__intercom_audit WHERE event='communication.updated' ORDER BY id DESC LIMIT 1");
    $metadata = json_decode($audit['context'],true);
    check(isset($metadata['permissions_before'][$compose][1]) && !isset($metadata['permissions_after'][$compose][1]), 'Removal of an ancestor denial is recorded in the audited save');
} finally { $store->rollback(); Access::clearStatics(); }

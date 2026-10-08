<?php

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Editor\Editor;
use Joomla\CMS\Editor\EditorsRegistry;
use Joomla\CMS\Plugin\PluginHelper;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\EmailDesign;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$app = Factory::getApplication();
$user = $app->getIdentity();
$r = $this->runtime;
$policy = $r->policy($user);
$design = $r->design->snapshot()['settings'];
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$wa = $app->getDocument()->getWebAssetManager();
$wa->useStyle('com_intercom.app')->useScript('com_intercom.app')->usePreset('choicesjs')->useScript('webcomponent.field-fancy-select');
PluginHelper::importPlugin('editors');
$editors = Factory::getContainer()->get(EditorsRegistry::class);
$editors->initRegistry();
$editorName = (string) ($user->getParam('editor') ?: $app->get('editor', 'tinymce'));
if (!$editors->has($editorName) && !PluginHelper::isEnabled('editors', $editorName)) {
    $editorName = 'none';
}
// Joomla's editor facade also supports enabled plugins using legacy onDisplay, including JCE.
$editor = Editor::getInstance($editorName);
foreach (['AGES','FEMALE','MALE','SAVED','TESTED','SUBMITTED','DIRTY','ERROR','CONFIRM_SEND','INVALID_MESSAGE','FAKE_TESTED','FAKE_SUBMITTED','ALL_AUDIENCE','NO_GROUPS','GROUP_REQUIRED','PREVIEW_UPDATING','PREVIEW_UPDATED','PREVIEW_OUTDATED','CONFIRM_DELETE','DELETED','RESTORED','ESTIMATE','ESTIMATE_CHECKED','ESTIMATE_STALE','ESTIMATE_UNAVAILABLE','ESTIMATE_LOADING','ESTIMATE_SIMULATED','COUNT_CHANGED','NO_RECIPIENTS','AUDIENCE_CHANGED','LOCAL_RECOVERED','LOCAL_SAVED','LOCAL_UNAVAILABLE','DRAFT_READONLY','TEST_REQUIRED','RELEASE_NOT_VERIFIED'] as $key) {
    Text::script('COM_INTERCOM_' . $key);
}
$id = $app->input->getInt('id');
$draft = $id ? $r->store->draft($id, (int) $user->id, false, true) : null;
if ($draft && $draft['state'] === 'deleted' && $draft['content'] === '{}') {
    throw new \RuntimeException('COM_INTERCOM_DRAFT_NOT_FOUND', 404);
}
$content = $draft ? json_decode($draft['content'], true) : [];
$definitions = $r->catalog->types();
$typeOptions = [];
foreach ($definitions as $key => $definition) {
    $typeOptions[$key] = ['requireGroup' => $definition['require_group'], 'prefixes' => ['da' => Message::translation($definition, 'da-DK')['subject_prefix'], 'en' => Message::translation($definition, 'en-GB')['subject_prefix']]];
}
$initial = ['composerUrl' => Route::_('index.php?option=com_intercom&view=composer&Itemid=' . $app->input->getInt('Itemid'), false), 'types' => $typeOptions, 'language' => str_starts_with($app->getLanguage()->getTag(), 'da') ? 'da' : 'en', 'allAudience' => $policy->scope()['all'], 'draft' => $draft, 'message' => $content, 'simulation' => ($r->config['mode'] ?? 'fake') === 'fake'];

$tags = $disciplines = $memberships = $tagLabels = [];
foreach ($r->catalog->tags() as $row) {
    $tagLabels[$row['tag']] = \FKT\Component\Intercom\Administrator\Domain\TagLabel::display($row, $app->getLanguage()->getTag());
    if (str_starts_with($row['tag'], 'group.') || str_starts_with($row['tag'], 'discipline.')) {
        if ($policy->scope()['all'] || in_array($row['tag'], $policy->scope()['tags'], true)) {
            if (str_starts_with($row['tag'], 'discipline.')) {
                $disciplines[] = $row['tag'];
            } else {
                $tags[] = $row['tag'];
            }
        }
    } elseif (str_starts_with($row['tag'], 'membership.')) {
        $memberships[] = $row['tag'];
    }
}
$initial['newUrl'] = Route::_('index.php?option=com_intercom&view=composer&new=1&Itemid=' . $app->input->getInt('Itemid'), false);
$initial['fresh'] = !$id && $app->input->getBool('new');
$initial['editorBodies'] = ['da' => !empty($content['body_da']) ? Message::bodyHtml($content, 'da') : '', 'en' => !empty($content['body_en']) ? Message::bodyHtml($content, 'en') : ''];
$initial['availableTeams'] = array_merge($tags, $disciplines);
$initial['locale'] = $app->getLanguage()->getTag();
$initial['timezone'] = $user->getParam('timezone', $app->get('offset', 'UTC'));
$initial['cacheContext'] = (int) $user->id . '.' . hash('sha256', ($r->config['mode'] ?? 'fake') . ':' . ($r->config['group_id'] ?? 0) . ':' . ($r->connection->accountId()));
$initial['retentionDays'] = (int) ($r->config['retention_days'] ?? 30);
$initial['releaseApproved'] = $initial['simulation'] || (new \FKT\Component\Intercom\Administrator\Service\ReleaseApproval($r->store))->valid();
$initial['estimateMinutes'] = (int) ($r->config['estimate_cache_minutes'] ?? 5);
$app->getDocument()->addScriptOptions('com_intercom', $initial);
?>
<div class="intercom">
<header><p class="ic-eyebrow">INTERCOM</p><a class="ic-quiet" id="ic-new-message" href="<?= $esc($initial['newUrl']) ?>" <?= $draft ? '' : 'hidden' ?>><?= $t('NEW') ?> →</a><h1><?= $t('NEW') ?></h1><p><?= $t('INTRO') ?></p></header>
<?php if ($draft && !in_array($draft['state'], ['draft', 'tested'], true)) :
    ?><p class="ic-notice"><?= $t('DRAFT_READONLY') ?> <a href="<?= $esc($initial['newUrl']) ?>"><?= $t('NEW') ?></a></p><?php
endif; ?>
<p class="ic-notice"><?= $t('NOTICE') ?></p><p class="ic-mode"><?= $t(($r->config['mode'] ?? 'fake') === 'fake' ? 'FAKE' : 'LIVE') ?></p>
<div class="ic-layout"><form id="ic-form" action="<?= $esc(Route::_('index.php?option=com_intercom&format=json', false)) ?>" method="post">
<?= HTMLHelper::_('form.token') ?>
<nav class="ic-steps" aria-label="<?= $t('STEPS') ?>">
<?php foreach (['RECIPIENTS','CONTENT','TEST_SEND'] as $step => $label) : ?>
<button type="button" data-step="<?= $step ?>" aria-current="<?= $step === 0 ? 'step' : 'false' ?>"><span><?= $step + 1 ?></span><?= $t($label) ?></button>
<?php endforeach; ?></nav>
<fieldset data-panel="0"><legend><?= $t('RECIPIENTS') ?></legend>
<p class="ic-help"><?= $t('TYPE') ?></p><div class="ic-types">
<?php foreach ($policy->types() as $type) : ?>
<label class="ic-type"><input type="radio" name="type" value="<?= $esc($type) ?>" <?= $type === $policy->types()[0] ? 'checked' : '' ?>><span><strong><?= $esc(Message::translation($definitions[$type], $app->getLanguage()->getTag())['name']) ?></strong><small><?= $esc(Message::translation($definitions[$type], $app->getLanguage()->getTag())['description']) ?></small></span></label>
<?php endforeach; ?></div>
<p class="ic-help"><?= $t('GROUP_HELP') ?></p>
<?php foreach (['tags' => [$tags, 'GROUPS', 6], 'disciplines' => [$disciplines, 'DISCIPLINES', 11], 'memberships' => [$memberships, 'MEMBERSHIPS', 11]] as $field => [$choices, $label, $prefix]) : ?>
<label for="ic-<?= $field ?>"><?= $t($label) ?></label>
<joomla-field-fancy-select placeholder="<?= $esc($t('SELECT_TAGS')) ?>">
<select id="ic-<?= $field ?>" name="<?= $field ?>[]" multiple>
    <?php foreach (array_values(array_unique(array_merge($choices, $content[$field] ?? []))) as $tag) : ?>
<option value="<?= $esc($tag) ?>" <?= in_array($tag, $content[$field] ?? [], true) ? 'selected' : '' ?>><?= $esc($tagLabels[$tag] ?? $r->catalog->label($tag, $app->getLanguage()->getTag())) ?><?= in_array($tag, $choices, true) ? '' : ' (' . $esc($t('TAG_UNAVAILABLE')) . ')' ?></option>
    <?php endforeach; ?></select></joomla-field-fancy-select>
<?php endforeach; ?>
<p id="ic-group-error" class="ic-field-error" role="alert" hidden><?= $t('GROUP_REQUIRED') ?></p>
<p class="ic-help"><?= $t('TAG_MATCH_HELP') ?></p>
<details><summary><?= $t('MORE_FILTERS') ?></summary>
<p class="ic-help"><?= $t('MEMBER_AGE_HELP') ?></p>
<div class="ic-row"><label><?= $t('AGE_FROM') ?><input name="age_from" type="number" min="0" max="120" value="0"></label><label><?= $t('AGE_TO') ?><input name="age_to" type="number" min="0" max="120" value="0"></label><label><?= $t('GENDER') ?><select name="gender"><option value=""><?= $t('ALL') ?></option><option value="male"><?= $t('MALE') ?></option><option value="female"><?= $t('FEMALE') ?></option></select></label></div></details><div class="ic-actions"><span class="ic-help"><?= $t('SCOPE_NOTE') ?></span><button type="button" data-go="1"><?= $t('WRITE') ?> →</button></div></fieldset>
<div class="ic-draft-controls"><button class="ic-quiet" type="button" data-action="delete" disabled><?= $t('DELETE_DRAFT') ?></button><button class="ic-quiet" type="button" data-action="restore" hidden><?= $t('RESTORE_DRAFT') ?></button><p class="ic-help"><?= $t('DELETE_HELP') ?></p></div>
<fieldset data-panel="1"><legend><?= $t('CONTENT') ?></legend><div class="ic-estimate-inline"><p data-estimate-line role="status" aria-live="polite"></p><button type="button" class="ic-quiet" data-refresh-estimate><?= $t('REFRESH_ESTIMATE') ?></button></div><p class="ic-help"><?= $t('BOTH_LANGUAGES') ?></p><label><?= $t('SENDER') ?><input name="sender" required maxlength="255" value="<?= $esc($r->config['sender_name'] ?? 'Trekanten Fencing') ?>"></label>
<div class="ic-edit-langs" aria-label="<?= $t('CONTENT_LANGUAGE') ?>"><button type="button" data-edit-lang="da" aria-pressed="true">Dansk</button><button type="button" data-edit-lang="en" aria-pressed="false">English</button></div>
<?php foreach (['da','en'] as $lang) :
    ?><div data-language-panel="<?= $lang ?>">
<label><?= $t('SUBJECT_' . strtoupper($lang)) ?><input name="subject_<?= $lang ?>" required maxlength="255"></label>
<label for="body_<?= $lang ?>"><?= $t('BODY_' . strtoupper($lang)) ?></label>
    <?= $editor->display('body_' . $lang, $esc(!empty($content['body_' . $lang]) ? Message::bodyHtml($content, $lang) : ''), '100%', '340', 60, 20, false, 'body_' . $lang) ?>
<button type="button" class="ic-quiet ic-firstname" data-firstname="<?= $lang ?>"><?= $t('INSERT_FIRSTNAME') ?></button>
</div>
<?php endforeach; ?><p class="ic-help"><?= $t('TEXT_HELP') ?></p><div class="ic-actions"><button class="ic-quiet" type="button" data-go="0">← <?= $t('RECIPIENTS') ?></button><button type="button" data-action="save"><?= $t('SAVE') ?></button><button type="button" data-go="2"><?= $t('TO_TEST') ?> →</button></div></fieldset>
<fieldset data-panel="2"><legend><?= $t('TEST_SEND') ?></legend><div class="ic-estimate-inline"><p data-estimate-line role="status" aria-live="polite"></p><button type="button" class="ic-quiet" data-refresh-estimate><?= $t('REFRESH_ESTIMATE') ?></button></div><p class="ic-help"><?= $t('TEST_HELP') ?></p><div class="ic-test-box"><p><?= $esc($user->email) ?></p><button type="button" data-action="preview" disabled><?= $t('PREVIEW') ?></button></div><label class="ic-check"><input id="ic-confirm" type="checkbox" aria-describedby="ic-send-help" disabled> <?= $t('CONFIRM') ?></label><p id="ic-send-help" class="ic-help" role="status"></p><label><?= $t('SEND_AT') ?><input name="send_at" type="datetime-local"></label><div class="ic-actions"><button class="ic-quiet" type="button" data-go="1">← <?= $t('CONTENT') ?></button><button type="button" data-action="release" disabled><?= $t('RELEASE') ?></button><button type="button" data-action="cancel" disabled><?= $t('CANCEL') ?></button></div></fieldset><p id="ic-status" role="status" aria-live="polite"></p><p id="ic-local-status" class="ic-help" role="status" aria-live="polite"></p>
</form><aside>
<div class="ic-preview-top"><span class="ic-eyebrow"><?= $t('YOUR_MESSAGE') ?></span><div class="ic-preview-langs"><button type="button" data-preview-lang="da" aria-pressed="true">DA</button><button type="button" data-preview-lang="en" aria-pressed="false">EN</button></div></div>
<dl class="ic-envelope"><dt><?= $t('SENDER') ?></dt><dd id="ic-preview-sender"></dd><dt><?= $t('SUBJECT') ?></dt><dd id="ic-preview-subject"></dd></dl>
<div class="ic-preview-themes"><button type="button" data-preview-theme="light" aria-pressed="true"><?= $t('LIGHT_MODE') ?></button><button type="button" data-preview-theme="dark" aria-pressed="false"><?= $t('DARK_MODE') ?></button></div>
<p id="ic-preview-status" class="ic-help" role="status"></p>
<iframe id="ic-preview-frame" class="ic-preview-frame" sandbox="" referrerpolicy="no-referrer" title="<?= $esc($t('YOUR_MESSAGE')) ?>"></iframe>
<p class="ic-help"><?= $t('PREVIEW_NOTE') ?></p><p class="ic-help"><?= $t('DARK_PREVIEW_HELP') ?></p><div class="ic-audience"><strong><?= $t('RECIPIENTS') ?></strong><p id="ic-audience-summary"></p><p id="ic-estimate" role="status" aria-live="polite"></p><button type="button" class="ic-quiet" id="ic-estimate-refresh"><?= $t('REFRESH_ESTIMATE') ?></button><p class="ic-help"><?= $t('ESTIMATE_HELP') ?></p></div>
<details class="ic-draft-list"><summary><?= $t('DRAFTS') ?></summary><ul class="ic-drafts">
<?php foreach ($r->store->rows('SELECT id,state,revision FROM #__intercom_drafts WHERE owner_id=' . (int) $user->id . " AND state!='deleted' ORDER BY id DESC LIMIT 30") as $row) : ?>
<li><a href="<?= $esc(Route::_('index.php?option=com_intercom&id=' . (int) $row['id'])) ?>">#<?= (int) $row['id'] ?> · <?= $esc($t('STATE_' . strtoupper($row['state']))) ?></a></li>
<?php endforeach; ?></ul></details>
<details class="ic-draft-list"><summary><?= $t('DELETED_DRAFTS') ?></summary><p class="ic-help"><?= sprintf($t('RESTORE_HELP'), max(1, min(3650, (int) ($r->config['retention_days'] ?? 30)))) ?></p><ul class="ic-drafts">
<?php foreach ($r->store->rows('SELECT id,revision FROM #__intercom_drafts WHERE owner_id=' . (int) $user->id . " AND state='deleted' AND content!='{}' ORDER BY updated_at DESC LIMIT 30") as $row) : ?>
<li><a href="<?= $esc(Route::_('index.php?option=com_intercom&id=' . (int) $row['id'])) ?>">#<?= (int) $row['id'] ?> · <?= $t('STATE_DELETED') ?></a></li>
<?php endforeach; ?></ul></details></aside></div>
<dialog id="ic-delete-dialog" aria-labelledby="ic-delete-title"><form method="dialog">
<h2 id="ic-delete-title"><?= $t('DELETE_DRAFT') ?></h2><p><?= $t('CONFIRM_DELETE') ?></p>
<div class="ic-actions"><button class="ic-quiet" value="cancel" autofocus><?= $t('CANCEL_ACTION') ?></button><button value="delete"><?= $t('DELETE_DRAFT') ?></button></div>
</form></dialog></div>
<?php
// Limit TinyMCE to formatting supported by the email sanitiser, independent of the site editor preset.
$options = $app->getDocument()->getScriptOptions('plg_editor_tinymce');
if ($editorName === 'tinymce') {
    foreach (['body_da', 'body_en'] as $field) {
        $options['tinyMCE'][$field] = array_replace($options['tinyMCE'][$field] ?? [], [
            'joomlaMergeDefaults' => true, 'toolbar' => 'undo redo | blocks | bold italic underline | bullist numlist | link | removeformat',
            'block_formats' => 'Paragraph=p;Heading 1=h1;Heading 2=h2;Heading 3=h3',
            'content_style' => 'body {font-family:' . EmailDesign::FONTS[$design['body_font']] . ';font-size:' . $design['body_size'] . 'px;line-height:1.65} p {margin:0 0 16px} h1,h2,h3 {font-family:' . EmailDesign::FONTS[$design['heading_font']] . ';line-height:1.3}',
            'menubar' => false, 'branding' => false, 'resize' => false, 'toolbar_mode' => 'sliding',
        ]);
    }
    $app->getDocument()->addScriptOptions('plg_editor_tinymce', $options, false);
}
?>

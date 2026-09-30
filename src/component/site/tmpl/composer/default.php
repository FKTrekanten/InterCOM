<?php

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Editor\EditorsRegistry;
use Joomla\CMS\Plugin\PluginHelper;
use FKT\Component\Intercom\Administrator\Domain\Message;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$app = Factory::getApplication();
$user = $app->getIdentity();
$r = $this->runtime;
$policy = $r->policy($user);
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$wa = $app->getDocument()->getWebAssetManager();
$wa->useStyle('com_intercom.app')->useScript('com_intercom.app')->usePreset('choicesjs')->useScript('webcomponent.field-fancy-select');
PluginHelper::importPlugin('editors');
$editors = Factory::getContainer()->get(EditorsRegistry::class);
$editors->initRegistry();
$editorName = (string) $user->getParam('editor', $app->get('editor', 'tinymce'));
$editor = $editors->get($editors->has($editorName) ? $editorName : 'none');
foreach (['SAVED','TESTED','SUBMITTED','DIRTY','ERROR','CONFIRM_SEND','INVALID_MESSAGE','FAKE_TESTED','FAKE_SUBMITTED','ALL_AUDIENCE','NO_GROUPS'] as $key) {
    Text::script('COM_INTERCOM_' . $key);
}
$id = $app->input->getInt('id');
$draft = $id ? $r->store->draft($id, (int) $user->id) : null;
$content = $draft ? json_decode($draft['content'], true) : [];
$initial = ['allAudience' => $policy->scope()['all'], 'draft' => $draft, 'message' => $content, 'simulation' => ($r->config['mode'] ?? 'fake') === 'fake'];
$app->getDocument()->addScriptOptions('com_intercom', $initial);
$definitions = $r->catalog->types();
$tags = $memberships = [];
foreach ($r->catalog->tags() as $row) {
    if (str_starts_with($row['tag'], 'group.')) {
        if ($policy->scope()['all'] || in_array($row['tag'], $policy->scope()['tags'], true)) {
            $tags[] = $row['tag'];
        }
    } else {
        $memberships[] = $row['tag'];
    }
}
?>
<div class="intercom">
<header><p class="ic-eyebrow">INTERCOM</p><h1><?= $t('NEW') ?></h1><p><?= $t('INTRO') ?></p></header>
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
<?php foreach (['tags' => [$tags, 'GROUPS', 6], 'memberships' => [$memberships, 'MEMBERSHIPS', 11]] as $field => [$choices, $label, $prefix]) : ?>
<label for="ic-<?= $field ?>"><?= $t($label) ?></label>
<joomla-field-fancy-select allow-custom="false" placeholder="<?= $esc($t('SELECT_TAGS')) ?>">
<select id="ic-<?= $field ?>" name="<?= $field ?>[]" multiple>
    <?php foreach (array_values(array_unique(array_merge($choices, $content[$field] ?? []))) as $tag) : ?>
<option value="<?= $esc($tag) ?>" <?= in_array($tag, $content[$field] ?? [], true) ? 'selected' : '' ?>><?= $esc(substr($tag, $prefix)) ?><?= in_array($tag, $choices, true) ? '' : ' (' . $esc($t('TAG_UNAVAILABLE')) . ')' ?></option>
    <?php endforeach; ?></select></joomla-field-fancy-select>
<?php endforeach; ?>
<p class="ic-help"><?= $t('TAG_MATCH_HELP') ?></p>
<details><summary><?= $t('MORE_FILTERS') ?></summary>
<div class="ic-row"><label><?= $t('AGE_FROM') ?><input name="age_from" type="number" min="0" max="120" value="0"></label><label><?= $t('AGE_TO') ?><input name="age_to" type="number" min="0" max="120" value="0"></label><label><?= $t('GENDER') ?><select name="gender"><option value=""><?= $t('ALL') ?></option><option value="male"><?= $t('MALE') ?></option><option value="female"><?= $t('FEMALE') ?></option></select></label></div></details><div class="ic-actions"><span class="ic-help"><?= $t('SCOPE_NOTE') ?></span><button type="button" data-go="1"><?= $t('WRITE') ?> →</button></div></fieldset>
<fieldset data-panel="1"><legend><?= $t('CONTENT') ?></legend><p class="ic-help"><?= $t('BOTH_LANGUAGES') ?></p><label><?= $t('SENDER') ?><input name="sender" required maxlength="255" value="Fægteklubben Trekanten"></label>
<div class="ic-edit-langs" aria-label="<?= $t('CONTENT_LANGUAGE') ?>"><button type="button" data-edit-lang="da" aria-pressed="true">Dansk</button><button type="button" data-edit-lang="en" aria-pressed="false">English</button></div>
<?php foreach (['da','en'] as $lang) :
    ?><div data-language-panel="<?= $lang ?>">
<label><?= $t('SUBJECT_' . strtoupper($lang)) ?><input name="subject_<?= $lang ?>" required maxlength="255"></label>
<label for="body_<?= $lang ?>"><?= $t('BODY_' . strtoupper($lang)) ?></label>
    <?= $editor->display('body_' . $lang, !empty($content['body_' . $lang]) ? Message::bodyHtml($content, $lang) : '', ['id' => 'body_' . $lang, 'height' => '340', 'width' => '100%'], ['buttons' => false]) ?>
<button type="button" class="ic-quiet ic-firstname" data-firstname="<?= $lang ?>"><?= $t('INSERT_FIRSTNAME') ?></button>
</div>
<?php endforeach; ?><p class="ic-help"><?= $t('TEXT_HELP') ?></p><div class="ic-actions"><button class="ic-quiet" type="button" data-go="0">← <?= $t('RECIPIENTS') ?></button><button type="button" data-action="save"><?= $t('SAVE') ?></button><button type="button" data-go="2"><?= $t('TO_TEST') ?> →</button></div></fieldset>
<fieldset data-panel="2"><legend><?= $t('TEST_SEND') ?></legend><p class="ic-help"><?= $t('TEST_HELP') ?></p><div class="ic-test-box"><p><?= $esc($user->email) ?></p><button type="button" data-action="preview" disabled><?= $t('PREVIEW') ?></button></div><label class="ic-check"><input id="ic-confirm" type="checkbox" disabled> <?= $t('CONFIRM') ?></label><label><?= $t('SEND_AT') ?><input name="send_at" type="datetime-local"></label><div class="ic-actions"><button class="ic-quiet" type="button" data-go="1">← <?= $t('CONTENT') ?></button><button type="button" data-action="release" disabled><?= $t('RELEASE') ?></button><button type="button" data-action="cancel" disabled><?= $t('CANCEL') ?></button></div></fieldset><p id="ic-status" role="status" aria-live="polite"></p>
</form><aside>
<div class="ic-preview-top"><span class="ic-eyebrow"><?= $t('YOUR_MESSAGE') ?></span><div class="ic-preview-langs"><button type="button" data-preview-lang="da" aria-pressed="true">DA</button><button type="button" data-preview-lang="en" aria-pressed="false">EN</button></div></div>
<dl class="ic-envelope"><dt><?= $t('SENDER') ?></dt><dd id="ic-preview-sender"></dd><dt><?= $t('SUBJECT') ?></dt><dd id="ic-preview-subject"></dd></dl>
<iframe id="ic-preview-frame" class="ic-preview-frame" sandbox="" referrerpolicy="no-referrer" title="<?= $esc($t('YOUR_MESSAGE')) ?>"></iframe>
<p class="ic-help"><?= $t('PREVIEW_NOTE') ?></p><div class="ic-audience"><strong><?= $t('RECIPIENTS') ?></strong><p id="ic-audience-summary"></p></div>
<details class="ic-draft-list"><summary><?= $t('DRAFTS') ?></summary><h2><?= $t('DRAFTS') ?></h2><ul class="ic-drafts">
<?php foreach ($r->store->rows('SELECT id,state,revision FROM #__intercom_drafts WHERE owner_id=' . (int) $user->id . ' ORDER BY id DESC LIMIT 30') as $row) : ?>
<li><a href="<?= $esc(Route::_('index.php?option=com_intercom&id=' . (int) $row['id'])) ?>">#<?= (int) $row['id'] ?> · <?= $esc($t('STATE_' . strtoupper($row['state']))) ?></a></li>
<?php endforeach; ?></ul></details></aside></div></div>
<?php
// Limit TinyMCE to formatting supported by the email sanitiser, independent of the site editor preset.
$options = $app->getDocument()->getScriptOptions('plg_editor_tinymce');
if ($editor->getName() === 'tinymce') {
    foreach (['body_da', 'body_en'] as $field) {
        $options['tinyMCE'][$field] = array_replace($options['tinyMCE'][$field] ?? [], [
            'joomlaMergeDefaults' => true, 'toolbar' => 'undo redo | blocks | bold italic underline | bullist numlist | link | removeformat',
            'block_formats' => 'Paragraph=p;Heading 1=h1;Heading 2=h2;Heading 3=h3',
            'content_style' => 'body {font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65} p {margin:0 0 14px}',
            'menubar' => false, 'branding' => false, 'resize' => false, 'toolbar_mode' => 'sliding',
        ]);
    }
    $app->getDocument()->addScriptOptions('plg_editor_tinymce', $options, false);
}
?>

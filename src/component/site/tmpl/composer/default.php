<?php

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$app = Factory::getApplication();
$user = $app->getIdentity();
$r = $this->runtime;
$policy = $r->policy($user);
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$app->getDocument()->getWebAssetManager()->useStyle('com_intercom.app')->useScript('com_intercom.app');
foreach (['SAVED','TESTED','SUBMITTED','DIRTY','ERROR','CONFIRM_SEND','INVALID_MESSAGE','FAKE_TESTED','FAKE_SUBMITTED','ALL_AUDIENCE','NO_GROUPS'] as $key) {
    Text::script('COM_INTERCOM_' . $key);
}
$id = $app->input->getInt('id');
$draft = $id ? $r->store->draft($id, (int) $user->id) : null;
$content = $draft ? json_decode($draft['content'], true) : [];
$initial = ['allAudience' => $policy->scope()['all'], 'draft' => $draft, 'message' => $content, 'simulation' => ($r->config['mode'] ?? 'fake') === 'fake'];
$app->getDocument()->addScriptOptions('com_intercom', $initial);
try {
    $tags = $r->gateway()->tags('group');
    $memberships = $r->gateway()->tags('membership');
    if (!$policy->scope()['all']) {
        $tags = array_values(array_intersect($tags, $policy->scope()['tags']));
    }
} catch (\Throwable) {
    $tags = $memberships = [];
    $app->enqueueMessage($t('PROVIDER_ERROR'), 'warning');
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
<label class="ic-type"><input type="radio" name="type" value="<?= $type ?>" <?= $type === $policy->types()[0] ? 'checked' : '' ?>><span><strong><?= $t(strtoupper($type)) ?></strong><small><?= $t('TYPE_HELP_' . strtoupper($type)) ?></small></span></label>
<?php endforeach; ?></div>
<p class="ic-help"><?= $t('GROUP_HELP') ?></p><div class="ic-tags">
<?php foreach ($tags as $tag) :
    ?><label><input type="checkbox" name="tags[]" value="<?= $esc($tag) ?>"> <?= $esc(substr($tag, 6)) ?></label><?php
endforeach; ?></div>
<details><summary><?= $t('MEMBERSHIPS') ?></summary>
<?php foreach ($memberships as $tag) :
    ?><label class="ic-check"><input type="checkbox" name="memberships[]" value="<?= $esc($tag) ?>"> <?= $esc(substr($tag, 11)) ?></label><?php
endforeach; ?>
<div class="ic-row"><label><?= $t('AGE_FROM') ?><input name="age_from" type="number" min="0" max="120" value="0"></label><label><?= $t('AGE_TO') ?><input name="age_to" type="number" min="0" max="120" value="0"></label><label><?= $t('GENDER') ?><select name="gender"><option value=""><?= $t('ALL') ?></option><option value="male"><?= $t('MALE') ?></option><option value="female"><?= $t('FEMALE') ?></option></select></label></div></details><div class="ic-actions"><span class="ic-help"><?= $t('SCOPE_NOTE') ?></span><button type="button" data-go="1"><?= $t('WRITE') ?> →</button></div></fieldset>
<fieldset data-panel="1"><legend><?= $t('CONTENT') ?></legend><p class="ic-help"><?= $t('BOTH_LANGUAGES') ?></p><label><?= $t('SENDER') ?><input name="sender" required maxlength="255" value="Fægteklubben Trekanten"></label>
<div class="ic-edit-langs" aria-label="<?= $t('CONTENT_LANGUAGE') ?>"><button type="button" data-edit-lang="da" aria-pressed="true">Dansk</button><button type="button" data-edit-lang="en" aria-pressed="false">English</button></div>
<?php foreach (['da','en'] as $lang) :
    ?><div data-language-panel="<?= $lang ?>">
<label><?= $t('SUBJECT_' . strtoupper($lang)) ?><input name="subject_<?= $lang ?>" required maxlength="255"></label><label><?= $t('BODY_' . strtoupper($lang)) ?><textarea name="body_<?= $lang ?>" required maxlength="100000" rows="8"></textarea></label>
</div>
<?php endforeach; ?><p class="ic-help"><?= $t('TEXT_HELP') ?></p><div class="ic-actions"><button class="ic-quiet" type="button" data-go="0">← <?= $t('RECIPIENTS') ?></button><button type="button" data-action="save"><?= $t('SAVE') ?></button><button type="button" data-go="2"><?= $t('TO_TEST') ?> →</button></div></fieldset>
<fieldset data-panel="2"><legend><?= $t('TEST_SEND') ?></legend><p class="ic-help"><?= $t('TEST_HELP') ?></p><div class="ic-test-box"><p><?= $esc($user->email) ?></p><button type="button" data-action="preview" disabled><?= $t('PREVIEW') ?></button></div><label class="ic-check"><input id="ic-confirm" type="checkbox" disabled> <?= $t('CONFIRM') ?></label><label><?= $t('SEND_AT') ?><input name="send_at" type="datetime-local"></label><div class="ic-actions"><button class="ic-quiet" type="button" data-go="1">← <?= $t('CONTENT') ?></button><button type="button" data-action="release" disabled><?= $t('RELEASE') ?></button><button type="button" data-action="cancel" disabled><?= $t('CANCEL') ?></button></div></fieldset><p id="ic-status" role="status" aria-live="polite"></p>
</form><aside>
<div class="ic-preview-top"><span class="ic-eyebrow"><?= $t('YOUR_MESSAGE') ?></span><div class="ic-preview-langs"><button type="button" data-preview-lang="da" aria-pressed="true">DA</button><button type="button" data-preview-lang="en" aria-pressed="false">EN</button></div></div>
<dl class="ic-envelope"><dt><?= $t('SENDER') ?></dt><dd id="ic-preview-sender"></dd><dt><?= $t('SUBJECT') ?></dt><dd id="ic-preview-subject"></dd></dl>
<div class="ic-email"><div class="ic-email-head"><span>FÆGTEKLUBBEN TREKANTEN</span><strong id="ic-preview-type"></strong></div><div class="ic-email-body" id="ic-preview-body"></div><div class="ic-email-foot"><?= $t('PREVIEW_FOOTER') ?></div></div>
<p class="ic-help"><?= $t('PREVIEW_NOTE') ?></p><div class="ic-audience"><strong><?= $t('RECIPIENTS') ?></strong><p id="ic-audience-summary"></p></div>
<details class="ic-draft-list"><summary><?= $t('DRAFTS') ?></summary><h2><?= $t('DRAFTS') ?></h2><ul class="ic-drafts">
<?php foreach ($r->store->rows('SELECT id,state,revision FROM #__intercom_drafts WHERE owner_id=' . (int) $user->id . ' ORDER BY id DESC LIMIT 30') as $row) : ?>
<li><a href="<?= $esc(Route::_('index.php?option=com_intercom&id=' . (int) $row['id'])) ?>">#<?= (int) $row['id'] ?> · <?= $esc($t('STATE_' . strtoupper($row['state']))) ?></a></li>
<?php endforeach; ?></ul></details></aside></div></div>

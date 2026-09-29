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
foreach (['SAVED','TESTED','SUBMITTED','DIRTY','ERROR','CONFIRM_SEND','INVALID_MESSAGE','FAKE_TESTED','FAKE_SUBMITTED'] as $key) {
    Text::script('COM_INTERCOM_' . $key);
}
$id = $app->input->getInt('id');
$draft = $id ? $r->store->draft($id, (int) $user->id) : null;
$content = $draft ? json_decode($draft['content'], true) : [];
$initial = ['draft' => $draft, 'message' => $content, 'simulation' => ($r->config['mode'] ?? 'fake') === 'fake'];
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
<fieldset><legend>1 · <?= $t('RECIPIENTS') ?></legend><label><?= $t('TYPE') ?><select name="type">
<?php foreach ($policy->types() as $type) :
    ?><option value="<?= $type ?>"><?= $t(strtoupper($type)) ?></option><?php
endforeach; ?></select></label>
<p class="ic-help"><?= $t('GROUP_HELP') ?></p><div class="ic-tags">
<?php foreach ($tags as $tag) :
    ?><label><input type="checkbox" name="tags[]" value="<?= $esc($tag) ?>"> <?= $esc(substr($tag, 6)) ?></label><?php
endforeach; ?></div>
<details><summary><?= $t('MEMBERSHIPS') ?></summary>
<?php foreach ($memberships as $tag) :
    ?><label class="ic-check"><input type="checkbox" name="memberships[]" value="<?= $esc($tag) ?>"> <?= $esc(substr($tag, 11)) ?></label><?php
endforeach; ?>
<div class="ic-row"><label><?= $t('AGE_FROM') ?><input name="age_from" type="number" min="0" max="120" value="0"></label><label><?= $t('AGE_TO') ?><input name="age_to" type="number" min="0" max="120" value="0"></label><label><?= $t('GENDER') ?><select name="gender"><option value=""><?= $t('ALL') ?></option><option value="male"><?= $t('MALE') ?></option><option value="female"><?= $t('FEMALE') ?></option></select></label></div></details></fieldset>
<fieldset><legend>2 · <?= $t('CONTENT') ?></legend><label><?= $t('SENDER') ?><input name="sender" required maxlength="255" value="Fægteklubben Trekanten"></label>
<?php foreach (['da','en'] as $lang) : ?>
<label><?= $t('SUBJECT_' . strtoupper($lang)) ?><input name="subject_<?= $lang ?>" required maxlength="255"></label><label><?= $t('BODY_' . strtoupper($lang)) ?><textarea name="body_<?= $lang ?>" required maxlength="100000" rows="8"></textarea></label>
<?php endforeach; ?><p class="ic-help"><?= $t('TEXT_HELP') ?></p><button type="button" data-action="save"><?= $t('SAVE') ?></button></fieldset>
<fieldset><legend>3 · <?= $t('TEST_SEND') ?></legend><p><?= $esc($user->email) ?></p><button type="button" data-action="preview" disabled><?= $t('PREVIEW') ?></button><label class="ic-check"><input id="ic-confirm" type="checkbox" disabled> <?= $t('CONFIRM') ?></label><label><?= $t('SEND_AT') ?><input name="send_at" type="datetime-local"></label><button type="button" data-action="release" disabled><?= $t('RELEASE') ?></button><button type="button" data-action="cancel" disabled><?= $t('CANCEL') ?></button></fieldset><p id="ic-status" role="status" aria-live="polite"></p>
</form><aside><h2><?= $t('DRAFTS') ?></h2><ul class="ic-drafts">
<?php foreach ($r->store->rows('SELECT id,state,revision FROM #__intercom_drafts WHERE owner_id=' . (int) $user->id . ' ORDER BY id DESC LIMIT 30') as $row) : ?>
<li><a href="<?= $esc(Route::_('index.php?option=com_intercom&id=' . (int) $row['id'])) ?>">#<?= (int) $row['id'] ?> · <?= $esc($t('STATE_' . strtoupper($row['state']))) ?></a></li>
<?php endforeach; ?></ul><h2><?= $t('CONTENT') ?></h2><div class="ic-preview"><strong id="ic-preview-subject"></strong><p id="ic-preview-body"></p></div></aside></div></div>

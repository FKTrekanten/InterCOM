<?php

defined('_JEXEC') or die;

use FKT\Component\Intercom\Administrator\Domain\EmailDesign;
use FKT\Component\Intercom\Administrator\Domain\Message;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$snapshot = $r->design->snapshot();
$settings = $snapshot['settings'];
$sample = ['type' => 'club', 'sender' => $r->config['sender_name'] ?? 'Trekanten Fencing', 'format' => 'html',
    'body_da' => '<h2>Nyheder fra klubben</h2><p>Kære {FIRSTNAME[std:Medlem]}</p><p>Her kan du se, hvordan din besked og klubbens design ser ud sammen.</p>',
    'body_en' => '<h2>News from the club</h2><p>Hello {FIRSTNAME[std:Member]}</p><p>See how your message and the club design look together.</p>',
    'footer' => \FKT\Component\Intercom\Administrator\Domain\Footer::validate($r->config), 'tags' => [], 'design' => $snapshot, 'definition' => $r->catalog->types()['club'] ?? []];
$wa->useScript('com_intercom.design');
foreach (['PREVIEW_UPDATING', 'PREVIEW_UPDATED', 'ERROR'] as $key) {
    Text::script('COM_INTERCOM_' . $key);
}
?>
<h2><?= $t('EMAIL_DESIGN') ?></h2><p><?= $t('DESIGN_HELP') ?></p>
<div class="ic-design-layout">
<form id="ic-design-form" name="adminForm" action="index.php?option=com_intercom&amp;task=management.savedesign" method="post">
<?= HTMLHelper::_('form.token') ?><input type="hidden" name="jform[revision]" value="<?= (int) $snapshot['revision'] ?>">
<fieldset><legend><?= $t('BRANDING') ?></legend><div class="ic-design-fields">
<?php foreach (['brand_da','brand_en','logo_url'] as $key) : ?>
<label><?= $t('DESIGN_' . strtoupper($key)) ?><input class="form-control" name="jform[<?= $key ?>]" value="<?= $esc($settings[$key]) ?>" type="<?= $key === 'logo_url' ? 'url' : 'text' ?>" maxlength="<?= $key === 'logo_url' ? 1000 : 255 ?>" required></label>
<?php endforeach; ?></div></fieldset>
<fieldset><legend><?= $t('TYPOGRAPHY_LAYOUT') ?></legend><div class="ic-design-fields">
<?php foreach (['heading_font','body_font'] as $key) : ?>
<label><?= $t('DESIGN_' . strtoupper($key)) ?><select class="form-select" name="jform[<?= $key ?>]">
    <?php foreach (EmailDesign::FONTS as $font => $stack) :
        ?><option value="<?= $font ?>" <?= $settings[$key] === $font ? 'selected' : '' ?>><?= $t('FONT_' . strtoupper($font)) ?></option><?php
    endforeach; ?>
</select></label>
<?php endforeach; ?>
<?php foreach (['logo_width' => [40,160], 'content_width' => [480,800], 'padding' => [16,48], 'body_size' => [14,20], 'heading_size' => [22,40]] as $key => [$min, $max]) : ?>
<label><?= $t('DESIGN_' . strtoupper($key)) ?><input class="form-control" type="number" name="jform[<?= $key ?>]" value="<?= (int) $settings[$key] ?>" min="<?= $min ?>" max="<?= $max ?>" required></label>
<?php endforeach; ?></div></fieldset><p class="small text-muted"><?= $t('FONT_HELP') ?></p>
<?php foreach (['light','dark'] as $theme) : ?>
<fieldset><legend><?= $t(strtoupper($theme) . '_MODE') ?></legend><div class="ic-design-colours">
    <?php foreach (['outer','surface','footer','text','muted','header','header_text','accent','line'] as $role) :
        $key = $theme . '_' . $role; ?>
<label><?= $t('COLOUR_' . strtoupper($role)) ?><input type="color" class="form-control form-control-color" name="jform[<?= $key ?>]" value="<?= $esc($settings[$key]) ?>" required></label>
    <?php endforeach; ?></div></fieldset>
<?php endforeach; ?><p class="small text-muted"><?= $t('DARK_MODE_HELP') ?></p>
<div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary" type="submit"><?= $t('SAVE_CHANGES') ?></button>
<button type="submit" class="btn btn-secondary" formaction="index.php?option=com_intercom&amp;task=management.resetdesign" formnovalidate><?= $t('RESET_DESIGN') ?></button></div>
</form><aside class="ic-design-preview">
<div class="d-flex gap-2 flex-wrap mb-3"><label><?= $t('CONTENT_LANGUAGE') ?><select class="form-select" id="ic-design-language"><option value="da">Dansk</option><option value="en">English</option></select></label>
<label><?= $t('APPEARANCE') ?><select class="form-select" id="ic-design-theme"><option value="light"><?= $t('LIGHT_MODE') ?></option><option value="dark"><?= $t('DARK_MODE') ?></option></select></label></div>
<iframe id="ic-design-preview" title="<?= $esc($t('YOUR_MESSAGE')) ?>" sandbox="" referrerpolicy="no-referrer" srcdoc="<?= $esc(Message::html($sample, 'da-DK', 'light')) ?>"></iframe>
<p id="ic-design-preview-status" role="status" class="small mt-2"></p><p class="small text-muted"><?= $t('DARK_PREVIEW_HELP') ?></p>
</aside></div>

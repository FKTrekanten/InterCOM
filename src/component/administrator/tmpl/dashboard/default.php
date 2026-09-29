<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

ToolbarHelper::title(Text::_('COM_INTERCOM'), 'envelope');
ToolbarHelper::preferences('com_intercom');
$r = $this->runtime;
$user = Factory::getApplication()->getIdentity();
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$c = $r->config;
?>
<h2><?= $t('SETTINGS') ?></h2>
<p><?= $t('CREDENTIAL_HELP') ?></p>
<?php if ($user->authorise('core.admin', 'com_intercom')) : ?>
<form action="index.php?option=com_intercom&task=connection.save" method="post" class="row g-3">
    <?php foreach (['sender_email', 'group_id', 'unsubscribe_form_id', 'retention_days', 'filter_ids', 'client_id', 'client_secret'] as $field) : ?>
<div class="col-md-6"><label class="form-label" for="<?= $field ?>"><?= $t(strtoupper($field)) ?></label>
<input class="form-control" id="<?= $field ?>" name="<?= $field ?>" type="<?= str_starts_with($field, 'client_') ? 'password' : ($field === 'sender_email' ? 'email' : 'text') ?>" autocomplete="off" value="<?= $esc(str_starts_with($field, 'client_') ? '' : ($field === 'filter_ids' ? implode(',', array_column($r->store->rows('SELECT filter_id FROM #__intercom_filters ORDER BY filter_id'), 'filter_id')) : ($c[$field] ?? ($field === 'retention_days' ? 30 : '')))) ?>"></div>
    <?php endforeach; ?>
    <?php foreach (\FKT\Component\Intercom\Administrator\Domain\Policy::TYPES as $type) : ?>
<div class="col-md-4"><label class="form-label" for="category_<?= $type ?>"><?= $t('CATEGORY') . ': ' . $t(strtoupper($type)) ?></label><input class="form-control" id="category_<?= $type ?>" name="category_<?= $type ?>" type="number" min="0" value="<?= (int) ($c['categories'][$type] ?? 0) ?>"></div>
    <?php endforeach; ?>
<div class="col-md-6"><label class="form-label" for="mode"><?= $t('MODE') ?></label><select class="form-select" name="mode" id="mode"><option value="fake"><?= $t('FAKE') ?></option><option value="live" <?= ($c['mode'] ?? '') === 'live' ? 'selected' : '' ?>><?= $t('LIVE') ?></option></select></div>
<div class="col-12"><label class="form-label" for="audience_rules"><?= $t('AUDIENCE_RULES') ?></label><p><?= $t('RULES_HELP') ?></p><textarea class="form-control font-monospace" id="audience_rules" name="audience_rules" rows="5"><?= $esc($c['audience_rules'] ?? '[]') ?></textarea></div>
<div class="col-12"><label><input type="checkbox" name="release_verified" value="1" <?= !empty($c['release_verified']) ? 'checked' : '' ?>> <?= $t('RELEASE_VERIFIED') ?></label></div>
<div class="col-12"><button class="btn btn-primary" type="submit"><?= $t('SAVE_SETTINGS') ?></button></div><?= HTMLHelper::_('form.token') ?>
</form>
<form action="index.php?option=com_intercom&task=connection.connect" method="post" class="my-4"><button class="btn btn-outline-primary"><?= $t('CONNECT') ?></button><?= HTMLHelper::_('form.token') ?></form>
<p><?= $t('CALLBACK') ?>: <code><?= $esc(\Joomla\CMS\Uri\Uri::root() . 'administrator/index.php?option=com_intercom&task=connection.callback') ?></code></p>
<?php endif; ?>
<?php if ($user->authorise('intercom.audit', 'com_intercom')) : ?>
<h2 class="mt-4"><?= $t('AUDIT') ?></h2><p><?= $t('AUDIT_HELP') ?></p>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('DATE') ?> (UTC)</th><th><?= $t('ACTOR') ?></th><th><?= $t('EVENT') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('DETAILS') ?></th></tr></thead><tbody>
    <?php foreach ($r->store->rows('SELECT * FROM #__intercom_audit ORDER BY id DESC LIMIT 100') as $event) : ?>
<tr><td><?= $esc($event['created_at']) ?></td><td><?= (int) $event['actor_id'] ?></td><td><?= $esc($event['event']) ?></td><td><?= (int) $event['draft_id'] ?></td><td><?= $esc($event['context']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<h2><?= $t('RESERVATIONS') ?></h2><p><?= $t('RESERVATION_HELP') ?></p>
<div class="table-responsive"><table class="table"><tr><th><?= $t('FILTER_ID') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('STATE') ?></th></tr>
    <?php foreach ($r->store->rows('SELECT f.filter_id,f.draft_id,d.state FROM #__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id') as $row) : ?>
<tr><td><?= (int) $row['filter_id'] ?></td><td><?= (int) $row['draft_id'] ?></td><td><?= $esc(isset($row['state']) ? $t('STATE_' . strtoupper($row['state'])) : '-') ?></td></tr>
    <?php endforeach; ?></table></div>
<?php endif; ?>

<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

ToolbarHelper::title(Text::_('COM_INTERCOM'), 'envelope');
ToolbarHelper::preferences('com_intercom');
$r = $this->runtime;
$user = Factory::getApplication()->getIdentity();
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('core');
?>
<p><?= $t('OPTIONS_HELP') ?></p>
<?php if ($user->authorise('intercom.audit', 'com_intercom')) : ?>
<h2 class="mt-4"><?= $t('AUDIT') ?></h2><p><?= $t('AUDIT_HELP') ?></p>
<form action="index.php?option=com_intercom" method="get" id="adminForm" name="adminForm"><input type="hidden" name="option" value="com_intercom"><input type="hidden" name="task" value=""><input type="hidden" name="limitstart" value="<?= (int) $this->pagination->limitstart ?>"><label for="audit-limit"><?= $t('PER_PAGE') ?></label><select id="audit-limit" name="limit" onchange="this.form.limitstart.value=0;this.form.submit()"><option value="10" <?= $this->pagination->limit === 10 ? 'selected' : '' ?>>10</option><option value="20" <?= $this->pagination->limit === 20 ? 'selected' : '' ?>>20</option><option value="50" <?= $this->pagination->limit === 50 ? 'selected' : '' ?>>50</option><option value="100" <?= $this->pagination->limit === 100 ? 'selected' : '' ?>>100</option></select><div class="table-responsive"><table class="table"><thead><tr><th><?= $t('DATE') ?> (UTC)</th><th><?= $t('ACTOR') ?></th><th><?= $t('EVENT') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('DETAILS') ?></th></tr></thead><tbody>
    <?php foreach ($this->events as $event) : ?>
<tr data-audit-id="<?= (int) $event['id'] ?>"><td><?= $esc($event['created_at']) ?></td><td><?= (int) $event['actor_id'] ?></td><td><?= $esc($event['event']) ?></td><td><?= (int) $event['draft_id'] ?></td><td><?= $esc($event['context']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= $this->pagination->getPagesLinks() ?><p><?= $this->pagination->getResultsCounter() ?></p></form>
<h2><?= $t('RESERVATIONS') ?></h2><p><?= $t('RESERVATION_HELP') ?></p>
<div class="table-responsive"><table class="table"><tr><th><?= $t('FILTER_ID') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('STATE') ?></th><th><?= $t('FILTER_SCOPE') ?></th></tr>
    <?php foreach ($r->store->rows('SELECT f.filter_id,f.draft_id,f.group_id,f.managed,d.state FROM #__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id') as $row) : ?>
<tr><td><?= (int) $row['filter_id'] ?></td><td><?= (int) $row['draft_id'] ?></td><td><?= $esc(isset($row['state']) ? $t('STATE_' . strtoupper($row['state'])) : '-') ?></td><td><?= $t((int) $row['managed'] === 1 && (int) $row['group_id'] === (($r->config['mode'] ?? 'fake') === 'fake' ? 0 : (int) ($r->config['group_id'] ?? 0)) ? 'FILTER_MANAGED' : 'FILTER_HISTORICAL') ?></td></tr>
    <?php endforeach; ?></table></div>
<?php endif; ?>

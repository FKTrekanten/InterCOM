<?php

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

$r = $this->runtime;
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
ToolbarHelper::title($t('RESERVATIONS'), 'envelope');
ToolbarHelper::preferences('com_intercom');
require dirname(__DIR__) . '/navigation.php';
?>
<p><?= $t('RESERVATION_HELP') ?></p>
<form method="get" action="index.php" id="adminForm" name="adminForm">
<input type="hidden" name="option" value="com_intercom"><input type="hidden" name="view" value="filters"><input type="hidden" name="task" value=""><input type="hidden" name="limitstart" value="<?= (int) $this->pagination->limitstart ?>">
<div class="ic-admin-search">
<label><?= $t('FILTER_SCOPE') ?><select class="form-select" name="scope">
<?php foreach (['current' => 'FILTER_MANAGED', 'historical' => 'FILTER_HISTORICAL', 'all' => 'ALL'] as $value => $label) :
    ?><option value="<?= $value ?>" <?= $this->filters['scope'] === $value ? 'selected' : '' ?>><?= $t($label) ?></option><?php
endforeach; ?>
</select></label><label><?= $t('STATE') ?><select class="form-select" name="state">
<option value=""><?= $t('ALL') ?></option>
<?php foreach (['free','draft','tested','testing','releasing','submitted','scheduled','cancelled','uncertain'] as $state) :
    ?><option value="<?= $state ?>" <?= $this->filters['state'] === $state ? 'selected' : '' ?>><?= $t($state === 'free' ? 'FILTER_FREE' : 'STATE_' . strtoupper($state)) ?></option><?php
endforeach; ?>
</select></label>
<label><?= $t('ROWS_PER_PAGE') ?><select class="form-select" id="filters-limit" name="limit">
<?php foreach ([10,20,50,100] as $limit) :
    ?><option value="<?= $limit ?>" <?= $this->pagination->limit === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php
endforeach; ?>
</select></label><button class="btn btn-primary" type="submit" onclick="this.form.limitstart.value=0"><?= $t('APPLY_FILTERS') ?></button>
<a class="btn btn-secondary" href="index.php?option=com_intercom&amp;view=filters"><?= $t('RESET_FILTERS') ?></a></div>
<?php $filterRows = $this->rows;
require dirname(__DIR__) . '/filter-table.php'; ?>
<?= $this->pagination->getPagesLinks() ?><p><?= $this->pagination->getResultsCounter() ?></p></form>

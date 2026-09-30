<?php

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

$r = $this->runtime;
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
ToolbarHelper::title($t('AUDIT'), 'envelope');
ToolbarHelper::preferences('com_intercom');
require dirname(__DIR__) . '/navigation.php';
?>
<p><?= $t('AUDIT_HELP') ?></p>
<form method="get" action="index.php" id="adminForm" name="adminForm">
<input type="hidden" name="option" value="com_intercom"><input type="hidden" name="view" value="audit"><input type="hidden" name="task" value=""><input type="hidden" name="limitstart" value="<?= (int) $this->pagination->limitstart ?>">
<div class="ic-admin-search">
<?php foreach (['actor' => ['ACTOR', 'number'], 'event' => ['EVENT', 'text'], 'from' => ['DATE_FROM_UTC', 'date'], 'to' => ['DATE_TO_UTC', 'date']] as $key => [$label, $kind]) : ?>
<label><?= $t($label) ?><input class="form-control" type="<?= $kind ?>" name="<?= $key ?>" value="<?= $esc($this->filters[$key]) ?>"></label>
<?php endforeach; ?>
<label><?= $t('ROWS_PER_PAGE') ?><select class="form-select" id="audit-limit" name="limit">
<?php foreach ([10,20,50,100] as $limit) :
    ?><option value="<?= $limit ?>" <?= $this->pagination->limit === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php
endforeach; ?>
</select></label><button class="btn btn-primary" type="submit" onclick="this.form.limitstart.value=0"><?= $t('APPLY_FILTERS') ?></button>
<a class="btn btn-secondary" href="index.php?option=com_intercom&amp;view=audit"><?= $t('RESET_FILTERS') ?></a></div>
<?php $events = $this->rows;
require dirname(__DIR__) . '/audit-table.php'; ?>
<?= $this->pagination->getPagesLinks() ?><p><?= $this->pagination->getResultsCounter() ?></p></form>

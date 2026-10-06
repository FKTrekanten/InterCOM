<?php

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$esc = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ToolbarHelper::title($t('ABANDON_TITLE'), 'envelope');
require dirname(__DIR__) . '/navigation.php';
$row = $this->record;
$operation = $row['operation'];
$pending = $operation && in_array($operation['state'], ['inspecting', 'awaiting_removal', 'unverified'], true);
$scope = ($this->runtime->config['mode'] ?? '') === 'live' && (int) $row['managed'] === 1 && (int) $row['group_id'] === (int) ($this->runtime->config['group_id'] ?? 0);
$pending = $pending && $scope && (int) $row['draft_id'] === (int) $operation['draft_id'] && $row['draft_state'] === 'abandoning'
    && (int) $row['revision'] === (int) $operation['revision'] && (int) $row['lease_generation'] === (int) $operation['lease_generation'];
$historical = $operation && $operation['state'] === 'released' && !$row['draft_id'];
$eligible = $row['delivery_mode'] === 'live' && (int) $row['managed'] === 1 && (int) $row['group_id'] === (int) ($this->runtime->config['group_id'] ?? 0)
    && ($this->runtime->config['mode'] ?? '') === 'live' && (int) $row['mailing_id'] > 0
    && in_array($row['draft_state'], ['draft', 'tested', 'cancelled', 'deleted'], true);
?>
<p><a href="index.php?option=com_intercom&amp;view=filters">← <?= $t('RESERVATIONS') ?></a></p>
<p><?= $t('ABANDON_HELP') ?></p>
<dl class="ic-history-meta">
<dt><?= $t('FILTER_ID') ?></dt><dd><?= (int) $row['filter_id'] ?></dd>
<dt><?= $t('DRAFT') ?></dt><dd><?= (int) ($pending || $historical ? $operation['draft_id'] : $row['draft_id']) ?></dd>
<dt><?= $t('MAILING') ?></dt><dd><?= (int) ($pending || $historical ? $operation['mailing_id'] : $row['mailing_id']) ?></dd>
<dt><?= $t('RECIPIENT_LIST') ?></dt><dd><?= (int) $row['group_id'] ?></dd>
</dl>
<?php if (($pending && $operation['state'] === 'unverified') || ($operation && $operation['state'] === 'cancelled')) : ?>
<p class="alert alert-warning"><?= $t('ABANDON_UNVERIFIED') ?></p>
<?php endif; ?>
<?php if ($pending && (int) $operation['baseline_verified'] === 1) : ?>
<p class="alert alert-info"><?= $t('ABANDON_MANUAL_HELP') ?></p>
<p><?= $t('ABANDON_REASON') ?>: <?= $esc($operation['reason']) ?></p>
<form method="post" action="index.php?option=com_intercom">
<input type="hidden" name="task" value="abandonment.verify"><input type="hidden" name="filter_id" value="<?= (int) $row['filter_id'] ?>"><input type="hidden" name="operation_id" value="<?= (int) $operation['id'] ?>"><input type="hidden" name="confirmed" value="1">
    <?= HTMLHelper::_('form.token') ?>
<label class="d-block mb-3"><input type="checkbox" name="permanently_removed" value="1" required> <?= $t('ABANDON_PERMANENT_CONFIRM') ?></label>
<button class="btn btn-danger" type="submit"><?= $t('ABANDON_VERIFY') ?></button>
</form>
<?php elseif ($eligible || $pending) : ?>
<form method="post" action="index.php?option=com_intercom">
<input type="hidden" name="task" value="abandonment.inspect"><input type="hidden" name="filter_id" value="<?= (int) $row['filter_id'] ?>"><input type="hidden" name="revision" value="<?= (int) $row['revision'] ?>"><input type="hidden" name="generation" value="<?= (int) $row['lease_generation'] ?>">
    <?= HTMLHelper::_('form.token') ?>
<label class="d-block mb-3"><?= $t('ABANDON_REASON') ?><textarea class="form-control" name="reason" maxlength="500" required><?= $esc($pending ? $operation['reason'] : '') ?></textarea></label>
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> <?= $t('ABANDON_BEGIN_CONFIRM') ?></label>
<button class="btn btn-warning" type="submit"><?= $t('ABANDON_INSPECT') ?></button>
</form>
<?php elseif ($operation && $operation['state'] === 'released' && !$row['draft_id']) : ?>
<p class="alert alert-success"><?= $t('ABANDON_RELEASED') ?></p>
<?php else : ?>
<p class="alert alert-warning"><?= $t('ABANDON_UNSAFE') ?></p>
<?php endif; ?>

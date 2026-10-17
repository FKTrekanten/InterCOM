<?php

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$esc = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
ToolbarHelper::title($t('CLEANUP_TITLE'), 'envelope');
require dirname(__DIR__) . '/navigation.php';
?>
<p><?= $t('CLEANUP_HELP') ?></p>
<?php if ($this->error !== '') : ?>
<p class="alert alert-warning"><?= $esc(Text::_($this->error)) ?></p>
<?php elseif (!$this->preview['filters']) : ?>
<p class="alert alert-info"><?= $t('CLEANUP_EMPTY') ?></p>
<?php else : ?>
<p><?= $t('CLEANUP_ACCOUNT') ?>: <?= $esc($this->preview['account']) ?></p>
<p><?= $t('PROVIDER_REFERENCES') ?>: <?= $t('RECIPIENT_LIST') ?> <?= (int) $this->preview['group'] ?></p>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('FILTER') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('MAILING') ?></th><th><?= $t('STATE') ?></th></tr></thead><tbody>
    <?php foreach ($this->preview['filters'] as $filter) : ?>
<tr><td><?= (int) $filter['filter_id'] ?></td><td><?= (int) ($filter['draft_id'] ?? 0) ?: '—' ?></td><td><?= (int) ($filter['mailing_id'] ?? 0) ?: '—' ?></td><td><?= isset($filter['close_state']) ? $t('STATE_' . strtoupper($filter['draft_state'])) . ' → ' . $t('STATE_' . strtoupper($filter['close_state'])) : $t('FILTER_FREE') ?> → <?= $t('RECONCILIATION_RETIRED') ?></td></tr>
    <?php endforeach; ?>
</tbody></table></div>
<p class="alert alert-warning"><?= $t('CLEANUP_OUTCOME_HELP') ?></p>
<form method="post" action="index.php?option=com_intercom&amp;task=poolcleanup.apply">
<input type="hidden" name="fingerprint" value="<?= $esc($this->preview['fingerprint']) ?>">
    <?= HTMLHelper::_('form.token') ?>
<label class="d-block mb-3"><?= $t('ABANDON_REASON') ?><input class="form-control" name="reason" maxlength="500" required></label>
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> <?= $t('CLEANUP_ATTEST') ?></label>
<button class="btn btn-warning" type="submit"><?= $t('CLEANUP_APPLY') ?></button>
</form>
<?php endif; ?>
<a class="btn btn-secondary mt-3" href="index.php?option=com_intercom&amp;view=filters"><?= $t('CANCEL_ACTION') ?></a>

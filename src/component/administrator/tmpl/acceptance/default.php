<?php
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;
ToolbarHelper::title(Text::_('COM_INTERCOM_ACCEPTANCE'), 'check');
ToolbarHelper::preferences('com_intercom');
$t = static fn ($k) => Text::_('COM_INTERCOM_' . $k);
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
require dirname(__DIR__) . '/navigation.php';
$p = $this->proof;
?>
<div class="alert <?= $this->approved ? 'alert-success' : 'alert-warning' ?>"><?= $t($this->approved ? 'ACCEPTANCE_VALID' : 'RELEASE_NOT_VERIFIED') ?></div>
<p><?= $t('ACCEPTANCE_HELP') ?></p>
<p><?= $t('ACCEPTANCE_RECIPIENT_LABEL') ?>: <strong><?= $esc($this->runtime->config['acceptance_recipient'] ?? '') ?></strong></p>
<p><a href="index.php?option=com_config&amp;view=component&amp;component=com_intercom"><?= Text::_('JOPTIONS') ?></a></p>
<?php if (($this->runtime->config['mode'] ?? 'fake') === 'live') : ?>
    <?php if ($p) :
        ?><p><?= $t('ACCEPTANCE_TEST') ?> #<?= (int) $p['draft_id'] ?> · <?= $esc($t($p['state'] === 'verified' && !$this->proofCurrent ? 'ACCEPTANCE_STATE_OUTDATED' : 'ACCEPTANCE_STATE_' . strtoupper($p['state']))) ?></p><?php
    endif; ?>
    <?php if ($p && $p['state'] === 'verified' && !$this->proofCurrent) : ?>
<p class="alert alert-warning"><?= $t('ACCEPTANCE_OUTDATED_HELP') ?></p>
    <?php endif; ?>
    <?php if (!$p || !in_array($p['state'], ['preparing','prepared','checking','submitted','uncertain'], true)) : ?>
<form method="post" action="index.php?option=com_intercom">
<input type="hidden" name="task" value="acceptance.prepare"><?= HTMLHelper::_('form.token') ?>
<button class="btn btn-primary" type="submit"><?= $t('ACCEPTANCE_PREPARE') ?></button>
</form>
    <?php elseif ($p['state'] === 'prepared') : ?>
<p><?= $t('ACCEPTANCE_READY_HELP') ?></p>
<form method="post" action="index.php?option=com_intercom">
<input type="hidden" name="task" value="acceptance.release"><input type="hidden" name="id" value="<?= (int) $p['draft_id'] ?>"><input type="hidden" name="revision" value="<?= (int) $p['revision'] ?>">
        <?= HTMLHelper::_('form.token') ?>
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> <?= $t('ACCEPTANCE_RELEASE_CONFIRM') ?></label>
<button class="btn btn-primary" type="submit"><?= $t('ACCEPTANCE_SEND_ONE') ?></button>
</form>
<form class="mt-3" method="post" action="index.php?option=com_intercom">
<input type="hidden" name="task" value="acceptance.retire"><input type="hidden" name="id" value="<?= (int) $p['draft_id'] ?>"><input type="hidden" name="revision" value="<?= (int) $p['revision'] ?>"><input type="hidden" name="confirmed" value="1"><?= HTMLHelper::_('form.token') ?>
<button class="btn btn-outline-secondary" type="submit"><?= $t('ACCEPTANCE_RETIRE') ?></button>
<p class="small text-muted"><?= $t('ACCEPTANCE_RETIRE_HELP') ?></p>
</form>
    <?php elseif (in_array($p['state'], ['submitted', 'uncertain', 'checking'], true)) : ?>
<p><?= $t('ACCEPTANCE_RECEIVED_HELP') ?></p>
<form method="post" action="index.php?option=com_intercom">
<input type="hidden" name="task" value="acceptance.verify"><input type="hidden" name="id" value="<?= (int) $p['draft_id'] ?>"><?= HTMLHelper::_('form.token') ?>
<label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> <?= $t('ACCEPTANCE_RECEIVED_CONFIRM') ?></label>
<button class="btn btn-primary" type="submit"><?= $t('ACCEPTANCE_VERIFY') ?></button>
</form>
    <?php else :
        ?><p><?= $t('ACCEPTANCE_UNCERTAIN_HELP') ?></p><?php
    endif; ?>
<?php else :
    ?><p><?= $t('ACCEPTANCE_LIVE_HELP') ?></p><?php
endif; ?>

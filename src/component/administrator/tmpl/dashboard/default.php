<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

ToolbarHelper::title(Text::_('COM_INTERCOM_DASHBOARD'), 'envelope');
ToolbarHelper::preferences('com_intercom');
$r = $this->runtime;
$user = Factory::getApplication()->getIdentity();
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
require dirname(__DIR__) . '/navigation.php';
?>
<p><?= $t('OPTIONS_HELP') ?></p>
<p class="badge bg-secondary"><?= $t(($r->config['mode'] ?? 'fake') === 'fake' ? 'SIMULATION' : 'LIVE') ?> · <?= $t('RECIPIENT_LIST') ?> <?= $r->catalog->context() ?></p>
<?php if ($user->authorise('intercom.audit', 'com_intercom')) :
    $s = $this->statistics; ?>
<div class="ic-admin-stats">
<div><span><?= $t('POOL_CREATED') ?></span><strong><?= (int) $s['intents']['total'] ?> / <?= (int) $s['cap'] ?></strong><small><?= $t('POOL_CAP_HELP') ?></small></div>
<div><span><?= $t('FILTER_FREE') ?></span><strong><?= (int) $s['pool']['free'] ?></strong><small><?= $t('POOL_ACTIVE_HELP') ?></small></div>
<div><span><?= $t('POOL_RESERVED') ?></span><strong><?= (int) $s['pool']['total'] - (int) $s['pool']['free'] ?></strong><small><?= $t('POOL_ACTIVE_HELP') ?></small></div>
<div><span><?= $t('NEEDS_REVIEW') ?></span><strong><?= (int) $s['pool']['uncertain'] + (int) $s['intents']['uncertain'] ?></strong><small><?= $t('POOL_REVIEW_HELP') ?></small></div>
<div><span><?= $t('ACCEPTED_SUBMISSIONS') ?></span><strong><?= (int) $s['accepted'] ?></strong><small><?= Text::sprintf('COM_INTERCOM_LAST_DAYS', (int) $s['days']) ?></small></div>
</div><p class="small text-muted"><?= $t('ACCEPTED_HELP') ?></p>
<div class="ic-admin-summary"><section><h2><?= $t('DRAFT_STATES') ?></h2><dl class="ic-state-counts">
    <?php foreach ($s['drafts'] as $row) :
        ?><dt><?= $esc($t('STATE_' . strtoupper($row['state']))) ?></dt><dd><?= (int) $row['total'] ?></dd><?php
    endforeach; ?>
    <?php if (!$s['drafts']) :
        ?><dt><?= $t('NO_RESULTS') ?></dt><?php
    endif; ?>
</dl></section><section><h2><?= $t('MAINTENANCE') ?></h2>
<p><?= $t('LAST_REFRESH') ?>: <?= $s['refresh'] ? $esc(HTMLHelper::_('date', $s['refresh'], Text::_('DATE_FORMAT_LC6'))) : $t('NOT_YET') ?></p>
<p><?= $t('LAST_MAINTENANCE') ?>: <?= $s['maintenance'] ? $esc(HTMLHelper::_('date', $s['maintenance'], Text::_('DATE_FORMAT_LC6'))) : $t('NOT_YET') ?></p>
    <?php foreach ($s['archives'] as $row) :
        ?><p><?= $t('ARCHIVE_QUEUE') ?> · <?= $esc($t('ARCHIVE_' . strtoupper($row['state']))) ?>: <?= (int) $row['total'] ?></p><?php
    endforeach; ?>
</section></div>
<h2><?= $t('RECENT_FILTERS') ?></h2>
    <?php $filterRows = $this->filters;
    require dirname(__DIR__) . '/filter-table.php'; ?>
<p><a href="index.php?option=com_intercom&amp;view=filters"><?= $t('VIEW_ALL_FILTERS') ?> →</a></p>
<?php endif; ?>

<?php if ($user->authorise('intercom.history', 'com_intercom')) : ?>
<h2><?= $t('LATEST_SENT') ?></h2>
    <?php $messages = $this->messages;
    require dirname(__DIR__) . '/history-table.php'; ?>
<p><a href="index.php?option=com_intercom&amp;view=history"><?= $t('VIEW_ALL_HISTORY') ?> →</a></p>
<?php endif; ?>

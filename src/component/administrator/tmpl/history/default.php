<?php

defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;
use FKT\Component\Intercom\Administrator\Service\History;

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
ToolbarHelper::title($t('HISTORY'), 'envelope');
ToolbarHelper::preferences('com_intercom');
require dirname(__DIR__) . '/navigation.php';
?>
<p><?= $t('HISTORY_HELP') ?></p>
<?php if ($this->record) :
    $row = $this->record;
    $snapshot = $row['snapshot'] ? json_decode($row['snapshot'], true, 64, JSON_THROW_ON_ERROR) : [];
    $message = $snapshot['message'] ?? [];
    $date = static fn ($v) => $v ? $esc(HTMLHelper::_('date', $v, Text::_('DATE_FORMAT_LC6'))) : $t('NOT_RECORDED');
    ?>
<p><a href="index.php?option=com_intercom&amp;view=history">← <?= $t('VIEW_ALL_HISTORY') ?></a></p>
<h2>#<?= (int) $row['draft_id'] ?> · <?= $t('STATE_' . strtoupper($row['state'])) ?></h2>
    <?php if ($row['delivery_mode'] === 'fake') :
        ?><p class="alert alert-info"><?= $t('SIMULATION') ?></p><?php
    endif; ?>
<dl class="ic-history-meta">
<dt><?= $t('ACTOR') ?></dt><dd><?= $esc($row['actor_name'] ?: '#' . $row['actor_id']) ?></dd>
<dt><?= $t('SENDER') ?></dt><dd><?= $esc($message['sender'] ?? '—') ?> <?= $esc($snapshot['sender_email'] ?? '') ?></dd>
    <?php foreach (['requested_at' => 'REQUESTED_AT', 'started_at' => 'SENT_AT', 'finished_at' => 'COMPLETED_AT'] as $key => $label) : ?>
<dt><?= $t($label) ?></dt><dd><?= $date($row[$key]) ?></dd><?php
    endforeach; ?>
<dt><?= $t('SEND_AT') ?></dt><dd><?= $row['scheduled_at'] ? $date(gmdate('Y-m-d H:i:s', (int) $row['scheduled_at'])) : $t('NOT_RECORDED') ?></dd>
<dt><?= $t('ESTIMATE') ?></dt><dd><?= ($snapshot['submission_estimate'] ?? $snapshot['estimate'] ?? null) !== null ? (int) ($snapshot['submission_estimate'] ?? $snapshot['estimate'] ?? null) : $t('NOT_RECORDED') ?> · <?= $date($snapshot['submission_estimate_checked'] ?? $snapshot['estimate_checked'] ?? null) ?></dd>
<dt><?= $t('RECIPIENTS') ?></dt><dd><?php
foreach (array_merge($message['tags'] ?? [], $message['memberships'] ?? []) as $tag) : ?>
<span title="<?= $esc($tag) ?>"><?= $esc($message['tag_labels'][$tag][\Joomla\CMS\Factory::getApplication()->getLanguage()->getTag()] ?? \FKT\Component\Intercom\Administrator\Domain\TagLabel::automatic($tag)) ?></span><br>
<?php endforeach; ?>
    <?= $t('AGE_FROM') ?>: <?= (int) ($message['age_from'] ?? 0) ?> · <?= $t('AGE_TO') ?>: <?= (int) ($message['age_to'] ?? 0) ?> · <?= $t('GENDER') ?>: <?= $esc($message['gender'] ?? '') ?></dd>
<dt><?= $t('PROVIDER_REFERENCES') ?></dt><dd><?= $t('RECIPIENT_LIST') ?> <?= (int) $row['group_id'] ?> · <?= $t('FILTER') ?> <?= (int) $row['filter_id'] ?> · <?= $t('MAILING') ?> <?= (int) $row['mailing_id'] ?></dd>
</dl>
    <?php if (!$snapshot) :
        ?><p class="alert alert-info"><?= $t('CONTENT_EXPIRED') ?></p>
    <?php elseif ((int) $row['reconstructed']) :
        ?><p class="alert alert-info"><?= $t('HISTORY_RECONSTRUCTED') ?></p>
    <?php endif; ?>
    <?php if ($message) : ?>
<div class="ic-history-versions">
        <?php foreach (['da' => 'Dansk', 'en' => 'English'] as $language => $label) :
            $html = $snapshot[$language] ?? null;
            ?>
<section><h3><?= $label ?></h3><h4><?= $esc($message['subject_' . $language] ?? '') ?></h4>
            <?php if ($html) :
                ?><iframe sandbox="" referrerpolicy="no-referrer" title="<?= $esc($label) ?>" srcdoc="<?= $esc(History::preview($html)) ?>"></iframe>
            <?php else :
                ?><pre class="ic-history-text"><?= $esc($message['body_' . $language] ?? '') ?></pre><?php
            endif; ?>
</section>
        <?php endforeach; ?></div>
        <?php if (!empty($snapshot['text'])) :
            ?><details><summary><?= $t('PLAIN_TEXT') ?></summary><pre class="ic-history-text"><?= $esc($snapshot['text']) ?></pre></details><?php
        endif; ?>
    <?php endif; ?>
    <?php if (\Joomla\CMS\Factory::getApplication()->getIdentity()->authorise('intercom.audit', 'com_intercom')) :
        $abandonments = $this->runtime->store->rows('SELECT a.*,u.name actor_name FROM #__intercom_abandonments a LEFT JOIN #__users u ON u.id=a.verified_by WHERE a.draft_id=' . (int) $row['draft_id'] . " AND a.state='released' ORDER BY a.id DESC");
        foreach ($abandonments as $abandonment) : ?>
<section class="alert alert-info"><h3><?= $t('STATE_ABANDONED') ?></h3>
<p><?= $t('PROVIDER_REFERENCES') ?>: <?= $t('RECIPIENT_LIST') ?> <?= (int) $abandonment['group_id'] ?> · <?= $t('FILTER') ?> <?= (int) $abandonment['filter_id'] ?> · <?= $t('MAILING') ?> <?= (int) $abandonment['mailing_id'] ?></p>
<p><?= $t('ACTOR') ?>: <?= $esc($abandonment['actor_name'] ?: '#' . $abandonment['verified_by']) ?> · <?= $date($abandonment['verified_at']) ?></p>
            <?php if ($abandonment['reason'] !== '') : ?>
<p><?= $t('ABANDON_REASON') ?>: <?= $esc($abandonment['reason']) ?></p>
            <?php endif; ?></section>
        <?php endforeach;
        $events = $this->runtime->store->rows('SELECT * FROM #__intercom_audit WHERE draft_id=' . (int) $row['draft_id'] . ' ORDER BY id DESC LIMIT 50');
        require dirname(__DIR__) . '/audit-table.php';
    endif; ?>
<?php else : ?>
<form method="get" action="index.php" id="adminForm" name="adminForm">
<input type="hidden" name="option" value="com_intercom"><input type="hidden" name="view" value="history"><input type="hidden" name="task" value=""><input type="hidden" name="limitstart" value="<?= (int) $this->pagination->limitstart ?>">
<div class="ic-admin-search">
    <?php foreach (['actor' => ['ACTOR', 'number'], 'type_key' => ['TYPE', 'text'], 'from' => ['DATE_FROM_UTC', 'date'], 'to' => ['DATE_TO_UTC', 'date']] as $key => [$label, $kind]) : ?>
<label><?= $t($label) ?><input class="form-control" type="<?= $kind ?>" name="<?= $key ?>" value="<?= $esc($this->filters[$key]) ?>"></label>
    <?php endforeach; ?>
<label><?= $t('STATE') ?><select class="form-select" name="state"><option value=""><?= $t('ALL') ?></option>
    <?php foreach (['releasing','submitted','scheduled','completed','uncertain','abandoned'] as $state) :
        ?><option value="<?= $state ?>" <?= $this->filters['state'] === $state ? 'selected' : '' ?>><?= $t('STATE_' . strtoupper($state)) ?></option><?php
    endforeach; ?>
</select></label>
<label><?= $t('ROWS_PER_PAGE') ?><select class="form-select" name="limit" id="history-limit">
    <?php foreach ([10,20,50,100] as $limit) :
        ?><option value="<?= $limit ?>" <?= $this->pagination->limit === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php
    endforeach; ?>
</select></label><button class="btn btn-primary" type="submit" onclick="this.form.limitstart.value=0"><?= $t('APPLY_FILTERS') ?></button>
<a class="btn btn-secondary" href="index.php?option=com_intercom&amp;view=history"><?= $t('RESET_FILTERS') ?></a></div>
    <?php $messages = $this->rows;
    require dirname(__DIR__) . '/history-table.php'; ?>
    <?= $this->pagination->getPagesLinks() ?><p><?= $this->pagination->getResultsCounter() ?></p></form>
<?php endif; ?>

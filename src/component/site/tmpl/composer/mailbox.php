<?php

defined('_JEXEC') or die;
use FKT\Component\Intercom\Administrator\Domain\Audience;
use FKT\Component\Intercom\Administrator\Service\Mailbox;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

?>
<section id="ic-mailbox" class="ic-mailbox" aria-label="<?= $t('YOUR_EMAILS') ?>">
<h2><?= $t('YOUR_EMAILS') ?></h2>
<nav class="ic-mail-tabs" aria-label="<?= $t('YOUR_EMAILS') ?>">
<?php foreach (Mailbox::VIEWS as $box => $states) : ?>
<a href="<?= $esc($mailUrl($box)) ?>" <?= $box === $mailPage['view'] ? 'aria-current="page"' : '' ?>><?= $t('BOX_' . strtoupper($box)) ?> <span><?= $mailPage['counts'][$box] ?></span></a>
<?php endforeach; ?></nav>
<form method="get" class="ic-mail-search">
<input type="hidden" name="option" value="com_intercom"><input type="hidden" name="view" value="composer"><input type="hidden" name="Itemid" value="<?= $app->input->getInt('Itemid') ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="box" value="<?= $esc($mailPage['view']) ?>">
<label><?= $t('SEARCH_EMAILS') ?><input name="mail_search" maxlength="100" value="<?= $esc($app->input->getString('mail_search', '')) ?>"></label>
<label><?= $t('MAIL_STATUS') ?><select name="mail_state"><option value=""><?= $t('ALL_STATUSES') ?></option>
<?php foreach (Mailbox::VIEWS[$mailPage['view']] as $state) :
    ?><option value="<?= $esc($state) ?>" <?= $app->input->getCmd('mail_state', '') === $state ? 'selected' : '' ?>><?= $t('STATE_' . strtoupper($state)) ?></option><?php
endforeach; ?></select></label>
<button type="submit" class="ic-quiet"><?= $t('SEARCH') ?></button>
</form>
<?php if (!$mailPage['rows']) :
    ?><p class="ic-help"><?= $t('NO_EMAILS') ?></p><?php
endif; ?>
<ul class="ic-mail-rows">
<?php foreach ($mailPage['rows'] as $row) :
    $savedMessage = json_decode($row['content'], true) ?: [];
    $lang = str_starts_with($app->getLanguage()->getTag(), 'da') ? 'da' : 'en';
    $subject = $savedMessage['subject_' . $lang] ?? $savedMessage['subject_da'] ?? $t('CONTENT_EXPIRED');
    $stamp = match ($row['state']) {
        'scheduled' => (int) $row['send_at'] ? gmdate('Y-m-d H:i:s', (int) $row['send_at']) : null,
        'completed' => $row['finished_at'], 'submitted' => $row['requested_at'], default => $row['updated_at'],
    };
    $stampLabel = match ($row['state']) {
        'scheduled' => 'DATE_SCHEDULED', 'completed' => 'DATE_COMPLETED', 'submitted' => 'DATE_SUBMITTED', default => 'DATE_UPDATED',
    };
    ?>
<li data-mail-id="<?= (int) $row['id'] ?>">
<div class="ic-mail-row-head"><span class="ic-mail-badge" data-state="<?= $esc($row['state']) ?>"><?= $t('STATE_' . strtoupper($row['state'])) ?></span><small>#<?= (int) $row['id'] ?></small></div>
<a class="ic-mail-subject" href="<?= $esc(Route::_('index.php?option=com_intercom&view=composer&Itemid=' . $app->input->getInt('Itemid') . '&id=' . (int) $row['id'] . '&box=' . $mailPage['view'], false)) ?>"><?= $esc($subject ?: $t('UNTITLED')) ?></a>
    <?php if ($savedMessage) :
        ?><p><?= $esc(Audience::summary($savedMessage, $app->getLanguage()->getTag()) ?: $t('ALL_AUDIENCE')) ?></p><?php
    endif; ?>
    <?php if ($stamp) :
        ?><p><?= $t($stampLabel) ?> <?= $esc(HTMLHelper::_('date', $stamp, 'd-m-Y H:i T', true)) ?></p><?php
    endif; ?>
    <?php if ($row['estimate_count'] !== null) :
        ?><p><?= $t('ESTIMATE') ?>: <?= (int) $row['estimate_count'] ?></p><?php
    endif; ?>
    <?php if ($savedMessage) :
        ?><button class="ic-quiet" type="button" data-copy-id="<?= (int) $row['id'] ?>"><?= $t('COPY_TO_DRAFT') ?></button><?php
    endif; ?>
</li>
<?php endforeach; ?></ul>
<nav class="ic-mail-pages" aria-label="<?= $t('PAGES') ?>">
<?php if ($mailPage['page'] > 1) :
    ?><a href="<?= $esc($mailUrl($mailPage['view'], $mailPage['page'] - 1)) ?>"><?= $t('PREVIOUS') ?></a><?php
endif; ?>
<span><?= $mailPage['page'] ?> / <?= max(1, (int) ceil($mailPage['total'] / 10)) ?></span>
<?php if ($mailPage['page'] * 10 < $mailPage['total']) :
    ?><a href="<?= $esc($mailUrl($mailPage['view'], $mailPage['page'] + 1)) ?>"><?= $t('NEXT') ?></a><?php
endif; ?></nav>
</section>

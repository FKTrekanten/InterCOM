<?php

defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
$language = str_starts_with(Factory::getApplication()->getLanguage()->getTag(), 'da') ? 'da' : 'en';
$date = static fn ($value) => $value ? $esc(HTMLHelper::_('date', $value, \Joomla\CMS\Language\Text::_('DATE_FORMAT_LC6'))) : $t('NOT_RECORDED');
?>
<div class="table-responsive"><table class="table table-striped"><caption class="visually-hidden"><?= $t('HISTORY') ?></caption>
<thead><tr><th><?= $t('SUBJECT') ?></th><th><?= $t('TYPE') ?></th><th><?= $t('SENDER') ?></th><th><?= $t('ACTOR') ?></th><th><?= $t('STATE') ?></th><th><?= $t('SENT_AT') ?></th><th><?= $t('ESTIMATE') ?></th></tr></thead><tbody>
<?php foreach ($messages as $row) :
    $snapshot = $row['snapshot'] ? json_decode($row['snapshot'], true, 64, JSON_THROW_ON_ERROR) : [];
    $message = $snapshot['message'] ?? [];
    $definition = $message['definition'] ?? [];
    $locale = $language === 'da' ? 'da-DK' : 'en-GB';
    $name = \FKT\Component\Intercom\Administrator\Domain\Message::translation($definition, $locale)['name'] ?: $row['type_key'];
    ?>
<tr data-history-id="<?= (int) $row['draft_id'] ?>"><td><a href="index.php?option=com_intercom&amp;view=history&amp;id=<?= (int) $row['draft_id'] ?>"><?= $esc($message['subject_' . $language] ?? $message['subject_en'] ?? $t('CONTENT_EXPIRED')) ?></a></td>
<td><?= $esc($name) ?></td><td><?= $esc($message['sender'] ?? '—') ?></td><td><?= $esc($row['actor_name'] ?: '#' . $row['actor_id']) ?></td><td><?= $row['delivery_mode'] === 'fake' ? $t('SIMULATION') . ' · ' : '' ?><?= $t('STATE_' . strtoupper($row['state'])) ?></td><td><?= $date($row['started_at']) ?></td><td><?= ($snapshot['submission_estimate'] ?? $snapshot['estimate'] ?? null) !== null ? (int) ($snapshot['submission_estimate'] ?? $snapshot['estimate'] ?? null) : $t('NOT_RECORDED') ?></td></tr>
<?php endforeach; ?>
<?php if (!$messages) :
    ?><tr><td colspan="7"><?= $t('NO_SENT_MAIL') ?></td></tr><?php
endif; ?>
</tbody></table></div>

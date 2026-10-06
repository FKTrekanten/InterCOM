<?php

defined('_JEXEC') or die;

?>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('FILTER_ID') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('STATE') ?></th><th><?= $t('MAILING') ?></th><th><?= $t('RECIPIENT_LIST') ?></th><th><?= $t('FILTER_SCOPE') ?></th></tr></thead><tbody>
<?php foreach ($filterRows as $row) : ?>
<tr data-filter-id="<?= (int) $row['filter_id'] ?>"><td><?= (int) $row['filter_id'] ?></td><td>
    <?php if (!in_array($row['state'], ['deleted', 'abandoning'], true) && !empty($row['draft_id']) && (int) $row['owner_id'] === (int) \Joomla\CMS\Factory::getApplication()->getIdentity()->id) : ?>
<a href="../index.php?option=com_intercom&amp;id=<?= (int) $row['draft_id'] ?>">#<?= (int) $row['draft_id'] ?></a>
    <?php else :
        ?><?= (int) $row['draft_id'] ?><?php
    endif; ?>
</td><td><?= $esc($t($row['state'] ? 'STATE_' . strtoupper($row['state']) : (!empty($row['draft_id']) ? 'RECONCILIATION_UNKNOWN' : 'FILTER_FREE'))) ?>
    <?php if (!empty($row['reconciliation_status'])) :
        ?><div class="small"><?= $esc($t('RECONCILIATION_' . strtoupper($row['reconciliation_status']))) ?><br><?= $esc($row['checked_at'] ?? '') ?> UTC</div><?php
    endif; ?>
</td><td><?= (int) ($row['mailing_id'] ?? 0) ?: '—' ?></td><td><?= (int) $row['group_id'] ?: $t('SIMULATION') ?></td><td><?= $t((int) $row['managed'] === 1 && (int) $row['group_id'] === $r->catalog->context() ? 'FILTER_MANAGED' : 'FILTER_HISTORICAL') ?><?php if (($filterActions ?? false) && \Joomla\CMS\Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_intercom') && (int) $row['managed'] === 1 && (int) $row['group_id'] === (int) ($r->config['group_id'] ?? 0) && ($r->config['mode'] ?? '') === 'live' && ($row['delivery_mode'] ?? '') === 'live' && !empty($row['mailing_id']) && (($row['state'] ?? '') === 'abandoning' || (in_array($row['state'], ['draft','tested','cancelled','deleted'], true) && ($row['history_state'] ?? '') === 'prepared' && empty($row['requested_at']) && (int) ($row['reconstructed'] ?? 1) === 0))) : ?>
<div class="mt-2"><a class="btn btn-sm btn-outline-warning" href="index.php?option=com_intercom&amp;view=abandonment&amp;filter_id=<?= (int) $row['filter_id'] ?>"><?= $t($row['state'] === 'abandoning' ? 'ABANDON_RESUME' : 'ABANDON_TITLE') ?></a></div>
         <?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$filterRows) :
    ?><tr><td colspan="6"><?= $t('NO_RESULTS') ?></td></tr><?php
endif; ?>
</tbody></table></div>

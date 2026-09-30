<?php

defined('_JEXEC') or die;

?>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('FILTER_ID') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('STATE') ?></th><th><?= $t('RECIPIENT_LIST') ?></th><th><?= $t('FILTER_SCOPE') ?></th></tr></thead><tbody>
<?php foreach ($filterRows as $row) : ?>
<tr data-filter-id="<?= (int) $row['filter_id'] ?>"><td><?= (int) $row['filter_id'] ?></td><td>
    <?php if (!empty($row['draft_id']) && (int) $row['owner_id'] === (int) \Joomla\CMS\Factory::getApplication()->getIdentity()->id) : ?>
<a href="../index.php?option=com_intercom&amp;id=<?= (int) $row['draft_id'] ?>">#<?= (int) $row['draft_id'] ?></a>
    <?php else :
        ?><?= (int) $row['draft_id'] ?><?php
    endif; ?>
</td><td><?= $esc($t($row['state'] ? 'STATE_' . strtoupper($row['state']) : 'FILTER_FREE')) ?></td><td><?= (int) $row['group_id'] ?: $t('SIMULATION') ?></td><td><?= $t((int) $row['managed'] === 1 && (int) $row['group_id'] === $r->catalog->context() ? 'FILTER_MANAGED' : 'FILTER_HISTORICAL') ?></td></tr>
<?php endforeach; ?>
<?php if (!$filterRows) :
    ?><tr><td colspan="5"><?= $t('NO_RESULTS') ?></td></tr><?php
endif; ?>
</tbody></table></div>

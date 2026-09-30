<?php

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

?>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('DATE') ?></th><th><?= $t('ACTOR') ?></th><th><?= $t('EVENT') ?></th><th><?= $t('DRAFT') ?></th><th><?= $t('DETAILS') ?></th></tr></thead><tbody>
<?php foreach ($events as $event) : ?>
<tr data-audit-id="<?= (int) $event['id'] ?>"><td><?= $esc(HTMLHelper::_('date', $event['created_at'], Text::_('DATE_FORMAT_LC6'))) ?></td><td><?= (int) $event['actor_id'] ?></td><td><?= $esc($event['event']) ?></td><td><?= (int) $event['draft_id'] ?></td><td><details><summary><?= $t('DETAILS') ?></summary><pre class="ic-audit-context"><?= $esc($event['context']) ?></pre></details></td></tr>
<?php endforeach; ?>
<?php if (!$events) :
    ?><tr><td colspan="5"><?= $t('NO_RESULTS') ?></td></tr><?php
endif; ?>
</tbody></table></div>

<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$connectionEscape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$connectionApp = Factory::getApplication();
$connectionZone = $connectionApp->getIdentity()->getParam('timezone', $connectionApp->get('offset', 'UTC'));
$connectionDate = static fn (int $timestamp): string => HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $timestamp), Text::_('COM_INTERCOM_CONNECTION_DATE_FORMAT'), $connectionZone) . ' (' . $connectionZone . ')';
$connectionText = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$connectionMessage = 'CONNECTION_MANUAL';
if ($connectionStatus['automatic']) {
    $connectionMessage = 'CONNECTION_AUTOMATIC';
} elseif ($connectionStatus['expired']) {
    $connectionMessage = 'CONNECTION_EXPIRED';
}
?>
<section class="intercom-connection-details mb-3" aria-label="<?= $connectionEscape($connectionText('CONNECTION')) ?>">
<p><strong><?= $connectionEscape($connectionText('CURRENT_CLIENT_ID')) ?>:</strong> <code><?= $connectionEscape($connectionStatus['client_id'] ?: $connectionText('NOT_YET')) ?></code></p>
<?php if ($connectionStatus['connected']) : ?>
<p><?= $connectionEscape(Text::sprintf('COM_INTERCOM_' . $connectionMessage, $connectionStatus['account_id'], $connectionDate($connectionStatus['expires_at']))) ?></p>
<?php else : ?>
<p><?= $connectionEscape($connectionText('CONNECTION_UNVERIFIED')) ?></p>
<?php endif; ?>
<?php if (!$connectionStatus['automatic']) :
    $connectionReason = match ($connectionStatus['state']) {
        'uncertain', 'reconnect' => 'RENEWAL_RECONNECT',
        'exchanging' => 'RENEWAL_BUSY',
        'pending' => 'RENEWAL_VALIDATING',
        'retry' => 'RENEWAL_RETRY',
        default => $connectionStatus['mode'] !== 'live' ? 'RENEWAL_SIMULATION'
            : (!$connectionStatus['renewable'] ? 'RENEWAL_MISSING_CREDENTIALS'
            : (!$connectionStatus['scheduler_enabled'] ? 'RENEWAL_SCHEDULER_DISABLED' : ($connectionStatus['stale'] ? 'RENEWAL_SCHEDULER_STALE' : 'RENEWAL_DUE'))),
    }; ?>
<p class="text-warning"><?= $connectionEscape($connectionText($connectionReason)) ?></p>
<?php endif; ?>
<dl>
<?php foreach (['last_check' => 'CONNECTION_LAST_CHECK', 'last_success' => 'CONNECTION_LAST_RENEWAL', 'renew_at' => 'CONNECTION_NEXT_RENEWAL'] as $connectionProperty => $connectionLabel) : ?>
<dt><?= $connectionEscape($connectionText($connectionLabel)) ?></dt>
<dd><?= $connectionStatus[$connectionProperty] > 0 ? $connectionEscape($connectionDate($connectionStatus[$connectionProperty])) : $connectionEscape($connectionText('NOT_YET')) ?></dd>
<?php endforeach; ?>
</dl>
</section>

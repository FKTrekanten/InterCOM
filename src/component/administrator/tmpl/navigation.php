<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$app = Factory::getApplication();
$user = $app->getIdentity();
$links = ['dashboard' => ['DASHBOARD', 'dashboard']];
if ($user->authorise('intercom.history', 'com_intercom')) {
    $links += ['history' => ['HISTORY', 'history']];
}
if ($user->authorise('intercom.audit', 'com_intercom')) {
    $links += ['audit' => ['AUDIT', 'audit'], 'filters' => ['RESERVATIONS', 'filters']];
}
if ($user->authorise('core.admin', 'com_intercom')) {
    $links += ['types' => ['COMMUNICATION_GROUPS', 'settings&section=types'], 'tags' => ['RECIPIENT_TAGS', 'settings&section=tags'],
        'access' => ['AUDIENCE_ACCESS', 'settings&section=access'], 'design' => ['EMAIL_DESIGN', 'settings&section=design']];
}
$active = $app->input->getCmd('view', 'dashboard') === 'settings' ? $app->input->getCmd('section', 'types') : $app->input->getCmd('view', 'dashboard');
$app->getDocument()->getWebAssetManager()->useStyle('com_intercom.admin');
?>
<nav class="ic-admin-nav" aria-label="<?= Text::_('COM_INTERCOM') ?>">
<?php foreach ($links as $key => [$label, $route]) : ?>
<a href="index.php?option=com_intercom&amp;view=<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><?= Text::_('COM_INTERCOM_' . $label) ?></a>
<?php endforeach; ?></nav>

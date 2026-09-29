<?php
// Runs inside the disposable/local Joomla container; never copy into the web root.
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
if (!is_file(JPATH_BASE . '/configuration.php') || !is_file(JPATH_BASE . '/libraries/vendor/autoload.php')) {
    throw new \RuntimeException('Joomla installation is not ready');
}
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session.web', 'session.web.site')->alias('session', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
$app->getSession()->start();
$app->createExtensionNamespaceMap();
$db = $container->get(\Joomla\Database\DatabaseInterface::class);
function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); echo "PASS: $message\n"; }

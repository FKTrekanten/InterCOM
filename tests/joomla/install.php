<?php
require __DIR__ . '/bootstrap.php';
$path=$argv[1] ?? '';
$temporary = tempnam('/tmp', 'intercom-') . '.zip';
copy($path, $temporary);
$path = $temporary;
$archive=\Joomla\CMS\Installer\InstallerHelper::unpack($path, true);
check(is_array($archive) && !empty($archive['dir']), 'Unpack built package');
check(\Joomla\CMS\Installer\Installer::getInstance()->install($archive['dir']), 'Install via native Joomla installer');
$app->createExtensionNamespaceMap();
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE element='com_intercom'")->loadResult()===1,'Component registered');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE element='intercom' AND folder='task'")->loadResult()===1,'Task plugin registered');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__intercom_connections")->loadResult()===1,'Encrypted connection row initialised');
$db->setQuery("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='task' AND element='intercom'")->execute();
$db->setQuery("UPDATE #__extensions SET enabled=0 WHERE type='plugin' AND element IN ('compat','compat6')")->execute();
check(is_object($app->bootComponent('com_intercom')->runtime),'Native component boots with compatibility plugins disabled');
echo "INSTALL OK\n";

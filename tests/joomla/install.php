<?php
require __DIR__ . '/bootstrap.php';
$path=$argv[1] ?? '';
$previousTask = $db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND element='intercom' AND folder='task'")->loadAssoc();
$temporary = tempnam('/tmp', 'intercom-') . '.zip';
copy($path, $temporary);
$path = $temporary;
$archive=\Joomla\CMS\Installer\InstallerHelper::unpack($path, true);
check(is_array($archive) && !empty($archive['dir']), 'Unpack built package');
foreach (['en-GB', 'da-DK'] as $tag) {
    $language = \Joomla\CMS\Language\Language::getInstance($tag);
    check($language->load('pkg_intercom.sys', $archive['dir'], $tag, true, false), "Package includes loadable $tag translations");
    check($language->_('PKG_INTERCOM') === 'InterCOM', "Package name translates in $tag");
    check($language->_('PKG_INTERCOM_DESCRIPTION') !== 'PKG_INTERCOM_DESCRIPTION', "Package description translates in $tag");
}
check(\Joomla\CMS\Installer\Installer::getInstance()->install($archive['dir']), 'Install via native Joomla installer');
$app->createExtensionNamespaceMap();
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE element='com_intercom'")->loadResult()===1,'Component registered');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE element='intercom' AND folder='task'")->loadResult()===1,'Task plugin registered');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__intercom_connections")->loadResult()===1,'Encrypted connection row initialised');
check((int)$db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND element='intercom' AND folder='task'")->loadResult() === ($previousTask === null ? 1 : (int)$previousTask['enabled']), 'Fresh install enables maintenance; upgrade preserves its setting');
$language = \Joomla\CMS\Language\Language::getInstance('en-GB');
check($language->load('pkg_intercom.sys', JPATH_SITE, 'en-GB', true, false), 'Native installer installs package translations where Joomla Manage loads them');
$expectedDate = (string)simplexml_load_file($archive['dir'] . '/pkg_intercom.xml')->creationDate;
check((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate), 'Package supplies a release date');
$manage = $app->bootComponent('com_installer')->getMVCFactory()->createModel('Manage', 'Administrator', ['ignore_request' => true]);
$manage->setState('filter.search', 'intercom');
$manage->setState('list.limit', 20);
$manage->setState('list.ordering', 'name');
$manage->setState('list.direction', 'ASC');
$items = $manage->getItems();
check(count($items) === 4, 'Joomla Manage lists the package and its three extensions');
foreach ($items as $item) {
    $expectedName = $item->type === 'plugin' && $item->folder === 'extension' ? 'InterCOM Options' : 'InterCOM';
    check($item->name === $expectedName, "Joomla Manage translates $item->element ($item->folder)");
    check($item->creationDate === $expectedDate, "Joomla Manage displays the release date for $item->element ($item->folder)");
}
$db->setQuery("UPDATE #__extensions SET enabled=0 WHERE type='plugin' AND element IN ('compat','compat6')")->execute();
check(is_object($app->bootComponent('com_intercom')->runtime),'Native component boots with compatibility plugins disabled');
echo "INSTALL OK\n";
check((int)$db->setQuery("SELECT enabled FROM #__extensions WHERE element='intercom' AND folder='extension'")->loadResult()===1,'Options validation plugin enabled');
check(is_numeric($db->setQuery('SELECT COUNT(*) FROM #__intercom_filter_creations')->loadResult()),'Managed filter intent table installed');

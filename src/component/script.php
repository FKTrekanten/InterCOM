<?php

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Version;

return new class () implements InstallerScriptInterface {
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }
        return version_compare(PHP_VERSION, '8.3', '>=') && version_compare(JVERSION, '6.1', '>=')
            && version_compare(JVERSION, '7.0', '<') && extension_loaded('sodium') && extension_loaded('curl') && extension_loaded('dom') && extension_loaded('mbstring');
    }
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }
    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall') {
            foreach (['Infrastructure/Store', 'Table/TransactionalAssetTable', 'Table/CommunicationTable', 'Service/CatalogMigration', 'Service/SettingsMigration', 'Service/History'] as $file) {
                require_once JPATH_ADMINISTRATOR . '/components/com_intercom/src/' . $file . '.php';
            }
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $store = new \FKT\Component\Intercom\Administrator\Infrastructure\Store($db);
            \FKT\Component\Intercom\Administrator\Service\CatalogMigration::run($store);
            \FKT\Component\Intercom\Administrator\Service\SettingsMigration::run($store);
            (new \FKT\Component\Intercom\Administrator\Service\History($store))->migrate();
        }
        return true;
    }
};

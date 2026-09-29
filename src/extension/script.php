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
            && version_compare(JVERSION, '7.0', '<') && extension_loaded('sodium') && extension_loaded('curl');
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
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $db->setQuery("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='extension' AND element='intercom'")->execute();
        return true;
    }
};

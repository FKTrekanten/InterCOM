<?php

defined('_JEXEC') or die;
use FKT\Plugin\Extension\Intercom\Extension\Intercom;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (): Intercom {
            $plugin = new Intercom((array) PluginHelper::getPlugin('extension', 'intercom'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};

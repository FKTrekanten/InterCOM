<?php

defined('_JEXEC') or die;

use FKT\Component\Intercom\Administrator\Extension\IntercomComponent;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Service\Runtime;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Database\DatabaseInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\FKT\\Component\\Intercom'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\FKT\\Component\\Intercom'));
        $container->set(ComponentInterface::class, function (Container $container): IntercomComponent {
            $component = new IntercomComponent($container->get(ComponentDispatcherFactoryInterface::class));
            $component->setMVCFactory($container->get(MVCFactoryInterface::class));
            $component->runtime = new Runtime(
                new Store($container->get(DatabaseInterface::class)),
                (string) Factory::getApplication()->get('secret')
            );
            return $component;
        });
    }
};

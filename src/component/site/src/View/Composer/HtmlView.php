<?php

namespace FKT\Component\Intercom\Site\View\Composer;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;

final class HtmlView extends BaseView
{
    public $runtime;
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        if ($app->getIdentity()->guest) {
            $return = base64_encode(\Joomla\CMS\Uri\Uri::getInstance()->toString());
            $app->redirect(\Joomla\CMS\Router\Route::_('index.php?option=com_users&view=login&return=' . urlencode($return), false));
            return;
        }
        if (!$app->getIdentity()->authorise('intercom.access', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        parent::display($tpl);
    }
}

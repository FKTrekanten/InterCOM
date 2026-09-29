<?php

namespace FKT\Component\Intercom\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;

final class HtmlView extends BaseView
{
    public $runtime;
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.manage', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        parent::display($tpl);
    }
}

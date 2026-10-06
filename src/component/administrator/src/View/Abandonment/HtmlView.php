<?php

namespace FKT\Component\Intercom\Administrator\View\Abandonment;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;

final class HtmlView extends BaseView
{
    public $record;
    public $runtime;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.admin', 'com_intercom') || !$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.audit', 'com_intercom')) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        $this->record = $this->runtime->abandonment()->details($app->input->getInt('filter_id'));
        parent::display($tpl);
    }
}

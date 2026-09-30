<?php

namespace FKT\Component\Intercom\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;

final class HtmlView extends BaseView
{
    public $runtime;
    public array $messages = [];
    public array $statistics = [];
    public array $filters = [];
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.manage', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        if ($app->getIdentity()->authorise('intercom.audit', 'com_intercom')) {
            $activity = new \FKT\Component\Intercom\Administrator\Service\Activity($this->runtime->store, $this->runtime->config);
            $this->statistics = $activity->overview();
            $this->filters = $activity->page('filters', [], 5, 0)['rows'];
        }
        if ($app->getIdentity()->authorise('intercom.history', 'com_intercom')) {
            $this->messages = (new \FKT\Component\Intercom\Administrator\Service\History($this->runtime->store))->page([], 5, 0, true)['rows'];
        }
        parent::display($tpl);
    }
}

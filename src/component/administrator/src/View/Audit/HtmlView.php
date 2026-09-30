<?php

namespace FKT\Component\Intercom\Administrator\View\Audit;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;
use Joomla\CMS\Pagination\Pagination;
use FKT\Component\Intercom\Administrator\Service\Activity;

final class HtmlView extends BaseView
{
    public $runtime;
    public array $rows = [];
    public array $filters = [];
    public $pagination;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.audit', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        $view = 'audit';
        foreach (['actor', 'event', 'from', 'to'] as $key) {
            $this->filters[$key] = $app->input->getString($key, $key === 'scope' ? 'current' : '');
        }
        $limit = (int) $app->getUserStateFromRequest('com_intercom.' . $view . '.limit', 'limit', 20, 'uint');
        $limit = in_array($limit, [10, 20, 50, 100], true) ? $limit : 20;
        $result = (new Activity($this->runtime->store, $this->runtime->config))->page($view, $this->filters, $limit, $app->input->getInt('limitstart', 0));
        $this->rows = $result['rows'];
        $this->pagination = new Pagination($result['total'], $result['start'], $result['limit']);
        foreach (['option' => 'com_intercom', 'view' => $view] + $this->filters as $key => $value) {
            $this->pagination->setAdditionalUrlParam($key, $value);
        }
        parent::display($tpl);
    }
}

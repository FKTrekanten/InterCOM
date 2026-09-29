<?php

namespace FKT\Component\Intercom\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;

final class HtmlView extends BaseView
{
    public $runtime;
    public array $events = [];
    public $pagination;
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.manage', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        if ($app->getIdentity()->authorise('intercom.audit', 'com_intercom')) {
            $limit = (int) $app->getUserStateFromRequest('com_intercom.audit.limit', 'limit', 20, 'uint');
            $limit = in_array($limit, [10, 20, 50, 100], true) ? $limit : 20;
            $total = (int) $this->runtime->store->row('SELECT COUNT(*) AS total FROM #__intercom_audit')['total'];
            $start = max(0, $app->input->getInt('limitstart', 0));
            $start = min($start, max(0, (int) (ceil($total / $limit) - 1)) * $limit);
            $this->pagination = new \Joomla\CMS\Pagination\Pagination($total, $start, $limit);
            $this->pagination->setAdditionalUrlParam('option', 'com_intercom');
            $this->events = $this->runtime->store->rows("SELECT * FROM #__intercom_audit ORDER BY id DESC LIMIT $limit OFFSET $start");
        }
        parent::display($tpl);
    }
}

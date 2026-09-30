<?php

namespace FKT\Component\Intercom\Administrator\View\History;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;
use Joomla\CMS\Pagination\Pagination;
use FKT\Component\Intercom\Administrator\Service\History;

final class HtmlView extends BaseView
{
    public $runtime;
    public array $rows = [];
    public array $filters = [];
    public array $record = [];
    public $pagination;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.history', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        $history = new History($this->runtime->store);
        if ($app->input->getInt('id')) {
            $this->record = $history->detail($app->input->getInt('id'));
        } else {
            foreach (['actor', 'state', 'type_key', 'from', 'to'] as $key) {
                $this->filters[$key] = $app->input->getString($key, '');
            }
            $limit = (int) $app->getUserStateFromRequest('com_intercom.history.limit', 'limit', 20, 'uint');
            $result = $history->page($this->filters, $limit, $app->input->getInt('limitstart', 0));
            $this->rows = $result['rows'];
            $this->pagination = new Pagination($result['total'], $result['start'], $result['limit']);
            foreach (['option' => 'com_intercom', 'view' => 'history'] + $this->filters as $key => $value) {
                $this->pagination->setAdditionalUrlParam($key, $value);
            }
        }
        parent::display($tpl);
    }
}

<?php

namespace FKT\Component\Intercom\Administrator\View\Settings;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\View\HtmlView as BaseView;
use Joomla\CMS\Pagination\Pagination;

final class HtmlView extends BaseView
{
    public $runtime;
    public $section;
    public $form;
    public $record;
    public $definitions;
    public $pagination;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.manage', 'com_intercom') || !$app->getIdentity()->authorise('core.admin', 'com_intercom')) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        $this->section = $app->input->getCmd('section', 'types');
        if (!in_array($this->section, ['types', 'tags', 'access', 'design'], true)) {
            throw new \RuntimeException('Unknown settings page', 404);
        }
        $r = $this->runtime;
        if ($this->section === 'types' && $app->input->getBool('edit')) {
            $id = $app->input->getInt('id');
            $this->record = $id ? $r->store->row("SELECT * FROM #__intercom_types WHERE id=$id") : ['id' => 0, 'revision' => 0, 'type_key' => '', 'state' => 1, 'ordering' => 0, 'category_id' => 0, 'suppression' => '', 'require_group' => 0, 'asset_id' => 0];
            if (!$this->record) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_TYPE', 404);
            }
            $asset = $r->store->row('SELECT rules FROM #__assets WHERE id=' . (int) $this->record['asset_id']);
            $this->form = Form::getInstance('com_intercom.communication', JPATH_COMPONENT_ADMINISTRATOR . '/forms/communication.xml', ['control' => 'jform']);
            $this->form->bind(['asset_id' => (int) $this->record['asset_id'], 'rules' => json_decode($asset['rules'] ?? '{}', true)]);
        } elseif ($this->section === 'types') {
            $limit = $app->getUserStateFromRequest('com_intercom.types.limit', 'limit', 20, 'uint');
            $limit = in_array($limit, [10, 20, 50, 100], true) ? $limit : 20;
            $total = (int) $r->store->row('SELECT COUNT(*) n FROM #__intercom_types')['n'];
            $start = min(max(0, $app->input->getInt('limitstart')), max(0, (int) (ceil($total / $limit) - 1)) * $limit);
            $this->pagination = new Pagination($total, $start, $limit);
            foreach (['option' => 'com_intercom', 'view' => 'settings', 'section' => 'types'] as $key => $value) {
                $this->pagination->setAdditionalUrlParam($key, $value);
            }
            $this->definitions = $r->store->rows("SELECT * FROM #__intercom_types ORDER BY ordering,id LIMIT $limit OFFSET $start");
        }
        parent::display($tpl);
    }
}

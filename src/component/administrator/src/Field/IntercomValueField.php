<?php

namespace FKT\Component\Intercom\Administrator\Field;

use Joomla\CMS\Form\Field\TextField;
use Joomla\CMS\Factory;

final class IntercomValueField extends TextField
{
    protected $type = 'IntercomValue';
    protected function getInput()
    {
        $r = Factory::getApplication()->bootComponent('com_intercom')->runtime;
        if ($this->value === null || $this->value === '') {
            $this->value = $this->fieldname === 'filter_ids'
                ? implode(',', array_column($r->store->rows('SELECT filter_id FROM #__intercom_filters ORDER BY filter_id'), 'filter_id'))
                : ($r->config['categories'][substr($this->fieldname, 9)] ?? 0);
        }
        return parent::getInput();
    }
}

<?php

namespace FKT\Component\Intercom\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use FKT\Component\Intercom\Administrator\Service\ReleaseApproval;

final class IntercomAcceptanceField extends FormField
{
    protected $type = 'IntercomAcceptance';
    protected function getInput()
    {
        $r = Factory::getApplication()->bootComponent('com_intercom')->runtime;
        $label = (new ReleaseApproval($r->store))->valid() ? 'COM_INTERCOM_ACCEPTANCE_VALID' : 'COM_INTERCOM_RELEASE_NOT_VERIFIED';
        return '<p>' . Text::_($label) . '</p><a href="index.php?option=com_intercom&amp;view=acceptance">' . Text::_('COM_INTERCOM_ACCEPTANCE') . '</a>';
    }
}

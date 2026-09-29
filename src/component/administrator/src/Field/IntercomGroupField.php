<?php

namespace FKT\Component\Intercom\Administrator\Field;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

final class IntercomGroupField extends ListField
{
    protected $type = 'IntercomGroup';

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $r = Factory::getApplication()->bootComponent('com_intercom')->runtime;
        $current = (int) ($this->value ?: ($r->config['group_id'] ?? 0));
        $options[] = HTMLHelper::_('select.option', '0', Text::_('COM_INTERCOM_CHOOSE_GROUP'));
        try {
            $groups = (new CleverReachGateway(fn () => $r->connection->token(), $r->config))->groups();
            $seen = [];
            foreach ($groups as $group) {
                $id = (int) ($group['id'] ?? 0);
                if ($id > 0 && is_string($group['name'] ?? null)) {
                    $options[] = HTMLHelper::_('select.option', (string) $id, $group['name']);
                    $seen[$id] = true;
                }
            }
            if ($current && !isset($seen[$current])) {
                $options[] = HTMLHelper::_('select.option', (string) $current, Text::sprintf('COM_INTERCOM_GROUP_UNAVAILABLE', $current));
            }
        } catch (\Throwable) {
            if ($current) {
                $options[] = HTMLHelper::_('select.option', (string) $current, Text::sprintf('COM_INTERCOM_GROUP_UNAVAILABLE', $current));
            }
        }
        return $options;
    }
}

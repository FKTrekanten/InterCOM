<?php

namespace FKT\Component\Intercom\Administrator\Field;

use FKT\Component\Intercom\Administrator\Domain\UnsubscribeForm;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

final class IntercomUnsubscribeField extends ListField
{
    protected $type = 'IntercomUnsubscribe';

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $options[] = HTMLHelper::_('select.option', '', Text::_('COM_INTERCOM_CHOOSE_UNSUBSCRIBE'));
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.admin', 'com_intercom')) {
            return $options;
        }
        $app->getDocument()->getWebAssetManager()->registerAndUseScript('com_intercom.options', 'com_intercom/options.js', ['version' => 'auto'], ['defer' => true]);
        foreach (['CHOOSE_UNSUBSCRIBE', 'UNSUBSCRIBE_LOADING', 'UNSUBSCRIBE_LOAD_ERROR'] as $key) {
            Text::script('COM_INTERCOM_' . $key);
        }
        $r = $app->bootComponent('com_intercom')->runtime;
        $current = (string) $this->value;
        $groupId = (int) $this->form->getValue('group_id', null, $r->config['group_id'] ?? 0);
        $seen = [];
        try {
            if ($groupId > 0) {
                $forms = (new CleverReachGateway(fn () => $r->connection->token(), $r->config))->unsubscribeForms($groupId);
                foreach ($forms as $form) {
                    $options[] = HTMLHelper::_('select.option', $form['id'], $form['name']);
                    $seen[$form['id']] = true;
                }
            }
        } catch (\Throwable) {
            // An unavailable API must not erase an existing configuration on save.
        }
        if ($current && !isset($seen[$current])) {
            $key = UnsubscribeForm::isFlow($current) ? 'COM_INTERCOM_UNSUBSCRIBE_UNAVAILABLE' : 'COM_INTERCOM_UNSUBSCRIBE_LEGACY';
            $options[] = HTMLHelper::_('select.option', $current, Text::sprintf($key, $current));
        }
        return $options;
    }
}

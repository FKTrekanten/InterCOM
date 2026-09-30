<?php

namespace FKT\Component\Intercom\Administrator\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

final class IntercomConnectionField extends FormField
{
    protected $type = 'IntercomConnection';
    protected function getInput()
    {
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.admin', 'com_intercom')) {
            return '';
        }
        $app->getDocument()->getWebAssetManager()->registerAndUseScript('com_intercom.options', 'com_intercom/options.js', ['version' => 'auto'], ['defer' => true]);
        $t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '<div class="intercom-connection"><p>' . $t('SECRET_ACTION_HELP') . '</p>';
        $html .= '<p>' . $t('ACCOUNT_HELP') . '</p><button type="button" class="btn btn-secondary mb-3" data-intercom-task="verifyaccount">' . $t('VERIFY_ACCOUNT') . '</button>';
        foreach (['client_id', 'client_secret'] as $name) {
            $html .= '<label class="form-label" for="' . $name . '">' . $t(strtoupper($name)) . '</label><input class="form-control mb-3" type="password" name="' . $name . '" id="' . $name . '" value="" autocomplete="new-password">';
        }
        $html .= '<button type="button" class="btn btn-primary me-2" data-intercom-task="savecredentials">' . $t('SAVE_CREDENTIALS') . '</button><button type="button" class="btn btn-secondary" data-intercom-task="connect">' . $t('CONNECT') . '</button>';
        $html .= '<p class="mt-3">' . $t('CALLBACK') . ': <code>' . $escape(Uri::root() . 'administrator/index.php?option=com_intercom&task=connection.callback') . '</code></p><hr><h3>' . $t('IMPORT_TOKENS') . '</h3><p>' . $t('IMPORT_HELP') . '</p>';
        foreach (['access_token', 'refresh_token'] as $name) {
            $html .= '<label class="form-label" for="' . $name . '">' . $t(strtoupper($name)) . '</label><input class="form-control mb-3" type="password" name="' . $name . '" id="' . $name . '" value="" autocomplete="new-password">';
        }
        return $html . '<label class="form-label" for="expires_in">' . $t('EXPIRES_IN') . '</label><input class="form-control mb-3" type="number" name="expires_in" id="expires_in" min="1" max="315360000" value=""><button type="button" class="btn btn-primary" data-intercom-task="importtokens">' . $t('IMPORT_TOKENS') . '</button></div>';
    }
}

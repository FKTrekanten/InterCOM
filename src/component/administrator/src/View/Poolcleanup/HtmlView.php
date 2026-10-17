<?php

namespace FKT\Component\Intercom\Administrator\View\Poolcleanup;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseView;

final class HtmlView extends BaseView
{
    public ?array $preview = null;
    public string $error = '';

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$app->isClient('administrator') || !$user->authorise('core.admin', 'com_intercom') || !$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.audit', 'com_intercom')) {
            throw new \RuntimeException(Text::_('COM_INTERCOM_DENIED'), 403);
        }
        try {
            $this->preview = $app->bootComponent('com_intercom')->runtime->poolCleanup()->preview();
        } catch (\Throwable $error) {
            $this->error = str_starts_with($error->getMessage(), 'COM_INTERCOM_') ? $error->getMessage() : 'COM_INTERCOM_ERROR';
        }
        parent::display($tpl);
    }
}

<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class PoolcleanupController extends BaseController
{
    public function apply(): void
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$app->isClient('administrator') || !$user->authorise('core.admin', 'com_intercom') || !$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.audit', 'com_intercom') || $app->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $runtime = $app->bootComponent('com_intercom')->runtime;
        try {
            $count = $runtime->poolCleanup()->apply($app->input->post->getString('fingerprint'), $app->input->post->getBool('confirmed'), $app->input->post->getString('reason'), (int) $user->id);
            $app->enqueueMessage(Text::sprintf('COM_INTERCOM_CLEANUP_DONE', $count));
            $this->setRedirect('index.php?option=com_intercom&view=filters');
        } catch (\Throwable $error) {
            $runtime->store->audit((int) $user->id, 'pool.cleanup_failed', 0, ['status' => $error->getCode()]);
            $app->enqueueMessage(Text::_(str_starts_with($error->getMessage(), 'COM_INTERCOM_') ? $error->getMessage() : 'COM_INTERCOM_ERROR'), 'error');
            $this->setRedirect('index.php?option=com_intercom&view=poolcleanup');
        }
    }
}

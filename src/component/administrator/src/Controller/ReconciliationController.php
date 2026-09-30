<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class ReconciliationController extends BaseController
{
    public function run(): void
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.admin', 'com_intercom') || !$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.audit', 'com_intercom') || $app->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('COM_INTERCOM_DENIED'), 403);
        }
        $runtime = $app->bootComponent('com_intercom')->runtime;
        try {
            $service = $runtime->reconciliation();
            if (!$service) {
                throw new \RuntimeException('COM_INTERCOM_MODE_CHANGED', 409);
            }
            $result = $service->run((int) $user->id);
            $app->enqueueMessage(Text::sprintf('COM_INTERCOM_RECONCILE_RESULT', $result['checked'], $result['released'], $result['adopted'], $result['blocked']));
        } catch (\Throwable $error) {
            $runtime->store->audit((int) $user->id, 'reconciliation.failed', 0, ['status' => $error->getCode()]);
            $app->enqueueMessage(Text::_(str_starts_with($error->getMessage(), 'COM_INTERCOM_') ? $error->getMessage() : 'COM_INTERCOM_ERROR'), 'error');
        }
        $app->redirect('index.php?option=com_intercom&view=filters');
    }
}

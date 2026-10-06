<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class AbandonmentController extends BaseController
{
    private function run(bool $verify): void
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (
            !$app->isClient('administrator') || !$user->authorise('core.admin', 'com_intercom')
            || !$user->authorise('core.manage', 'com_intercom') || !$user->authorise('intercom.audit', 'com_intercom')
            || $app->input->getMethod() !== 'POST' || !Session::checkToken('post')
        ) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        $filter = $app->input->post->getInt('filter_id');
        $runtime = $app->bootComponent('com_intercom')->runtime;
        try {
            if (!$app->input->post->getBool('confirmed', false)) {
                throw new \RuntimeException('COM_INTERCOM_ABANDON_CONFIRM_REQUIRED', 422);
            }
            $service = $runtime->abandonment();
            if ($verify) {
                $operation = $service->details($filter)['operation'];
                if (!$operation || (int) $operation['id'] !== $app->input->post->getInt('operation_id')) {
                    throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
                }
                $service->verify((int) $operation['id'], (int) $user->id, $app->input->post->getBool('permanently_removed', false));
                $app->enqueueMessage(Text::_('COM_INTERCOM_ABANDON_RELEASED'));
                $this->setRedirect('index.php?option=com_intercom&view=filters');
                return;
            }
            $service->inspect($filter, $app->input->post->getInt('revision'), $app->input->post->getInt('generation'), (int) $user->id, $app->input->post->getString('reason'));
            $app->enqueueMessage(Text::_('COM_INTERCOM_ABANDON_INSPECTED'));
        } catch (\Throwable $error) {
            $runtime->store->audit((int) $user->id, 'abandonment.action_failed', 0, ['filter_id' => $filter]);
            $app->enqueueMessage(Text::_(str_starts_with($error->getMessage(), 'COM_INTERCOM_') ? $error->getMessage() : 'COM_INTERCOM_ABANDON_UNVERIFIED'), 'error');
        }
        $this->setRedirect('index.php?option=com_intercom&view=abandonment&filter_id=' . $filter);
    }

    public function inspect(): void
    {
        $this->run(false);
    }

    public function verify(): void
    {
        $this->run(true);
    }
}

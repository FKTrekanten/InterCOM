<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use FKT\Component\Intercom\Administrator\Service\Acceptance;

final class AcceptanceController extends BaseController
{
    private function run(string $action): void
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$app->isClient('administrator') || !$user->authorise('core.manage', 'com_intercom') || !$user->authorise('core.admin', 'com_intercom') || $app->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        $r = $app->bootComponent('com_intercom')->runtime;
        try {
            if ($action !== 'prepare' && !$app->input->post->getBool('confirmed')) {
                throw new \RuntimeException('COM_INTERCOM_CONFIRM_REQUIRED', 422);
            }
            $service = new Acceptance($r, $user);
            match ($action) {
                'prepare' => $service->prepare(),
                'release' => $service->release($app->input->post->getInt('id'), $app->input->post->getInt('revision')),
                'retire' => $service->retire($app->input->post->getInt('id'), $app->input->post->getInt('revision')),
                'verify' => $service->verify($app->input->post->getInt('id')),
            };
            $app->enqueueMessage(Text::_('COM_INTERCOM_ACCEPTANCE_' . strtoupper($action) . '_OK'));
        } catch (\Throwable $e) {
            $r->store->audit((int) $user->id, 'acceptance.' . $action . '_failed');
            $app->enqueueMessage(Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_PROVIDER_ERROR'), 'error');
        }
        $this->setRedirect('index.php?option=com_intercom&view=acceptance');
    }
    public function prepare(): void
    {
        $this->run('prepare');
    }
    public function release(): void
    {
        $this->run('release');
    }
    public function retire(): void
    {
        $this->run('retire');
    }
    public function verify(): void
    {
        $this->run('verify');
    }
}

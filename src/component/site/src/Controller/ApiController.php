<?php

namespace FKT\Component\Intercom\Site\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class ApiController extends BaseController
{
    public function execute($task)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $runtime = $app->bootComponent('com_intercom')->runtime;
        header('Content-Type: application/json; charset=utf-8');
        try {
            if ($user->guest || !$user->authorise('intercom.access', 'com_intercom')) {
                throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
            }
            if ($app->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
                throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
            }
            $workflow = $runtime->workflow($user);
            $id = $app->input->post->getInt('id', 0);
            $revision = $app->input->post->getInt('revision', 0);
            $result = match ($task) {
                'save' => $workflow->save(json_decode($app->input->post->get('message', '{}', 'raw'), true, 64, JSON_THROW_ON_ERROR), $id, $revision),
                'preview' => $workflow->preview($id, $revision, $user->email),
                'release' => $app->input->post->getBool('confirm')
                    ? $workflow->release($id, $revision, $app->input->post->getInt('send_at', 0))
                    : throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
                'cancel' => $workflow->cancel($id, $revision),
                default => throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
            };
            echo json_encode(['success' => true, 'data' => $result], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $status = in_array($e->getCode(), [403, 404, 409, 422], true) ? $e->getCode() : 500;
            http_response_code($status);
            $runtime->store->audit((int) $user->id, 'request.failed', 0, ['status' => $status]);
            $key = str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR';
            echo json_encode(['success' => false, 'error' => Text::_($key)]);
        }
        $app->close();
    }
}

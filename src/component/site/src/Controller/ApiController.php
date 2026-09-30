<?php

namespace FKT\Component\Intercom\Site\Controller;

use FKT\Component\Intercom\Administrator\Domain\Message;
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
                'render' => $this->renderMessage($runtime, $user, json_decode($app->input->post->get('message', '{}', 'raw'), true, 64, JSON_THROW_ON_ERROR)),
                'save' => $workflow->save(json_decode($app->input->post->get('message', '{}', 'raw'), true, 64, JSON_THROW_ON_ERROR), $id, $revision),
                'preview' => $workflow->preview($id, $revision, $user->email),
                'release' => $app->input->post->getBool('confirm')
                    ? $workflow->release($id, $revision, $app->input->post->getInt('send_at', 0))
                    : throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
                'cancel' => $workflow->cancel($id, $revision),
                'delete' => $workflow->delete($id, $revision), 'restore' => $workflow->restore($id, $revision),
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
    private function renderMessage($runtime, $user, array $input): array
    {
        // Empty draft content gets preview-only guidance, never persisted as message text.
        foreach (['da' => 'da-DK', 'en' => 'en-GB'] as $lang => $locale) {
            $input['subject_' . $lang] = trim((string) ($input['subject_' . $lang] ?? '')) ?: Text::_('COM_INTERCOM_SUBJECT_' . strtoupper($lang));
            $input['body_' . $lang] = trim((string) ($input['body_' . $lang] ?? '')) ?: ($lang === 'da' ? '<p>Din besked vises her.</p>' : '<p>Your message appears here.</p>');
        }
        $message = Message::validate($input);
        $runtime->policy($user)->assertAllowed($message['type'], $message['tags'], 'compose');
        $message['definition'] = $runtime->catalog->snapshot($message);
        $message['design'] = $runtime->design->snapshot();
        $message['footer'] = \FKT\Component\Intercom\Administrator\Domain\Footer::validate($runtime->config);
        $subjects = [];
        foreach (['da' => 'da-DK', 'en' => 'en-GB'] as $lang => $locale) {
            $prefix = Message::translation($message['definition'], $locale)['subject_prefix'];
            $subjects[$lang] = ($prefix ? '[' . $prefix . '] ' : '') . $message['subject_' . $lang];
        }
        return ['da' => Message::html($message, 'da-DK', 'light'), 'en' => Message::html($message, 'en-GB', 'light'), 'subjects' => $subjects, 'da_dark' => Message::html($message, 'da-DK', 'dark'), 'en_dark' => Message::html($message, 'en-GB', 'dark')];
    }
}

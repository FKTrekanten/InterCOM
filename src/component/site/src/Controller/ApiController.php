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
                'save' => $workflow->saveDraft(json_decode($app->input->post->get('message', '{}', 'raw'), true, 64, JSON_THROW_ON_ERROR), $id, $revision),
                'saveaudience' => $workflow->saveAudience(json_decode($app->input->post->get('message', '{}', 'raw'), true, 64, JSON_THROW_ON_ERROR), $id, $revision),
                'audience' => $this->estimateAudience($workflow, json_decode($app->input->post->get('message', '{}', 'raw'), true, 64, JSON_THROW_ON_ERROR), $id, $revision),
                'estimate' => $workflow->estimate($id, $revision, $app->input->post->getBool('force', true)),
                'keepalive' => $workflow->keepalive($id, $revision),
                'status' => $workflow->status($id),
                'copy' => $workflow->copy($id, $app->input->post->get('request_key', '', 'cmd')),
                'recover' => $this->recover($runtime, $workflow, $user, $id, $revision, $app->input->post->getBool('legacy_verified', false)),
                'preview' => $workflow->preview($id, $revision, $user->email),
                'release' => $app->input->post->getBool('confirm')
                    ? $workflow->release($id, $revision, $app->input->post->getInt('send_at', 0), $app->input->post->getInt('approved_count', -1))
                    : throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
                'cancel' => $workflow->cancel($id, $revision),
                'delete' => $workflow->delete($id, $revision), 'restore' => $workflow->restore($id, $revision),
                default => throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
            };
            if (is_array($result) && isset($result['id'], $result['state'])) {
                $result = array_merge($workflow->status((int) $result['id']), array_intersect_key($result, ['estimate_error' => true]));
            }
            echo json_encode(['success' => true, 'data' => $result], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $status = in_array($e->getCode(), [403, 404, 409, 422], true) ? $e->getCode() : 500;
            http_response_code($status);
            $runtime->store->audit((int) $user->id, 'request.failed', 0, ['status' => $status, 'task' => in_array($task, ['save', 'saveaudience', 'audience', 'estimate', 'preview', 'release', 'status', 'copy', 'recover', 'cancel', 'delete', 'restore', 'render', 'keepalive'], true) ? $task : 'unknown']);
            $key = str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR';
            $result = ['success' => false, 'error' => Text::_($key), 'category' => $key];
            if ($status !== 403 && !$user->guest && isset($workflow) && ($id ?? 0) > 0) {
                try {
                    $result['data'] = $workflow->status($id);
                } catch (\Throwable) {
                    // Missing/unauthorized records never leak through error handling.
                }
            }
            echo json_encode($result);
        }
        $app->close();
    }
    private function recover($runtime, $workflow, $user, int $id, int $revision, bool $verified): array
    {
        if (!$user->authorise('core.admin', 'com_intercom')) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        (new \FKT\Component\Intercom\Administrator\Service\TestRecovery($runtime->store))->recover($id, (int) $user->id, $revision, $verified);
        return $workflow->status($id);
    }

    private function estimateAudience($workflow, array $input, int $id, int $revision): array
    {
        $draft = $workflow->saveAudience($input, $id, $revision);
        return $workflow->estimate((int) $draft['id'], (int) $draft['revision']);
    }

    private function renderMessage($runtime, $user, array $input): array
    {
        // Empty draft content gets preview-only guidance, never persisted as message text.
        foreach (['da' => 'da-DK', 'en' => 'en-GB'] as $lang => $locale) {
            $input['subject_' . $lang] = trim((string) ($input['subject_' . $lang] ?? '')) ?: Text::_('COM_INTERCOM_SUBJECT_' . strtoupper($lang));
            $input['body_' . $lang] = trim((string) ($input['body_' . $lang] ?? '')) ?: ($lang === 'da' ? '<p>Din besked vises her.</p>' : '<p>Your message appears here.</p>');
        }
        $message = Message::validate($input);
        $runtime->policy($user)->assertAllowed($message['type'], \FKT\Component\Intercom\Administrator\Domain\Audience::targetedTags($message), 'compose');
        $message['definition'] = $runtime->catalog->snapshot($message);
        $message['design'] = $runtime->design->snapshot();
        $message['tag_labels'] = $runtime->catalog->tagLabels($message);
        $message['footer'] = \FKT\Component\Intercom\Administrator\Domain\Footer::validate($runtime->config);
        $subjects = [];
        foreach (['da' => 'da-DK', 'en' => 'en-GB'] as $lang => $locale) {
            $prefix = Message::translation($message['definition'], $locale)['subject_prefix'];
            $subjects[$lang] = ($prefix ? '[' . $prefix . '] ' : '') . $message['subject_' . $lang];
        }
        return ['da' => Message::html($message, 'da-DK', 'light'), 'en' => Message::html($message, 'en-GB', 'light'), 'subjects' => $subjects, 'da_dark' => Message::html($message, 'da-DK', 'dark'), 'en_dark' => Message::html($message, 'en-GB', 'dark')];
    }
}

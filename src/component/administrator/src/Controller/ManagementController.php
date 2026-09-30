<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class ManagementController extends BaseController
{
    private function renderDesign($r, array $data): void
    {
        $settings = \FKT\Component\Intercom\Administrator\Domain\EmailDesign::validate($data);
        $sample = ['type' => 'club', 'sender' => $settings['sender_en'], 'format' => 'html', 'tags' => [],
            'body_da' => '<h2>Nyheder fra klubben</h2><p>Kære {FIRSTNAME[std:Medlem]}</p><p>Her kan du se, hvordan din besked og klubbens design ser ud sammen.</p>',
            'body_en' => '<h2>News from the club</h2><p>Hello {FIRSTNAME[std:Member]}</p><p>See how your message and the club design look together.</p>',
            'design' => ['settings' => $settings], 'definition' => $r->catalog->types()['club'] ?? []];
        $result = [];
        foreach (['da' => 'da-DK', 'en' => 'en-GB'] as $lang => $locale) {
            foreach (['light', 'dark'] as $theme) {
                $result[$lang . '_' . $theme] = \FKT\Component\Intercom\Administrator\Domain\Message::html($sample, $locale, $theme);
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $result], JSON_THROW_ON_ERROR);
        Factory::getApplication()->close();
    }

    public function execute($task)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $r = $app->bootComponent('com_intercom')->runtime;
        $section = ['savetype' => 'types', 'deletetype' => 'types', 'refreshtags' => 'tags', 'savetags' => 'tags', 'savescopes' => 'access', 'savedesign' => 'design', 'resetdesign' => 'design', 'renderdesign' => 'design'][$task] ?? 'types';
        try {
            if (
                !$user->authorise('core.admin', 'com_intercom') || !$user->authorise('core.manage', 'com_intercom')
                || $app->input->getMethod() !== 'POST' || !Session::checkToken('post')
            ) {
                throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
            }
            $data = $app->input->post->get('jform', [], 'array');
            if ($task === 'renderdesign') {
                $this->renderDesign($r, $data);
                return;
            }
            match ($task) {
                'savetype' => $r->catalog->saveType($data, (int) $user->id),
                'deletetype' => $r->catalog->deleteType((int) ($data['id'] ?? 0), (int) ($data['revision'] ?? 0), (int) $user->id),
                'refreshtags' => $r->catalog->refreshTags($r->gateway(), (int) $user->id),
                'savetags' => $r->catalog->saveTags((array) ($data['enabled'] ?? []), (array) ($data['ordering'] ?? []), (int) ($data['revision'] ?? 0), (int) $user->id),
                'savedesign', 'resetdesign' => $r->design->save($data, (int) ($data['revision'] ?? 0), (int) $user->id, $task === 'resetdesign'),
                'savescopes' => $r->catalog->saveScopes((array) ($data['scopes'] ?? []), (int) $user->id, (string) ($data['revision'] ?? '')),
                default => throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
            };
            $app->enqueueMessage(Text::_('COM_INTERCOM_SAVED'));
        } catch (\Throwable $e) {
            if ($task === 'renderdesign') {
                http_response_code(in_array($e->getCode(), [403, 409, 422], true) ? $e->getCode() : 500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR')]);
                $app->close();
            }
            $r->store->audit((int) $user->id, 'management.failed', 0, ['task' => $task, 'status' => $e->getCode()]);
            if ($e->getCode() === 403) {
                throw new \RuntimeException(Text::_('COM_INTERCOM_DENIED'), 403);
            }
            $app->enqueueMessage(Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR'), 'error');
        }
        $app->redirect('index.php?option=com_intercom&view=settings&section=' . $section);
    }
}

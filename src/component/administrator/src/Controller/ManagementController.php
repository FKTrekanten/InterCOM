<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class ManagementController extends BaseController
{
    public function execute($task)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $r = $app->bootComponent('com_intercom')->runtime;
        $section = ['savetype' => 'types', 'deletetype' => 'types', 'refreshtags' => 'tags', 'savetags' => 'tags', 'savescopes' => 'access'][$task] ?? 'types';
        try {
            if (
                !$user->authorise('core.admin', 'com_intercom') || !$user->authorise('core.manage', 'com_intercom')
                || $app->input->getMethod() !== 'POST' || !Session::checkToken('post')
            ) {
                throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
            }
            $data = $app->input->post->get('jform', [], 'array');
            match ($task) {
                'savetype' => $r->catalog->saveType($data, (int) $user->id),
                'deletetype' => $r->catalog->deleteType((int) ($data['id'] ?? 0), (int) ($data['revision'] ?? 0), (int) $user->id),
                'refreshtags' => $r->catalog->refreshTags($r->gateway(), (int) $user->id),
                'savetags' => $r->catalog->saveTags((array) ($data['enabled'] ?? []), (array) ($data['ordering'] ?? []), (int) ($data['revision'] ?? 0), (int) $user->id),
                'savescopes' => $r->catalog->saveScopes((array) ($data['scopes'] ?? []), (int) $user->id, (string) ($data['revision'] ?? '')),
                default => throw new \RuntimeException('COM_INTERCOM_DENIED', 403),
            };
            $app->enqueueMessage(Text::_('COM_INTERCOM_SAVED'));
        } catch (\Throwable $e) {
            $r->store->audit((int) $user->id, 'management.failed', 0, ['task' => $task, 'status' => $e->getCode()]);
            if ($e->getCode() === 403) {
                throw new \RuntimeException(Text::_('COM_INTERCOM_DENIED'), 403);
            }
            $app->enqueueMessage(Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR'), 'error');
        }
        $app->redirect('index.php?option=com_intercom&view=settings&section=' . $section);
    }
}

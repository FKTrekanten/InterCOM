<?php

namespace FKT\Plugin\Task\Intercom\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

final class Intercom extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;
    protected const TASKS_MAP = ['intercom.maintenance' => [
        'langConstPrefix' => 'PLG_TASK_INTERCOM_MAINTENANCE', 'method' => 'maintain']];

    public static function getSubscribedEvents(): array
    {
        return ['onTaskOptionsList' => 'advertiseRoutines', 'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm'];
    }

    protected function maintain(ExecuteTaskEvent $event): int
    {
        $runtime = $this->getApplication()->bootComponent('com_intercom')->runtime;
        $runtime->workflow(new User())->maintain((int) ($runtime->config['retention_days'] ?? 30));
        return 0;
    }
}

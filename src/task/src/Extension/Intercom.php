<?php

namespace FKT\Plugin\Task\Intercom\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
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
        try {
            $runtime = $this->getApplication()->bootComponent('com_intercom')->runtime;
            $ok = $runtime->workflow(new User())->maintain(
                (int) ($runtime->config['retention_days'] ?? 30),
                fn (string $label, \Throwable $error) => $this->logFailure($label, $error)
            );
            return $ok ? Status::OK : Status::KNOCKOUT;
        } catch (\Throwable $error) {
            $this->logFailure('Maintenance initialization', $error);
            return Status::KNOCKOUT;
        }
    }

    private function logFailure(string $label, \Throwable $error): void
    {
        // Exceptions can contain SQL, addresses or credentials. Log location and
        // class, plus only our known public message keys, never raw exception text.
        $key = preg_match('/^COM_INTERCOM_[A-Z0-9_]+$/D', $error->getMessage()) ? ' ' . $error->getMessage() : '';
        $message = sprintf('%s failed: %s%s (%s:%d)', $label, get_class($error), $key, basename($error->getFile()), $error->getLine());
        try {
            $this->logTask($message, 'warning');
        } catch (\Throwable) {
            // A broken log destination must not prevent scheduler cleanup either.
            try {
                error_log('InterCOM: ' . $message);
            } catch (\Throwable) {
                // Nothing else is safe to call; still return the failure status.
            }
        }
    }
}

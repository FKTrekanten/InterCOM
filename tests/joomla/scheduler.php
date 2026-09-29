<?php
require __DIR__ . '/bootstrap.php';
$app->bootComponent('com_scheduler');
\Joomla\CMS\Plugin\PluginHelper::importPlugin('task');
$options = new \Joomla\Component\Scheduler\Administrator\Task\TaskOptions();
$event = new \Joomla\Event\Event('onTaskOptionsList', ['subject'=>$options]);
$app->getDispatcher()->dispatch('onTaskOptionsList', $event);
$option = $options->findOption('intercom.maintenance');
check($option !== null, 'Joomla discovers maintenance routine');
$task = new \Joomla\Component\Scheduler\Administrator\Task\Task((object) [
    'id'=>999,'type'=>'intercom.maintenance','params'=>'{}','taskOption'=>$option]);
$event = new \Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent('onExecuteTask', ['subject'=>$task]);
$app->getDispatcher()->dispatch('onExecuteTask', $event);
check(($event->getResultSnapshot()['status'] ?? -1) === 0, 'Native scheduler executes maintenance successfully');

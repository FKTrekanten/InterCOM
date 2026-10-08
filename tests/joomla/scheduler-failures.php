<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Scheduler failure fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
require '/workspace/vendor/autoload.php';
$app->loadLanguage();

use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Service\Archive;
use FKT\Component\Intercom\Administrator\Service\Reconciliation;
use FKT\Component\Intercom\Administrator\Service\Workflow;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Log\Log;
use Joomla\Component\Scheduler\Administrator\Scheduler\Scheduler;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Database\DatabaseInterface;

$app->bootComponent('com_scheduler');
\Joomla\CMS\Plugin\PluginHelper::importPlugin('task');
$plugin = $app->bootPlugin('intercom', 'task');
$mockFactory = new class ('unused') extends \PHPUnit\Framework\TestCase {
    public function mock(string $class): object { return $this->createMock($class); }
};
$calls = [];
$fault = '';
$query = '';
$expireDuringRetention = false;
$elapsed = 0.0;
$fakeDb = $mockFactory->mock(DatabaseInterface::class);
$fakeDb->method('setQuery')->willReturnCallback(function ($sql) use (&$query, &$fault, &$calls, &$expireDuringRetention, &$elapsed, $db, $fakeDb) {
    $query = (string) $sql;
    $phase = str_contains($query, "SELECT provider FROM #__intercom_connections") ? 'retention'
        : (str_starts_with($query, 'SELECT f.filter_id') ? 'reconciliation'
        : (str_contains($query, "SELECT draft_id FROM #__intercom_archives WHERE state='sending'") ? 'archive' : ''));
    if ($phase !== '') {
        $calls[] = $phase;
        if ($phase === 'retention' && $expireDuringRetention) { $elapsed = 61; }
        if ($fault === 'exception' && $phase === 'retention') {
            throw new RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        if ($fault === $phase) {
            throw new TypeError('Injected secret@example.invalid password=private');
        }
    }
    $db->setQuery($sql);
    return $fakeDb;
});
foreach (['loadAssoc','loadAssocList','execute','transactionStart','transactionCommit','transactionRollback','quote','insertObject'] as $method) {
    $fakeDb->method($method)->willReturnCallback(fn (...$args) => $db->$method(...$args));
}
$store = new Store($fakeDb);
$gateway = new \FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway(fn () => 'synthetic', ['mode'=>'live','group_id'=>799991],
    static function () { throw new LogicException('No provider request expected'); });
$workflow = new Workflow($store, new FakeGateway(), new Policy([], []), 0, null,
    new Archive($store, '', static fn () => false, static fn () => false),
    new Reconciliation($store, $gateway, ['mode'=>'live','group_id'=>799991]), null, null,
    static function ($budget) use (&$calls, &$fault) {
        $calls[] = 'connection';
        if ($fault === 'connection') { throw new TypeError('Injected secret@example.invalid password=private'); }
    });
$runtime = new class ($workflow) {
    public array $config = ['retention_days'=>30];
    public function __construct(private Workflow $workflow) {}
    public function workflow(object $user): Workflow { return $this->workflow; }
};
$component = new class ($runtime, $app->bootComponent('com_intercom')) implements ComponentInterface {
    public function __construct(public object $runtime, private ComponentInterface $actual) {}
    public function getDispatcher(\Joomla\CMS\Application\CMSApplicationInterface $application): \Joomla\CMS\Dispatcher\DispatcherInterface {
        return $this->actual->getDispatcher($application);
    }
};
$stubApp = $mockFactory->mock(CMSApplication::class);
$stubApp->method('getLanguage')->willReturn($app->getLanguage());
$stubApp->method('bootComponent')->willReturnCallback(function () use (&$fault, $component) {
    if ($fault === 'initialization') { throw new Error('Injected secret@example.invalid password=private'); }
    return $component;
});
$plugin->setApplication($stubApp);
$logs = [];
$breakLogger = false;
Log::addLogger(['logger'=>'callback','callback'=>function ($entry) use (&$logs, &$breakLogger) {
    if (str_contains($entry->message, ' failed: ')) {
        $logs[] = $entry->message;
        if ($breakLogger) { throw new Error('Broken task logger'); }
    }
}], Log::ALL);
$ids = [];
$existing = $db->setQuery('SELECT id,state FROM #__scheduler_tasks')->loadAssocList();
try {
    $db->setQuery('UPDATE #__scheduler_tasks SET state=0')->execute();
    $makeTask = function (string $title) use ($db, &$ids): int {
        $row = (object) ['title'=>$title,'type'=>'intercom.maintenance','state'=>1,'priority'=>0,'params'=>'{}',
            'execution_rules'=>json_encode(['rule-type'=>'interval-minutes','interval-minutes'=>30]),
            'cron_rules'=>json_encode(['type'=>'interval','exp'=>30]),'next_execution'=>gmdate('Y-m-d H:i:s',time()-3600),
            'created'=>gmdate('Y-m-d H:i:s'),'times_executed'=>0,'times_failed'=>0];
        $db->insertObject('#__scheduler_tasks', $row);
        return $ids[] = (int) $db->insertid();
    };
    $failedId = $makeTask('InterCOM failure fixture');
    $nextId = $makeTask('InterCOM following task fixture');
    foreach (['initialization','connection','retention','reconciliation','archive','exception','logger'] as $case) {
        $fault = $case === 'logger' ? 'initialization' : $case;
        $breakLogger = $case === 'logger';
        $calls = [];
        $db->setQuery("UPDATE #__scheduler_tasks SET next_execution=UTC_TIMESTAMP()-INTERVAL 2 HOUR,locked=NULL WHERE id=$failedId")->execute();
        $db->setQuery("UPDATE #__scheduler_tasks SET next_execution=UTC_TIMESTAMP()-INTERVAL 1 HOUR,locked=NULL WHERE id=$nextId")->execute();
        $task = (new Scheduler())->runTask([]);
        check($task !== null && (int)$task->get('id') === $failedId, "Native queue selects the failing task: $case");
        $row = $db->setQuery("SELECT * FROM #__scheduler_tasks WHERE id=$failedId")->loadAssoc();
        check($row['locked'] === null && (int)$row['last_exit_code'] === Status::KNOCKOUT, "Failure is visible and task lock is released: $case");
        check(strtotime($row['next_execution'].' UTC') > time() && (int)$row['times_failed'] > 0, "Failure advances execution instead of immediate reselection: $case");
        if (in_array($case, ['connection','retention','reconciliation','archive','exception'], true)) {
            check($calls === ['connection','retention','reconciliation','archive'], "Remaining independent phases run after failure: $case");
        }
        $fault = '';
        $breakLogger = false;
        $following = (new Scheduler())->runTask([]);
        check($following !== null && (int)$following->get('id') === $nextId && $following->isSuccess(), "Next due task runs successfully after failure: $case");
        check($db->setQuery("SELECT locked FROM #__scheduler_tasks WHERE id=$nextId")->loadResult() === null, 'Following task also releases its lock');
    }
    $calls = [];
    $expireDuringRetention = true;
    $budget = new \FKT\Component\Intercom\Administrator\Domain\MaintenanceBudget(60, static function () use (&$elapsed): float { return $elapsed; });
    check($workflow->maintain(30, static function () { throw new LogicException('No failure expected'); }, $budget), 'Budget exhaustion yields normally without a failure');
    check($calls === ['connection','retention'], 'An exhausted overall run budget defers the remaining phases');
    $expireDuringRetention = false;
    check(count($logs) >= 6, 'Task warnings identify every injected failure');
    foreach ($logs as $message) {
        check(!str_contains($message, 'secret@example.invalid') && !str_contains($message, 'password=private'), 'Task warnings omit raw exception secrets');
    }
    echo "NATIVE SCHEDULER FAILURE RECOVERY OK\n";
} finally {
    $plugin->setApplication($app);
    if ($ids) { $db->setQuery('DELETE FROM #__scheduler_tasks WHERE id IN ('.implode(',',$ids).')')->execute(); }
    foreach ($existing as $row) { $db->setQuery('UPDATE #__scheduler_tasks SET state='.(int)$row['state'].' WHERE id='.(int)$row['id'])->execute(); }
}

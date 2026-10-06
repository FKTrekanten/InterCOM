<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Maintenance budget fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
require '/workspace/vendor/autoload.php';

use FKT\Component\Intercom\Administrator\Domain\MaintenanceBudget;
use FKT\Component\Intercom\Administrator\Service\Archive;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailerInterface;

$runtime = $app->bootComponent('com_intercom')->runtime;
$store = $runtime->store;
$store->begin();
$factoryContainer = new ReflectionProperty(\Joomla\CMS\Factory::class, 'container');
try {
    $now = 0.0;
    $clock = static function () use (&$now): float { return $now; };
    $sendCalls = [];
    $archive = new Archive($store, 'board@example.invalid', static fn () => true,
        function ($address, $payload, $timeout) use (&$sendCalls, &$now): bool {
            $sendCalls[] = $timeout;
            $now += 21;
            return true;
        });
    $payload = json_encode(['mailing_id'=>899991,'subject'=>'Budget fixture','html'=>'<p>Fixture</p>','text'=>'Fixture']);
    foreach ([899991,899992] as $id) {
        $store->execute("INSERT INTO #__intercom_archives(draft_id,address,payload,state,due_at,updated_at) VALUES ($id,'board@example.invalid',".$store->q($payload).",'pending',0,UTC_TIMESTAMP())");
    }
    $archive->maintain(new MaintenanceBudget(60, $clock));
    check($sendCalls === [10], 'Archive transport receives a bounded timeout and stops after its phase budget');
    check($store->row('SELECT state FROM #__intercom_archives WHERE draft_id=899991')['state']==='submitted', 'Accepted archive is recorded before yielding');
    check($store->row('SELECT state FROM #__intercom_archives WHERE draft_id=899992')['state']==='pending', 'Work after budget exhaustion stays pending for a later run');
    $now = 0;
    $sends = 0;
    $slowStatus = new Archive($store, '', function () use (&$now): bool { $now = 21; return true; },
        function () use (&$sends): bool { $sends++; return true; });
    $slowStatus->maintain(new MaintenanceBudget(60, $clock));
    check($sends===0 && $store->row('SELECT state FROM #__intercom_archives WHERE draft_id=899992')['state']==='pending', 'Expired provider check does not claim or start an archive send');
    $brokenSmtp = new Archive($store, '', static fn () => true, function () use (&$sends): bool {
        $sends++; throw new TypeError('Synthetic SMTP error');
    });
    $brokenSmtp->maintain();
    $brokenSmtp->maintain();
    check($sends===1 && $store->row('SELECT state FROM #__intercom_archives WHERE draft_id=899992')['state']==='uncertain', 'SMTP Error retains an uncertain outcome without automatic retry');

    // Exercise the real Runtime archive transport with a native Mail instance;
    // its send override prevents any network/email while recording configured limits.
    $mail = new class extends Mail {
        public array $limits = [];
        public function send() {
            $this->limits = [$this->Timeout, $this->getSMTPInstance()->Timelimit];
            return true;
        }
    };
    $stubFactory = new class ($mail) implements MailerFactoryInterface {
        public function __construct(private Mail $mail) {}
        public function createMailer(?\Joomla\Registry\Registry $settings = null): MailerInterface { return $this->mail; }
    };
    $transportContainer = $container->createChild();
    $transportContainer->set(MailerFactoryInterface::class, $stubFactory);
    $factoryContainer->setValue(null, $transportContainer);
    $transport = (new ReflectionProperty(Archive::class, 'send'))->getValue($runtime->archive());
    check($transport('board@example.invalid', json_decode($payload, true), 3) === true && $mail->limits === [3,3], 'Runtime applies the remaining timeout to both SMTP connection and command waits');
} finally {
    $factoryContainer->setValue(null, $container);
    $store->rollback();
}
echo "MAINTENANCE BUDGETS OK\n";

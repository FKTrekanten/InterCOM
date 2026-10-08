<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Renewal fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
$app->loadLanguage();
$app->getLanguage()->load('com_intercom', JPATH_ADMINISTRATOR.'/components/com_intercom', 'en-GB', true);

use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use FKT\Component\Intercom\Administrator\Domain\MaintenanceBudget;
use FKT\Component\Intercom\Administrator\Domain\TokenExchangeFailure;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity;
use FKT\Component\Intercom\Administrator\Service\Connection;

$s = $app->bootComponent('com_intercom')->runtime->store;
$cipher = new CredentialCipher($app->get('secret'));
$backup = $s->row("SELECT * FROM #__intercom_connections WHERE provider='cleverreach'");
$renewalBackup = $s->row("SELECT * FROM #__intercom_connection_renewals WHERE provider='cleverreach'");
$auditStart = (int) ($s->row('SELECT MAX(id) AS id FROM #__intercom_audit')['id'] ?? 0);
$now = 1791028800;
$clock = static function () use (&$now): int { return $now; };
$lookupFails = false;
$lookup = new CleverReachIdentity(static function ($token) use (&$lookupFails) {
    if ($lookupFails) { throw new RuntimeException('private provider response'); }
    return ['id' => $token === 'other-account' ? '999' : '231113'];
});
$latestRefresh = 'refresh-0';
$count = 0;
$history = [];
$exchange = static function ($form, $values) use (&$latestRefresh, &$count, &$history, &$now) {
    check($form['refresh_token'] === $latestRefresh, 'Exchange uses the latest rotating refresh token');
    $count++;
    $history[] = $now;
    $latestRefresh = 'refresh-' . $count;
    return array_merge($values, ['access_token' => 'access-' . $count, 'refresh_token' => $latestRefresh, 'expires_at' => $now + 30 * 86400]);
};
$c = new Connection($s, $cipher, $lookup, $exchange, $clock);
$reset = static function (int $lifetime = 30 * 86400) use ($s, $cipher, &$now, &$latestRefresh, &$count, &$history, &$lookupFails): void {
    $latestRefresh = 'refresh-0'; $count = 0; $history = []; $lookupFails = false;
    $s->execute("UPDATE #__intercom_connections SET account_id='231113',envelope=" . $s->q($cipher->encrypt([
        'access_token' => 'access-0', 'refresh_token' => $latestRefresh, 'client_id' => 'visible-app',
        'client_secret' => 'never-render-this-secret', 'expires_at' => $now + $lifetime,
    ])) . " WHERE provider='cleverreach'");
    $meta = ['state'=>'healthy', 'renew_at'=>Connection::renewAt($now + $lifetime, $now), 'next_check'=>0];
    $s->execute('UPDATE #__intercom_connection_renewals SET metadata=' . $s->q(json_encode($meta)) . ",pending='' WHERE provider='cleverreach'");
};
$meta = static fn (): array => json_decode($s->row("SELECT metadata FROM #__intercom_connection_renewals WHERE provider='cleverreach'")['metadata'], true);
$expect = static function (callable $action, string $message): void {
    try { $action(); throw new LogicException('Expected failure'); }
    catch (RuntimeException $e) { check($e->getMessage() === $message, 'Expected sanitized failure: ' . $message); }
};
try {
    $reset();
    $initial = $now;
    for ($day = 0; $day <= 90; $day++) {
        $now = $initial + $day * 86400;
        $c->maintain(['mode'=>'live']);
        check($c->credentials()['expires_at'] > $now, 'Idle connection remains valid on day ' . $day);
    }
    check($count === 3 && $history === [$initial+23*86400, $initial+46*86400, $initial+69*86400], 'Ninety idle days renew exactly at days 23, 46 and 69');
    $status = $c->status(['mode'=>'live'], true);
    check($status['automatic'] && $status['client_id']==='visible-app' && $status['account_id']==='231113', 'Healthy backend status includes client/account and automatic renewal');
    $encoded = json_encode($status);
    check(!str_contains($encoded,'never-render-this-secret') && !str_contains($encoded,'access-') && !str_contains($encoded,'refresh-'), 'Status projection excludes every secret');
    check(!$c->status(['mode'=>'fake'],true)['automatic'] && !$c->status(['mode'=>'live'],false)['automatic'], 'Simulation or disabled scheduler cannot promise renewal');
    $c->save(['client_id'=>'visible-app'], 42);
    check($meta()['last_success'] === $history[2], 'No-op options credential save preserves renewal metadata');
    $now += 49*3600;
    check(!$c->status(['mode'=>'live'],true)['automatic'], 'Missed checks suppress the automatic-renewal assurance');

    $reset(); $now += 23*86400;
    $before = $c->credentials();
    $lookupFails = true;
    $expect(fn () => $c->maintain(['mode'=>'live']), 'COM_INTERCOM_ACCOUNT_UNAVAILABLE');
    check($count===1 && $meta()['state']==='pending' && $c->credentials()===$before, 'Failed validation retains active credentials and saves pending pair');
    $pending = $s->row("SELECT pending FROM #__intercom_connection_renewals WHERE provider='cleverreach'")['pending'];
    check($cipher->decrypt($pending)['refresh_token']==='refresh-1' && !str_contains($pending,'refresh-1'), 'Rotated pending refresh token is durable and encrypted');
    // A separate connection object simulates a later process after the failure.
    $lookupFails = false; $now += 3600;
    $later = new Connection($s,$cipher,$lookup,$exchange,$clock);
    $later->maintain(['mode'=>'live']);
    check($count===1 && $later->credentials()['refresh_token']==='refresh-1' && $meta()['state']==='healthy', 'Recovery validates the stored pair without another exchange');

    $reset(); $now += 23*86400;
    $racing = new Connection($s,$cipher,$lookup,static function () { throw new LogicException('Duplicate exchange'); },$clock);
    $first = new Connection($s,$cipher,$lookup,static function ($form,$values) use ($racing,$exchange,$expect) {
        // Scheduled runner skips an attempt already claimed; expired on-demand calls report busy.
        $racing->maintain(['mode'=>'live']);
        return $exchange($form,$values);
    },$clock);
    $first->maintain(['mode'=>'live']);
    check($count===1, 'Competing scheduled requests cannot exchange twice');

    $reset(); $now += 31*86400;
    $first = new Connection($s,$cipher,$lookup,static function ($form,$values) use ($racing,$exchange,$expect) {
        $expect(fn () => $racing->token(), 'COM_INTERCOM_RENEWAL_BUSY');
        return $exchange($form,$values);
    },$clock);
    check($first->token()==='access-1' && $count===1, 'Expired on-demand renewal is serialized with competing calls');
    check(!$first->status(['mode'=>'live'],true)['automatic'], 'On-demand success does not masquerade as a scheduled check');

    $reset(); $now += 23*86400;
    $unknownCount = 0;
    $unknown = new Connection($s,$cipher,$lookup,static function () use (&$unknownCount) {
        $unknownCount++; throw new TokenExchangeFailure('uncertain');
    },$clock);
    $expect(fn () => $unknown->maintain(['mode'=>'live']), 'COM_INTERCOM_RENEWAL_UNCERTAIN');
    $now += 86400;
    $expect(fn () => $unknown->maintain(['mode'=>'live']), 'COM_INTERCOM_RENEWAL_RECONNECT');
    check($unknownCount===1 && $meta()['state']==='uncertain', 'A lost response is never retried with the previous refresh token');

    $reset(); $now += 23*86400;
    $abandoned = ['state'=>'exchanging','attempt'=>'abandoned','attempt_at'=>$now-121,'next_check'=>0];
    $s->execute('UPDATE #__intercom_connection_renewals SET metadata='.$s->q(json_encode($abandoned))." WHERE provider='cleverreach'");
    $expect(fn () => $c->maintain(['mode'=>'live']), 'COM_INTERCOM_RENEWAL_UNCERTAIN');
    check($count===0, 'An interrupted exchange is quarantined without replay');

    foreach (['retry','reconnect'] as $outcome) {
        $reset(); $now += 23*86400; $failedCount = 0;
        $failure = new Connection($s,$cipher,$lookup,static function ($form,$values) use (&$failedCount,$outcome,$exchange) {
            if (++$failedCount===1) { throw new TokenExchangeFailure($outcome); }
            return $exchange($form,$values);
        },$clock);
        $expect(fn () => $failure->maintain(['mode'=>'live']), $outcome==='retry' ? 'COM_INTERCOM_PROVIDER_ERROR' : 'COM_INTERCOM_RENEWAL_RECONNECT');
        check($meta()['state']===$outcome, 'Failure classification saved: '.$outcome);
        $failure->maintain(['mode'=>'live']);
        check($failedCount===1, 'Backoff prevents immediate retry');
        $now += 3600;
        if ($outcome==='retry') {
            $failure->maintain(['mode'=>'live']);
            check($failedCount===2 && $meta()['state']==='healthy', 'Confirmed safe failure retries after backoff');
        }
    }

    $reset(); $now += 23*86400;
    $mismatch = new Connection($s,$cipher,$lookup,static fn ($form,$values)=>array_merge($values,['access_token'=>'other-account','refresh_token'=>'foreign-refresh','expires_at'=> $now+30*86400]),$clock);
    $expect(fn () => $mismatch->maintain(['mode'=>'live']), 'COM_INTERCOM_ACCOUNT_MISMATCH');
    check($mismatch->accountId()==='231113' && $mismatch->credentials()['access_token']==='access-0' && $meta()['state']==='reconnect', 'Renewal cannot switch the pinned account');

    $reset(); $now += 23*86400;
    $superseded = new Connection($s,$cipher,$lookup,static function ($form,$values) use ($c,$exchange) {
        $c->importTokens('manual-access','manual-refresh',30*86400,42);
        return $exchange($form,$values);
    },$clock);
    $superseded->maintain(['mode'=>'live']);
    check($c->credentials()['access_token']==='manual-access' && $c->credentials()['refresh_token']==='manual-refresh' && $meta()['state']==='healthy', 'Late exchange cannot overwrite manually replaced credentials');

    $reset(); $now += 31*86400;
    $s->begin();
    try { $expect(fn () => $c->token(), 'COM_INTERCOM_RENEWAL_DEFERRED'); }
    finally { $s->rollback(); }
    check($count===0, 'Refresh cannot run inside a transaction that might roll back its token pair');
    check($c->token()==='access-1', 'Expired token renews when called outside the mailing transaction');

    $reset(); $now += 31*86400;
    $c->maintain(['mode'=>'fake']);
    $c->maintain(['mode'=>'live'], new MaintenanceBudget(0));
    check($count===0, 'Simulation and exhausted budget make no provider calls');
    $c->importTokens('manual-access','',3600,42);
    check(!$c->status(['mode'=>'live'],true)['automatic'], 'Missing refresh token prevents automatic-renewal assurance');

    // Render the same shared status view used in backend Options and Dashboard.
    $now = strtotime('2026-10-08 08:25 UTC');
    $reset(); $c->maintain(['mode'=>'live']);
    $connectionStatus = $c->status(['mode'=>'live'],true);
    $app->set('offset','Europe/Copenhagen');
    $render = static function (array $connectionStatus): string {
        ob_start(); require JPATH_ADMINISTRATOR.'/components/com_intercom/tmpl/connection-status.php'; return ob_get_clean();
    };
    $html = $render($connectionStatus);
    check(str_contains($html,'visible-app') && str_contains($html,'231113') && str_contains($html,'Saturday, 07 November 2026 09:25') && str_contains($html,'Europe/Copenhagen') && str_contains($html,'renewed automatically'), 'Backend renders account, client ID, localized date and timezone with DST');
    check(!str_contains($html,'never-render-this-secret') && !str_contains($html,'access-0') && !str_contains($html,'refresh-0'), 'Rendered backend details contain no secret material');
    $connectionStatus['client_id'] = '<script>unsafe</script>';
    $connectionStatus['automatic'] = false;
    $connectionStatus['state'] = 'uncertain';
    $html = $render($connectionStatus);
    check(!str_contains($html,'<script>') && !str_contains($html,'renewed automatically') && str_contains($html,'Reconnect'), 'Backend escapes client ID and replaces assurance when renewal is blocked');
    $app->getLanguage()->load('com_intercom', JPATH_ADMINISTRATOR.'/components/com_intercom', 'da-DK', true);
    $connectionStatus = $c->status(['mode'=>'live'],true);
    $html = $render($connectionStatus);
    check(str_contains($html,'Forbundet til CleverReach-konto 231113') && str_contains($html,'07.') && str_contains($html,'2026 09:25') && str_contains($html,'fornyes automatisk'), 'Backend uses Danish connection text and date format');
    $events = json_encode($s->rows('SELECT context FROM #__intercom_audit WHERE id>'.$auditStart));
    check(!str_contains($events,'never-render-this-secret') && !str_contains($events,'refresh-') && !str_contains($events,'access-'), 'Renewal audit never records token material');
    echo "CONNECTION RENEWAL OK\n";
} finally {
    $s->rollback();
    $s->execute('UPDATE #__intercom_connections SET account_id='.$s->q($backup['account_id']).',envelope='.$s->q($backup['envelope'])." WHERE provider='cleverreach'");
    $s->execute('UPDATE #__intercom_connection_renewals SET metadata='.$s->q($renewalBackup['metadata']).',pending='.$s->q($renewalBackup['pending'])." WHERE provider='cleverreach'");
    $s->execute('DELETE FROM #__intercom_audit WHERE id>'.$auditStart);
}

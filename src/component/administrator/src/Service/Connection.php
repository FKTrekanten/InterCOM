<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use FKT\Component\Intercom\Administrator\Domain\MaintenanceBudget;
use FKT\Component\Intercom\Administrator\Domain\TokenExchangeFailure;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity;

final class Connection
{
    public function __construct(private Store $store, private CredentialCipher $cipher, private ?CleverReachIdentity $identity = null, private ?\Closure $exchangeTransport = null, private ?\Closure $clock = null)
    {
        $this->identity ??= new CleverReachIdentity();
        $this->clock ??= static fn (): int => time();
    }

    public function accountId(): string
    {
        return (string) ($this->store->row("SELECT account_id FROM #__intercom_connections WHERE provider='cleverreach'")['account_id'] ?? '');
    }

    public function pin(int $actor): string
    {
        return $this->store->transaction(function () use ($actor): string {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $values = $this->credentials();
            if (empty($values['access_token'])) {
                throw new \RuntimeException('COM_INTERCOM_ACCOUNT_UNPINNED');
            }
            $id = $this->identity->resolve($values['access_token']);
            $current = $this->accountId();
            if ($current !== '' && $current !== $id) {
                throw new \RuntimeException('COM_INTERCOM_ACCOUNT_MISMATCH');
            }
            $this->pinIdentity($id, $actor);
            return $id;
        });
    }

    private function pinIdentity(string $id, int $actor): void
    {
        if ($this->accountId() !== $id) {
            $this->store->execute('UPDATE #__intercom_connections SET account_id=' . $this->store->q($id) . " WHERE provider='cleverreach'");
            $this->store->audit($actor, 'connection.identity_verified', 0, ['account_id' => $id]);
        }
    }

    private function replaceTokens(array $values, int $actor): void
    {
        $candidate = $this->identity->resolve($values['access_token']);
        $previous = $this->accountId();
        $old = $this->credentials();
        // Pin legacy installations using independent evidence from the previous token.
        // Expiry/scopes/errors cannot be bypassed by trusting the replacement token.
        if ($previous === '' && !empty($old['access_token'])) {
            try {
                $previous = $this->identity->resolve($old['access_token']);
            } catch (\Throwable) {
                if ($this->store->hasLiveReservations()) {
                    throw new \RuntimeException('COM_INTERCOM_ACCOUNT_UNPINNED');
                }
            }
        }
        $changed = $previous !== $candidate;
        if ($changed && $this->store->hasLiveReservations()) {
            throw new \RuntimeException($previous === '' ? 'COM_INTERCOM_ACCOUNT_UNPINNED' : 'COM_INTERCOM_ACCOUNT_MISMATCH');
        }
        if ($changed) {
            // Remote IDs are account-local. Never reuse another account's cached pool,
            // tag catalogue or test approval, even when its last lease has been freed.
            $this->store->execute('DELETE FROM #__intercom_filters WHERE draft_id IS NULL AND group_id!=0');
            $this->store->execute('UPDATE #__intercom_tags SET available=0,enabled=0 WHERE list_id!=0');
            $this->store->execute('UPDATE #__intercom_catalogues SET revision=revision+1,refreshed_at=NULL WHERE list_id!=0');
            $this->store->execute("UPDATE #__intercom_drafts SET tested_revision=NULL,tested_fingerprint=NULL,state='draft',mailing_id=0,filter_id=NULL WHERE delivery_mode='live' AND state IN ('draft','tested')");
            $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
            $params = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            $params['release_verified'] = false;
            $this->store->execute('UPDATE #__extensions SET params=' . $this->store->q(json_encode($params, JSON_THROW_ON_ERROR)) . " WHERE element='com_intercom' AND type='component'");
            $this->store->audit($actor, 'connection.account_changed', 0, ['account_id' => $candidate]);
        }
        $this->pinIdentity($candidate, $actor);
        $this->persist($values);
    }

    public function credentials(): array
    {
        $row = $this->store->row("SELECT envelope FROM #__intercom_connections WHERE provider='cleverreach'");
        return empty($row['envelope']) ? [] : $this->cipher->decrypt($row['envelope']);
    }

    public function save(array $values, int $actor): void
    {
        $this->store->transaction(function () use ($values, $actor): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $current = $this->credentials();
            $changed = false;
            foreach (['client_id', 'client_secret'] as $name) {
                if (!empty($values[$name]) && ($current[$name] ?? '') !== $values[$name]) {
                    if ($this->store->hasLiveReservations()) {
                        throw new \RuntimeException('COM_INTERCOM_LIVE_RESERVATIONS');
                    }
                    $changed = true;
                    $current[$name] = $values[$name];
                    unset($current['access_token'], $current['refresh_token'], $current['expires_at']);
                }
            }
            if ($changed) {
                $this->persist($current);
            }
            $this->store->audit($actor, 'connection.credentials_changed');
        });
    }

    private function persist(array $values, bool $reset = true): void
    {
        $this->store->execute("UPDATE #__intercom_connections SET envelope=" . $this->store->q($this->cipher->encrypt($values))
            . " WHERE provider='cleverreach'");
        if ($reset) {
            $this->writeRenewal(['state' => 'healthy', 'next_check' => 0,
                'renew_at' => self::renewAt((int) ($values['expires_at'] ?? 0), ($this->clock)())], '');
        }
    }

    private function exchange(#[\SensitiveParameter] array $form, #[\SensitiveParameter] array $credentials, ?MaintenanceBudget $budget = null): array
    {
        if ($this->exchangeTransport !== null) {
            return ($this->exchangeTransport)($form, $credentials, $budget);
        }
        $curl = curl_init('https://rest.cleverreach.com/oauth/token.php');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_USERPWD => ($credentials['client_id'] ?? '') . ':' . ($credentials['client_secret'] ?? ''),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $budget?->timeout(20) ?? 20]);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        $tokens = json_decode((string) $raw, true);
        $validPair = is_array($tokens);
        foreach (['access_token', 'refresh_token'] as $key) {
            $value = $tokens[$key] ?? null;
            $validPair = $validPair && is_string($value) && $value !== '' && strlen($value) <= 16384 && !preg_match('/[\\x00-\\x20\\x7f]/', $value);
        }
        $lifetime = filter_var($tokens['expires_in'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 315360000]]);
        if ($error || $code !== 200 || !$validPair || $lifetime === false) {
            // A successful exchange followed by a malformed/lost response may have rotated the token.
            throw new TokenExchangeFailure(TokenExchangeFailure::classify($error, $code, $tokens));
        }
        return array_merge($credentials, ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
            'expires_at' => ($this->clock)() + $lifetime]);
    }

    public function authorize(string $code, string $redirect, int $actor): void
    {
        $this->store->transaction(function () use ($code, $redirect, $actor): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $this->replaceTokens($this->exchange(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect], $this->credentials()), $actor);
            $this->store->audit($actor, 'connection.authorized');
        });
    }

    public function importTokens(#[\SensitiveParameter] string $access, #[\SensitiveParameter] string $refresh, int $lifetime, int $actor): void
    {
        if (
            $access === '' || strlen($access) > 16384 || strlen($refresh) > 16384
            || preg_match('/[\x00-\x20\x7f]/', $access . $refresh) || $lifetime < 1 || $lifetime > 315360000
        ) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_TOKENS');
        }
        $this->store->transaction(function () use ($access, $refresh, $lifetime, $actor): void {
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $values = $this->credentials();
            $values['access_token'] = $access;
            // Never pair a new access token with a previous account's refresh token.
            $values['refresh_token'] = $refresh;
            $values['expires_at'] = ($this->clock)() + $lifetime;
            $this->replaceTokens($values, $actor);
            $this->store->audit($actor, 'connection.tokens_imported', 0, ['expires_at' => $values['expires_at'], 'refresh_available' => $refresh !== '']);
        });
    }

    /** Renew outside mailing transactions so a later rollback cannot lose rotated credentials. */
    public function token(?MaintenanceBudget $budget = null): string
    {
        $values = $this->credentials();
        if (!empty($values['access_token']) && (int) ($values['expires_at'] ?? 0) > ($this->clock)()) {
            if ($this->accountId() === '') {
                $this->pin(0);
            }
            return $values['access_token'];
        }
        $this->renew($budget ?? new MaintenanceBudget(30));
        $values = $this->credentials();
        if (empty($values['access_token']) || (int) ($values['expires_at'] ?? 0) <= ($this->clock)()) {
            throw new \RuntimeException('COM_INTERCOM_TOKEN_EXPIRED');
        }
        return $values['access_token'];
    }

    public static function renewAt(int $expiry, int $issued): int
    {
        $lifetime = max(0, $expiry - $issued);
        return $expiry - min(7 * 86400, (int) floor($lifetime / 4));
    }

    private function renewal(bool $lock = false): array
    {
        $row = $this->store->row("SELECT metadata,pending FROM #__intercom_connection_renewals WHERE provider='cleverreach'" . ($lock ? ' FOR UPDATE' : ''));
        return ['meta' => json_decode($row['metadata'] ?? '{}', true, 32, JSON_THROW_ON_ERROR), 'pending' => $row['pending'] ?? ''];
    }

    private function writeRenewal(array $meta, string $pending): void
    {
        $this->store->execute('UPDATE #__intercom_connection_renewals SET metadata=' . $this->store->q(json_encode($meta, JSON_THROW_ON_ERROR))
            . ',pending=' . $this->store->q($pending) . " WHERE provider='cleverreach'");
    }

    private function lock(): void
    {
        $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
    }

    public function maintain(array $config, ?MaintenanceBudget $budget = null): void
    {
        $budget = ($budget ?? new MaintenanceBudget())->limit(30);
        if (($config['mode'] ?? 'fake') !== 'live' || $budget->expired()) {
            return;
        }
        $values = $this->credentials();
        if (empty($values['access_token'])) {
            return;
        }
        $now = ($this->clock)();
        $meta = $this->renewal()['meta'];
        if ((int) ($meta['next_check'] ?? 0) > $now) {
            return;
        }
        $this->renew($budget, true);
    }

    private function renew(MaintenanceBudget $budget, bool $scheduled = false): void
    {
        if ($this->store->inTransaction()) {
            // Callers must preflight before taking mailing/filter locks.
            throw new \RuntimeException('COM_INTERCOM_RENEWAL_DEFERRED');
        }
        if ($budget->expired()) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        $now = ($this->clock)();
        $claim = $this->store->transaction(function () use ($now, $scheduled): ?array {
            $this->lock();
            $row = $this->renewal(true);
            $meta = $row['meta'];
            $values = $this->credentials();
            if (!$scheduled && !empty($values['access_token']) && (int) ($values['expires_at'] ?? 0) > $now) {
                return null;
            }
            if ($scheduled && (int) ($meta['next_check'] ?? 0) > $now) {
                return null;
            }
            if ($scheduled) {
                $meta['last_check'] = $now;
                $due = (int) ($meta['renew_at'] ?? ((int) ($values['expires_at'] ?? 0) - 7 * 86400));
                if (($meta['state'] ?? 'healthy') === 'healthy' && $due > $now) {
                    $meta['renew_at'] = $due;
                    $meta['next_check'] = min($now + 86400, $due);
                    $this->writeRenewal($meta, $row['pending']);
                    return null;
                }
            }
            if (in_array($meta['state'] ?? '', ['uncertain', 'reconnect'], true)) {
                $meta['next_check'] = $now + 86400;
                $this->writeRenewal($meta, $row['pending']);
                return ['error' => 'COM_INTERCOM_RENEWAL_RECONNECT'];
            }
            if (($meta['state'] ?? '') === 'exchanging') {
                // Never replay an abandoned exchange: its response may have been lost.
                if ($now - (int) ($meta['attempt_at'] ?? 0) > 120) {
                    $meta['state'] = 'uncertain';
                    $meta['error'] = 'COM_INTERCOM_RENEWAL_UNCERTAIN';
                    $meta['next_check'] = $now + 86400;
                    $this->writeRenewal($meta, $row['pending']);
                    return ['error' => 'COM_INTERCOM_RENEWAL_UNCERTAIN'];
                }
                return ['error' => 'COM_INTERCOM_RENEWAL_BUSY'];
            }
            if (($meta['state'] ?? '') === 'pending' && $row['pending'] !== '') {
                $this->writeRenewal($meta, $row['pending']);
                return ['attempt' => $meta['attempt'], 'values' => $this->cipher->decrypt($row['pending']), 'validate' => true];
            }
            if (($meta['state'] ?? '') === 'retry' && (int) ($meta['next_check'] ?? 0) > $now) {
                return ['error' => 'COM_INTERCOM_RENEWAL_RETRY'];
            }
            if (empty($values['refresh_token']) || empty($values['client_id']) || empty($values['client_secret'])) {
                $meta['state'] = 'reconnect';
                $meta['error'] = 'COM_INTERCOM_TOKEN_EXPIRED';
                $meta['next_check'] = $now + 86400;
                $this->writeRenewal($meta, '');
                return ['error' => 'COM_INTERCOM_TOKEN_EXPIRED'];
            }
            if ($this->accountId() === '') {
                $meta['state'] = 'reconnect';
                $meta['error'] = 'COM_INTERCOM_ACCOUNT_UNPINNED';
                $meta['next_check'] = $now + 86400;
                $this->writeRenewal($meta, '');
                return ['error' => 'COM_INTERCOM_ACCOUNT_UNPINNED'];
            }
            $meta['attempt'] = bin2hex(random_bytes(16));
            $meta['attempt_at'] = $now;
            $meta['state'] = 'exchanging';
            $meta['next_check'] = $now + 3600;
            unset($meta['error']);
            $this->writeRenewal($meta, '');
            return ['attempt' => $meta['attempt'], 'values' => $values, 'validate' => false];
        });
        if ($claim === null) {
            return;
        }
        if (isset($claim['error'])) {
            throw new \RuntimeException($claim['error']);
        }
        $attempt = $claim['attempt'];
        $values = $claim['values'];
        if (!$claim['validate']) {
            try {
                $values = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $values['refresh_token']], $values, $budget);
                $saved = $this->store->transaction(function () use ($attempt, $values): bool {
                    $this->lock();
                    $row = $this->renewal(true);
                    if (($row['meta']['attempt'] ?? '') !== $attempt) {
                        return false; // A manual reconnect/import has superseded this attempt.
                    }
                    $meta = $row['meta'];
                    $meta['state'] = 'pending';
                    $this->writeRenewal($meta, $this->cipher->encrypt($values));
                    return true;
                });
                if (!$saved) {
                    return; // The newer manually supplied connection is authoritative.
                }
            } catch (\Throwable $error) {
                $outcome = $error instanceof TokenExchangeFailure ? $error->outcome : 'uncertain';
                $this->failRenewal($attempt, $outcome, $outcome === 'uncertain' ? 'COM_INTERCOM_RENEWAL_UNCERTAIN' : ($outcome === 'retry' ? 'COM_INTERCOM_RENEWAL_RETRY' : 'COM_INTERCOM_RENEWAL_RECONNECT'));
                throw new \RuntimeException($outcome === 'uncertain' ? 'COM_INTERCOM_RENEWAL_UNCERTAIN' : $error->getMessage());
            }
        }
        try {
            if ($budget->expired()) {
                throw new \RuntimeException('COM_INTERCOM_ACCOUNT_UNAVAILABLE');
            }
            $id = $this->identity->resolve($values['access_token'], $budget->timeout(10));
            $this->store->transaction(function () use ($attempt, $values, $id): void {
                $this->lock();
                $row = $this->renewal(true);
                if (($row['meta']['attempt'] ?? '') !== $attempt) {
                    return;
                }
                if ($this->accountId() !== $id) {
                    throw new \RuntimeException('COM_INTERCOM_ACCOUNT_MISMATCH');
                }
                if ((int) ($values['expires_at'] ?? 0) <= ($this->clock)()) {
                    throw new \RuntimeException('COM_INTERCOM_RENEWAL_RECONNECT');
                }
                $this->persist($values, false);
                $now = ($this->clock)();
                $this->writeRenewal(['state' => 'healthy', 'last_check' => (int) ($row['meta']['last_check'] ?? 0), 'last_success' => $now,
                    'renew_at' => self::renewAt((int) $values['expires_at'], $now), 'next_check' => $now + min(86400, max(1, self::renewAt((int) $values['expires_at'], $now) - $now))], '');
                $this->store->audit(0, 'connection.refreshed', 0, ['expires_at' => (int) $values['expires_at']]);
            });
        } catch (\Throwable $error) {
            $reconnect = in_array($error->getMessage(), ['COM_INTERCOM_ACCOUNT_MISMATCH', 'COM_INTERCOM_RENEWAL_RECONNECT'], true);
            $public = $reconnect ? $error->getMessage() : 'COM_INTERCOM_ACCOUNT_UNAVAILABLE';
            $this->failRenewal($attempt, $reconnect ? 'reconnect' : 'pending', $public);
            throw new \RuntimeException($public);
        }
    }

    private function failRenewal(string $attempt, string $state, string $error): void
    {
        $this->store->transaction(function () use ($attempt, $state, $error): void {
            $this->lock();
            $row = $this->renewal(true);
            $meta = $row['meta'];
            if (($meta['attempt'] ?? '') !== $attempt) {
                return;
            }
            if ($state === 'uncertain' && $row['pending'] !== '') {
                $state = 'pending';
                $error = 'COM_INTERCOM_ACCOUNT_UNAVAILABLE';
            }
            $meta['state'] = $state;
            $meta['error'] = $error;
            $meta['failures'] = min(6, (int) ($meta['failures'] ?? 0) + 1);
            $meta['next_check'] = ($this->clock)() + (in_array($state, ['retry', 'pending'], true) ? min(21600, 3600 * $meta['failures']) : 86400);
            $this->writeRenewal($meta, $row['pending']);
            $this->store->audit(0, 'connection.renewal_failed', 0, ['state' => $state, 'error' => $error]);
        });
    }

    /** A deliberately secret-free projection for administrator views. */
    public function status(array $config, bool $schedulerEnabled): array
    {
        $values = $this->credentials();
        $meta = $this->renewal()['meta'];
        $now = ($this->clock)();
        $expiry = (int) ($values['expires_at'] ?? 0);
        $connected = !empty($values['access_token']) && $this->accountId() !== '';
        $stale = (int) ($meta['last_check'] ?? 0) < $now - 48 * 3600;
        $state = $meta['state'] ?? 'healthy';
        return ['client_id' => (string) ($values['client_id'] ?? ''), 'account_id' => $this->accountId(),
            'connected' => $connected, 'expires_at' => $expiry, 'expired' => $expiry <= $now,
            'state' => $state, 'error' => (string) ($meta['error'] ?? ''), 'stale' => $stale,
            'automatic' => $connected && $expiry > $now && !$stale && $schedulerEnabled && ($config['mode'] ?? 'fake') === 'live'
                && !empty($values['refresh_token']) && !empty($values['client_secret']) && !empty($values['client_id']) && $state === 'healthy',
            'last_check' => (int) ($meta['last_check'] ?? 0), 'last_success' => (int) ($meta['last_success'] ?? 0),
            'renew_at' => (int) ($meta['renew_at'] ?? ($expiry - 7 * 86400)),
            'renewable' => !empty($values['refresh_token']) && !empty($values['client_id']) && !empty($values['client_secret']),
            'scheduler_enabled' => $schedulerEnabled, 'mode' => $config['mode'] ?? 'fake'];
    }
}

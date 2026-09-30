<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity;

final class Connection
{
    public function __construct(private Store $store, private CredentialCipher $cipher, private ?CleverReachIdentity $identity = null, private ?\Closure $exchangeTransport = null)
    {
        $this->identity ??= new CleverReachIdentity();
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
            foreach (['client_id', 'client_secret'] as $name) {
                if (!empty($values[$name]) && ($current[$name] ?? '') !== $values[$name]) {
                    if ($this->store->hasLiveReservations()) {
                        throw new \RuntimeException('COM_INTERCOM_LIVE_RESERVATIONS');
                    }
                    $current[$name] = $values[$name];
                    unset($current['access_token'], $current['refresh_token'], $current['expires_at']);
                }
            }
            $this->persist($current);
            $this->store->audit($actor, 'connection.credentials_changed');
        });
    }

    private function persist(array $values): void
    {
        $this->store->execute("UPDATE #__intercom_connections SET envelope=" . $this->store->q($this->cipher->encrypt($values))
            . " WHERE provider='cleverreach'");
    }

    private function exchange(array $form, array $credentials): array
    {
        if ($this->exchangeTransport !== null) {
            return ($this->exchangeTransport)($form, $credentials);
        }
        $curl = curl_init('https://rest.cleverreach.com/oauth/token.php');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_USERPWD => ($credentials['client_id'] ?? '') . ':' . ($credentials['client_secret'] ?? ''),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        $tokens = json_decode((string) $raw, true);
        if ($error || $code !== 200 || !is_array($tokens) || empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        }
        return array_merge($credentials, ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
            'expires_at' => time() + max(60, (int) ($tokens['expires_in'] ?? 3600))]);
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
            $values['expires_at'] = time() + $lifetime;
            $this->replaceTokens($values, $actor);
            $this->store->audit($actor, 'connection.tokens_imported', 0, ['expires_at' => $values['expires_at'], 'refresh_available' => $refresh !== '']);
        });
    }

    public function token(): string
    {
        return $this->store->transaction(function (): string {
            // Serialise refreshes and re-read credentials after acquiring the lock.
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $values = $this->credentials();
            if (!empty($values['access_token']) && ($values['expires_at'] ?? 0) > time()) {
                if ($this->accountId() === '') {
                    $this->pin(0);
                }
                return $values['access_token'];
            }
            if (empty($values['refresh_token']) || empty($values['client_id']) || empty($values['client_secret'])) {
                throw new \RuntimeException('COM_INTERCOM_TOKEN_EXPIRED');
            }
            $values = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $values['refresh_token']], $values);
            // Refresh grants are bound to the established account too.
            $id = $this->identity->resolve($values['access_token']);
            if ($this->accountId() !== '' && $this->accountId() !== $id) {
                throw new \RuntimeException('COM_INTERCOM_ACCOUNT_MISMATCH');
            }
            $this->replaceTokens($values, 0);
            $this->store->audit(0, 'connection.refreshed');
            return $values['access_token'];
        });
    }
}

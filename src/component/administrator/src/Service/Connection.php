<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Connection
{
    public function __construct(private Store $store, private CredentialCipher $cipher)
    {
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
            if ($this->store->row('SELECT filter_id FROM #__intercom_filters WHERE draft_id IS NOT NULL LIMIT 1 FOR UPDATE')) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT');
            }
            $current = $this->credentials();
            foreach (['client_id', 'client_secret'] as $name) {
                if (!empty($values[$name])) {
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
            if ($this->store->row('SELECT filter_id FROM #__intercom_filters WHERE draft_id IS NOT NULL LIMIT 1 FOR UPDATE')) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT');
            }
            $this->persist($this->exchange(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect], $this->credentials()));
            $this->store->audit($actor, 'connection.authorized');
        });
    }

    public function token(): string
    {
        return $this->store->transaction(function (): string {
            // Serialise refreshes and re-read credentials after acquiring the lock.
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $values = $this->credentials();
            if (empty($values['refresh_token'])) {
                throw new \RuntimeException('COM_INTERCOM_NOT_CONNECTED');
            }
            if (($values['expires_at'] ?? 0) < time() + 60) {
                $values = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $values['refresh_token']], $values);
                $this->persist($values);
                $this->store->audit(0, 'connection.refreshed');
            }
            return $values['access_token'];
        });
    }
}

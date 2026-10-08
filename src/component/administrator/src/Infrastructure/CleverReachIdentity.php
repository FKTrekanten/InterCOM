<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

final class CleverReachIdentity
{
    public function __construct(private ?\Closure $transport = null)
    {
    }

    public static function identifier(mixed $response): string
    {
        // /debug/whoami identifies the customer, not the OAuth application's client_id.
        $id = is_array($response) ? ($response['id'] ?? null) : null;
        if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id)) {
            throw new \RuntimeException('COM_INTERCOM_ACCOUNT_UNAVAILABLE');
        }
        return (string) $id;
    }

    public function resolve(#[\SensitiveParameter] string $token, int $timeout = 10): string
    {
        if ($this->transport !== null) {
            return self::identifier(($this->transport)($token));
        }
        $curl = curl_init('https://rest.cleverreach.com/v3/debug/whoami');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(1, $timeout), CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($error || $status !== 200) {
            throw new \RuntimeException('COM_INTERCOM_ACCOUNT_UNAVAILABLE');
        }
        try {
            return self::identifier(json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new \RuntimeException('COM_INTERCOM_ACCOUNT_UNAVAILABLE');
        }
    }
}

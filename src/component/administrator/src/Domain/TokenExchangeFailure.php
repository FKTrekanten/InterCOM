<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class TokenExchangeFailure extends \RuntimeException
{
    public function __construct(public readonly string $outcome)
    {
        parent::__construct($outcome === 'reconnect' ? 'COM_INTERCOM_RENEWAL_RECONNECT' : 'COM_INTERCOM_PROVIDER_ERROR');
    }

    public static function classify(int $curlError, int $status, mixed $response): string
    {
        if (in_array($curlError, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_CONNECT], true) || (!$curlError && $status === 429)) {
            return 'retry';
        }
        if (
            !$curlError && in_array($status, [400, 401, 403], true) && is_array($response)
            && in_array($response['error'] ?? '', ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)
        ) {
            return 'reconnect';
        }
        return 'uncertain';
    }
}

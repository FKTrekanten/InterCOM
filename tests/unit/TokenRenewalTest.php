<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\TokenExchangeFailure;
use FKT\Component\Intercom\Administrator\Service\Connection;
use PHPUnit\Framework\TestCase;

final class TokenRenewalTest extends TestCase
{
    public function testRenewalLeavesSevenDaysOfRecoveryForMonthlyTokens(): void
    {
        $issued = 1791028800;
        self::assertSame($issued + 23 * 86400, Connection::renewAt($issued + 30 * 86400, $issued));
    }

    public function testShortLifetimesDoNotCauseImmediateRepeatedRenewals(): void
    {
        self::assertSame(11800, Connection::renewAt(15400, 1000));
        self::assertSame(1001, Connection::renewAt(1001, 1000));
    }

    public function testTimeoutAndServerFailuresCannotSafelyReplayRotatingTokens(): void
    {
        self::assertSame('uncertain', TokenExchangeFailure::classify(CURLE_OPERATION_TIMEDOUT, 0, null));
        self::assertSame('uncertain', TokenExchangeFailure::classify(0, 500, ['error' => 'server_error']));
        self::assertSame('uncertain', TokenExchangeFailure::classify(0, 200, null));
        self::assertSame('uncertain', TokenExchangeFailure::classify(CURLE_OPERATION_TIMEDOUT, 429, null));
    }

    public function testConfirmedConnectionFailureAndRateLimitCanRetry(): void
    {
        foreach ([CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_CONNECT] as $error) {
            self::assertSame('retry', TokenExchangeFailure::classify($error, 0, null));
        }
        self::assertSame('retry', TokenExchangeFailure::classify(0, 429, ['error' => 'rate_limit']));
    }

    public function testRejectedRefreshRequiresReconnectWithoutLeakingProviderDetails(): void
    {
        $outcome = TokenExchangeFailure::classify(0, 400, ['error' => 'invalid_grant', 'error_description' => 'refresh-token-secret']);
        self::assertSame('reconnect', $outcome);
        self::assertSame('COM_INTERCOM_RENEWAL_RECONNECT', (new TokenExchangeFailure($outcome))->getMessage());
    }
}

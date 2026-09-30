<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CleverReachIdentityTest extends TestCase
{
    public function testUsesCustomerIdRatherThanApplicationClientId(): void
    {
        self::assertSame('231113', CleverReachIdentity::identifier(['id' => '231113', 'client_id' => 'wrong']));
        self::assertSame('231113', CleverReachIdentity::identifier(['id' => 231113]));
        $lookup = new CleverReachIdentity(static fn ($token) => ['id' => $token === 'candidate' ? '231113' : '9']);
        self::assertSame('231113', $lookup->resolve('candidate'));
    }

    public static function invalid(): array
    {
        return [[null], ['231113'], [['client_id' => '231113']], [['id' => 0]], [['id' => false]], [['id' => '01']], [['id' => '1/2']], [['id' => '1e3']], [['id' => 1.2]], [['id' => '231113 ']], [['id' => str_repeat('1', 21)]]];
    }

    #[DataProvider('invalid')]
    public function testMalformedOrUnavailableIdentityFailsClosed(mixed $response): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COM_INTERCOM_ACCOUNT_UNAVAILABLE');
        CleverReachIdentity::identifier($response);
    }
}

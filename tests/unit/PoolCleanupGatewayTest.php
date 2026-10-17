<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachPoolCleanup;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PoolCleanupGatewayTest extends TestCase
{
    private function gateway(string $failure = ''): CleverReachPoolCleanup
    {
        return new CleverReachPoolCleanup(new CleverReachGateway(fn () => 'fixture', ['group_id' => 880001], static function ($method, $path, $data) use ($failure) {
            self::assertSame('GET', $method);
            self::assertNull($data);
            if ($path === '/v3/debug/whoami') {
                return ['id' => '12345'];
            }
            if ($path === '/v3/groups/880001') {
                return ['id' => $failure === 'list' ? '999999' : '880001'];
            }
            if ($path === '/v3/groups/880001/filters') {
                if ($failure === 'catalogue') {
                    throw new \RuntimeException('Unavailable', 403);
                }
                return [];
            }
            if ($path === '/v3/groups/880001/filters/880101' || $path === '/v3/mailings/880201') {
                if ($failure === 'present') {
                    return ['id' => '880201'];
                }
                throw new \RuntimeException('Unavailable', $failure === 'permission' ? 403 : 404);
            }
            if (str_starts_with($path, '/v3/mailings?')) {
                parse_str(explode('?', $path)[1], $query);
                if ($failure === 'malformed') {
                    return ['wrong-state' => []];
                }
                if ($failure === 'incomplete') {
                    return [$query['state'] => array_fill(0, 100, ['id' => '880299'])];
                }
                return [$query['state'] => $failure === $query['state'] ? [['id' => '880201']] : []];
            }
            throw new \LogicException('Unexpected fixture request');
        }));
    }

    public function testAbsentRecordsRequireSuccessfulAccountListAndCatalogueReads(): void
    {
        $gateway = $this->gateway();
        self::assertSame('12345', $gateway->account());
        self::assertSame([], $gateway->filters(880001));
        $gateway->assertFilterAbsent(880001, 880101);
        $gateway->assertMailingAbsent(880201);
    }

    public static function failures(): array
    {
        return array_map(static fn ($failure) => [$failure], ['list', 'catalogue', 'permission', 'present', 'draft', 'waiting', 'running', 'automation', 'finished', 'malformed', 'incomplete']);
    }

    #[DataProvider('failures')]
    public function testUnsafeOrIncompleteReadsCannotEstablishAbsence(string $failure): void
    {
        $gateway = $this->gateway($failure);
        $gateway->account();
        $this->expectException(\RuntimeException::class);
        if (in_array($failure, ['list', 'catalogue'], true)) {
            $gateway->filters(880001);
        } else {
            $gateway->assertMailingAbsent(880201);
        }
    }
}

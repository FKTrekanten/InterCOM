<?php

declare(strict_types=1);

namespace FKT\Tests;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use PHPUnit\Framework\TestCase;

final class GatewayTagsTest extends TestCase
{
    public function testOriginsShareOneCompletePaginatedCatalogue(): void
    {
        $calls = [];
        $gateway = new CleverReachGateway(fn () => 'fixture', [], static function ($method, $path) use (&$calls): array {
            $calls[] = [$method, $path];
            return str_ends_with($path, 'offset=0') ? array_fill(0, 100, ['origin' => 'group', 'tag' => 'Youth']) : [['origin' => 'discipline', 'tag' => 'foil'], ['origin' => 'membership', 'tag' => 'Active']];
        });
        self::assertSame(['group.Youth'], $gateway->tags('group'));
        self::assertSame(['discipline.foil'], $gateway->tags('discipline'));
        self::assertSame(['membership.Active'], $gateway->tags('membership'));
        self::assertSame([['GET', '/v3/tags?limit=100&offset=0'], ['GET', '/v3/tags?limit=100&offset=100']], $calls);
    }

    public function testFailedPaginationNeverCachesAPartialAudienceCatalogue(): void
    {
        $failed = false;
        $gateway = new CleverReachGateway(fn () => 'fixture', [], static function ($method, $path) use (&$failed): array {
            if (str_ends_with($path, 'offset=0')) {
                return array_fill(0, 100, ['origin' => 'group', 'tag' => 'Youth']);
            }
            if (!$failed) {
                $failed = true;
                throw new \RuntimeException('Synthetic timeout');
            }
            return [['origin' => 'membership', 'tag' => 'Active']];
        });
        try {
            $gateway->tags('group');
            self::fail('Expected timeout');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic timeout', $error->getMessage());
        }
        self::assertSame(['membership.Active'], $gateway->tags('membership'));
    }
}

<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\MaintenanceBudget;
use PHPUnit\Framework\TestCase;

final class MaintenanceBudgetTest extends TestCase
{
    public function testChildPhaseCannotExtendTheRunDeadline(): void
    {
        $now = 100.0;
        $run = new MaintenanceBudget(60, static function () use (&$now): float {
            return $now;
        });
        $now = 155;
        $archive = $run->limit(20);
        self::assertSame(5, $archive->timeout(10));
        $now = 160;
        self::assertTrue($run->expired());
        self::assertTrue($archive->expired());
    }

    public function testPhaseLimitAndTransportTimeoutAreIndependent(): void
    {
        $now = 0.0;
        $phase = (new MaintenanceBudget(60, static function () use (&$now): float {
            return $now;
        }))->limit(20);
        self::assertSame(10, $phase->timeout(10));
        $now = 19.5;
        self::assertFalse($phase->expired());
        self::assertSame(1, $phase->timeout(10));
        $now = 20;
        self::assertTrue($phase->expired());
    }

    public function testNoTimeRemainingNeverBecomesAnUnlimitedTransportTimeout(): void
    {
        $budget = new MaintenanceBudget(0);
        self::assertTrue($budget->expired());
        self::assertSame(1, $budget->timeout(10));
    }
}

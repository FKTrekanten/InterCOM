<?php

declare(strict_types=1);

namespace FKT\Tests;

use FKT\Component\Intercom\Administrator\Domain\MailStatus;
use FKT\Component\Intercom\Administrator\Service\TestOperations;
use PHPUnit\Framework\TestCase;

final class MailStatusTest extends TestCase
{
    public function testFailedLookupIsEditableAndDoesNotClaimDelivery(): void
    {
        $draft = ['state' => 'draft', 'revision' => 3, 'delivery_mode' => 'live', 'test_operation' => ['revision' => 3, 'outcome' => 'failed', 'stage' => 'validation']];
        self::assertSame('STATUS_PREPARATION_FAILED', MailStatus::describe($draft)['status_detail']);
        self::assertSame('error', MailStatus::describe($draft)['status_severity']);
        $draft['revision'] = 4;
        self::assertSame('STATUS_DRAFT', MailStatus::describe($draft)['status_detail']);
    }

    public function testUnknownRemoteWritesStayDistinctFromTestTransportFailure(): void
    {
        $draft = ['state' => 'uncertain', 'revision' => 3, 'test_operation' => ['revision' => 3, 'outcome' => 'uncertain', 'stage' => 'mailing_write']];
        self::assertSame('STATUS_MAILING_REVIEW', MailStatus::describe($draft)['status_detail']);
        $draft['test_operation']['stage'] = 'filter_write';
        self::assertSame('STATUS_FILTER_REVIEW', MailStatus::describe($draft)['status_detail']);
        self::assertFalse(TestOperations::safeStage('filter_write'));
        self::assertFalse(TestOperations::safeStage('mailing_write'));
        self::assertFalse(TestOperations::safeStage('unknown_stage'));
        self::assertTrue(TestOperations::safeStage('provider_checks'));
        self::assertTrue(TestOperations::safeStage('test_en'));
    }

    public function testSimulationCannotClaimActualDelivery(): void
    {
        self::assertSame('FAKE_TESTED', MailStatus::describe(['state' => 'tested', 'revision' => 1, 'delivery_mode' => 'fake'])['status_detail']);
        self::assertSame('FAKE_SUBMITTED', MailStatus::describe(['state' => 'completed', 'revision' => 1, 'delivery_mode' => 'fake'])['status_detail']);
    }
}

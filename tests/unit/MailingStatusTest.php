<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\MailingStatus;
use PHPUnit\Framework\TestCase;

final class MailingStatusTest extends TestCase
{
    private function completed(): array
    {
        return ['id' => '123', 'started' => 100, 'finished' => 120, 'is_mailing' => true, 'is_campaign' => false,
            'is_dynamic' => false, 'mailing_groups' => ['group_ids' => ['456']]];
    }

    public function testCompletionRequiresExplicitTerminalEvidence(): void
    {
        self::assertSame('completed', MailingStatus::status($this->completed(), 123, 456, 150));
        foreach ([['finished' => 0], ['started' => 0, 'finished' => 0]] as $change) {
            self::assertSame('waiting', MailingStatus::status(array_replace($this->completed(), $change), 123, 456, 150));
        }
        foreach (
            [['finished' => 'false'], ['finished' => null], ['started' => 0], ['finished' => 99], ['finished' => 151],
            ['is_campaign' => true], ['is_dynamic' => true], ['is_mailing' => false], ['is_campaign' => null]] as $change
        ) {
            self::assertSame('unknown', MailingStatus::status(array_replace($this->completed(), $change), 123, 456, 150));
        }
        self::assertSame('mismatch', MailingStatus::status($this->completed(), 999, 456, 150));
        self::assertSame('mismatch', MailingStatus::status($this->completed(), 123, 999, 150));
        $multiple = $this->completed();
        $multiple['mailing_groups']['group_ids'][] = '789';
        self::assertSame('mismatch', MailingStatus::status($multiple, 123, 456, 150));
        self::assertSame('mismatch', MailingStatus::status([], 123, 456, 150));
    }
}

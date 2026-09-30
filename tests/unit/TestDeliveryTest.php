<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Service\TestDelivery;
use PHPUnit\Framework\TestCase;

final class TestDeliveryTest extends TestCase
{
    private function snapshot(): array
    {
        $message = Message::validate(['type' => 'club', 'sender' => 'Club', 'subject_da' => 'Dansk emne', 'subject_en' => 'English subject', 'body_da' => 'Hej {FIRSTNAME[std:Medlem]}', 'body_en' => 'Hello {FIRSTNAME[std:Member]}']);
        return ['message' => $message, 'da' => Message::html($message, 'da-DK'), 'en' => Message::html($message, 'en-GB')];
    }

    public function testBothLanguagesGoOnlyToTheSuppliedUserViaTheSiteTransport(): void
    {
        $sent = [];
        $delivery = new TestDelivery(static function ($address, $payload) use (&$sent): bool {
            $sent[] = [$address, $payload];
            return true;
        });
        $delivery->deliver($this->snapshot(), 'coach@example.invalid');
        self::assertCount(2, $sent);
        foreach ($sent as [$address, $payload]) {
            self::assertSame('coach@example.invalid', $address);
            self::assertStringContainsString('[Intercom test / ', $payload['subject']);
            self::assertStringNotContainsString('{UNSUBSCRIBE}', $payload['html']);
            self::assertStringNotContainsString('{FIRSTNAME', $payload['html']);
            self::assertStringNotContainsString('prefers-color-scheme', $payload['text']);
        }
        self::assertStringContainsString('Hej Medlem', $sent[0][1]['html']);
        self::assertStringNotContainsString('Hello Member', $sent[0][1]['html']);
        self::assertStringContainsString('Hello Member', $sent[1][1]['html']);
        self::assertStringContainsString('inactive', $sent[1][1]['text']);
    }

    public function testTransportFailureHasNoSensitiveErrorOrAutomaticRetry(): void
    {
        $attempts = 0;
        $delivery = new TestDelivery(static function () use (&$attempts): bool {
            $attempts++;
            throw new \RuntimeException('SMTP recipient secret@example.invalid password=private');
        });
        try {
            $delivery->deliver($this->snapshot(), 'coach@example.invalid');
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('COM_INTERCOM_TEST_DELIVERY_ERROR', $e->getMessage());
            self::assertSame(1, $attempts);
        }
    }

    public function testInvalidUserEmailDoesNotCallMailer(): void
    {
        $delivery = new TestDelivery(static fn () => self::fail('Mailer must not run'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COM_INTERCOM_INVALID_EMAIL');
        $delivery->deliver($this->snapshot(), 'invalid');
    }
}

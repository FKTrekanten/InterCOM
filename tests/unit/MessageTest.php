<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private function message(): array
    {
        return ['type' => 'class', 'sender' => 'Club', 'subject_da' => 'Hej', 'subject_en' => 'Hello',
            'body_da' => '<script>alert(1)</script>', 'body_en' => 'Hello {FIRSTNAME[std:Member]}', 'tags' => ['group.Youth']];
    }
    public function testUntrustedContentIsEscapedAndUnsubscribePreserved(): void
    {
        $html = Message::html(Message::validate($this->message()));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('{UNSUBSCRIBE}', $html);
        self::assertStringContainsString('{FIRSTNAME[std:Member]}', $html);
    }
    public function testCommaInjectionIntoTagRulesIsDenied(): void
    {
        $message = $this->message();
        $message['tags'] = ['group.Youth,group.Other'];
        $this->expectException(\RuntimeException::class);
        Message::validate($message);
    }
    public function testInvalidAgeBoundsRejected(): void
    {
        $message = $this->message();
        $message['age_from'] = 20;
        $message['age_to'] = 10;
        $this->expectException(\RuntimeException::class);
        Message::validate($message);
    }
}

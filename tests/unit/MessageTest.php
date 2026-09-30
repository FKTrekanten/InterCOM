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
    public function testEmailUsesLanguageLabelsAndAutomaticFallback(): void
    {
        $message = Message::validate($this->message());
        $message['tags'] = ['group.Adult_Ryparken_Monday_17_30', 'group.epee'];
        $message['tag_labels'] = ['group.Adult_Ryparken_Monday_17_30' => ['da-DK' => 'Voksne, Ryparken, mandag 17:30']];
        $da = Message::html($message, 'da-DK');
        $en = Message::html($message, 'en-GB');
        self::assertStringContainsString('Voksne, Ryparken, mandag 17:30, Epee', $da);
        self::assertStringContainsString('Adult, Ryparken, Monday 17:30, Epee', $en);
        self::assertStringNotContainsString('Adult_Ryparken_Monday_17_30', $da . $en . Message::text($message));
        self::assertStringContainsString('Voksne, Ryparken, mandag 17:30', Message::text($message));
        self::assertStringContainsString('Adult, Ryparken, Monday 17:30', Message::text($message));
        self::assertSame(['group.Adult_Ryparken_Monday_17_30', 'group.epee'], $message['tags']);
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
    public function testTagCannotInjectProviderDirectivesIntoFooter(): void
    {
        $message = $this->message();
        $message['tags'] = ['group.{IF[language]}'];
        $this->expectException(\RuntimeException::class);
        Message::validate($message);
    }

    public function testSubjectCannotInjectProviderDirectives(): void
    {
        $message = $this->message();
        $message['subject_en'] = '{IF[language]}unexpected';
        $this->expectException(\RuntimeException::class);
        Message::validate($message);
    }
}

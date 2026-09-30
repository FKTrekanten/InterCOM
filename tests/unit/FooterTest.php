<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Footer;
use FKT\Component\Intercom\Administrator\Domain\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FooterTest extends TestCase
{
    public function testBothLanguagesKeepExistingLinksAndAddConfiguredContactDetails(): void
    {
        $message = ['body_da' => 'Dansk', 'body_en' => 'English', 'tags' => [], 'footer' => Footer::validate([
            'footer_address' => "Club & friends\nStreet 1", 'footer_profile_da' => 'https://example.org/da/profile',
            'footer_profile_en' => 'https://example.org/en/profile', 'footer_instagram' => ''])];
        foreach (['da-DK', 'en-GB'] as $language) {
            $html = Message::html($message, $language, 'light');
            self::assertStringContainsString('Club &amp; friends<br', $html);
            self::assertStringContainsString('#F4F7FA', $html);
            self::assertStringNotContainsString('>Instagram<', $html);
            self::assertStringContainsString('>LinkedIn<', $html);
            self::assertStringContainsString($language === 'da-DK' ? '/da/profile' : '/en/profile', $html);
            self::assertStringContainsString($language === 'da-DK' ? 'Afmeld al kommunikation' : 'Unsubscribe from all communication', $html);
            self::assertStringContainsString('[recipient]', $html);
        }
        $delivery = Message::html($message);
        self::assertStringContainsString('{UNSUBSCRIBE}', $delivery);
        self::assertStringContainsString('{ONLINE_VERSION}', $delivery);
        self::assertStringNotContainsString('pstmrk.it', $delivery);
        self::assertStringContainsString('Street 1', Message::text($message));
        self::assertStringContainsString('/da/profile', Message::text($message));
    }

    public static function invalid(): array
    {
        return [[['footer_instagram' => 'javascript:alert(1)']], [['footer_website' => 'http://example.org']],
            [['footer_website' => 'https://user:secret@example.org']], [['footer_email' => 'invalid']],
            [['footer_phone' => "Phone\nInjected"]], [['footer_address' => '<b>Club</b>']],
            [['footer_profile_en' => '']], [['footer_address' => []]]];
    }

    #[DataProvider('invalid')]
    public function testRejectsUnsafeContactSettings(array $settings): void
    {
        $this->expectException(\RuntimeException::class);
        Footer::validate($settings);
    }
}

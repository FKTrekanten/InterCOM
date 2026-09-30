<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\EmailDesign;
use FKT\Component\Intercom\Administrator\Domain\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailDesignTest extends TestCase
{
    public function testDefaultsAreReadableInBothThemes(): void
    {
        $design = EmailDesign::validate([]);
        self::assertArrayNotHasKey('sender_en', $design);
        self::assertArrayNotHasKey('sender_da', $design);
        foreach (['light', 'dark'] as $theme) {
            foreach (['text', 'muted', 'accent'] as $role) {
                self::assertGreaterThanOrEqual(4.5, EmailDesign::contrast($design[$theme . '_' . $role], $design[$theme . '_surface']));
            }
        }
    }

    public static function invalidDesigns(): array
    {
        return [
            [['light_text' => '#ffffff']], [['dark_accent' => '#1C252D']], [['light_header_text' => '#172534']],
            [['logo_url' => 'javascript:alert(1)']], [['logo_url' => 'https://user:secret@example.org/a.png']],
            [['light_outer' => '#fff;background:red']], [['body_font' => 'serif;display:none']],
            [['logo_width' => '500']], [['heading_size' => '28px']], [['brand_en' => '{IF[LANGUAGE]}']],
        ];
    }

    #[DataProvider('invalidDesigns')]
    public function testUnsafeOrUnreadableDesignIsRejected(array $input): void
    {
        $this->expectException(\RuntimeException::class);
        EmailDesign::validate($input);
    }

    public function testRendererUsesSnapshotAndPreservesDeliveryDirectives(): void
    {
        $settings = EmailDesign::validate(['brand_en' => 'Trekanten & friends', 'dark_surface' => '#202B34']);
        $message = ['format' => 'html', 'body_da' => '<p>Dansk</p>', 'body_en' => '<p>English</p>', 'tags' => [],
            'design' => ['revision' => 2, 'settings' => $settings],
            'definition' => ['translations' => ['en-GB' => ['heading' => 'Club News'], 'da-DK' => ['heading' => 'Klubnyt']]]];
        $delivered = Message::html($message);
        self::assertStringContainsString('prefers-color-scheme:dark', $delivered);
        self::assertStringContainsString('supported-color-schemes', $delivered);
        self::assertStringContainsString('[data-ogsc]', $delivered);
        self::assertStringContainsString('{UNSUBSCRIBE}', $delivered);
        self::assertStringContainsString('{ONLINE_VERSION}', $delivered);
        self::assertStringContainsString('{IF[LANGUAGE==da-DK]}', $delivered);
        self::assertStringNotContainsString('{{', $delivered);
        $preview = Message::html($message, 'en-GB', 'dark');
        self::assertStringContainsString('class="preview-dark"', $preview);
        self::assertStringContainsString('Trekanten &amp; friends', $preview);
        self::assertStringContainsString('Club News', $preview);
        self::assertStringContainsString('#202B34', $preview);
        self::assertStringNotContainsString('<p>Dansk</p>', $preview);
        self::assertStringNotContainsString('{UNSUBSCRIBE}', $preview);
        self::assertLessThan(strpos($preview, 'class="ic-logo"'), strpos($preview, 'Club News'));
    }
}

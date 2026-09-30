<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\EmailContent;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use PHPUnit\Framework\TestCase;

final class EmailContentTest extends TestCase
{
    public function testFormattingAndSafeLinksSurviveWithoutExecutableHtml(): void
    {
        $clean = EmailContent::sanitise('<p onclick="evil()" style="color:red;text-align:center">Ægte <strong>news</strong></p>'
            . '<ul><li>One</li></ul><a href="https://trekanten.org/?a=1&amp;b=2">Club</a>'
            . '<a href="javascript:evil()">Bad</a><img src=x onerror="evil()"><svg><script>evil()</script></svg>'
            . '<iframe>bad frame</iframe><style>bad style</style><!--{IF[bad]}-->{ONLINE_VERSION}');
        self::assertStringContainsString('<strong>news</strong>', $clean);
        self::assertStringContainsString('<ul><li>One</li></ul>', $clean);
        self::assertStringContainsString('text-align:center', $clean);
        self::assertStringContainsString('Ægte', $clean);
        self::assertStringContainsString('href="https://trekanten.org/', $clean);
        foreach (['onclick', 'javascript:', 'onerror', 'bad frame', 'bad style', '<svg', '{ONLINE_VERSION}', 'color:red'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $clean);
        }
        self::assertStringContainsString('Club (https://trekanten.org/?a=1&b=2)', EmailContent::text($clean));
    }

    public function testNestedDirectivesCannotReappearAfterSanitising(): void
    {
        $clean = EmailContent::sanitise('<p>Hej {FIRSTNAME[std:Medlem]} {IF[language=={EVIL}da-DK]} hidden {ENDIF[language]}</p>');
        self::assertStringContainsString('{FIRSTNAME[std:Medlem]}', $clean);
        self::assertStringNotContainsString('{IF', $clean);
        self::assertStringNotContainsString('{ENDIF', $clean);
        self::assertStringNotContainsString('EVIL', $clean);
        self::assertSame($clean, EmailContent::sanitise($clean));
    }

    public function testLocalPreviewUsesOneLanguageAndExamplePersonalisation(): void
    {
        $message = ['format' => 'html', 'body_da' => '<p>Dansk {FIRSTNAME[std:Medlem]}</p>',
            'body_en' => '<p>English {FIRSTNAME[std:Member]}</p>', 'tags' => ['group.<Youth>'],
            'definition' => ['fallback' => 'en-GB', 'translations' => ['en-GB' => ['name' => 'Club', 'heading' => 'Latest news']]]];
        $da = Message::html($message, 'da-DK');
        self::assertStringContainsString('Dansk Medlem', $da);
        self::assertStringNotContainsString('English Member', $da);
        self::assertStringContainsString('Latest news', $da);
        self::assertStringContainsString('&lt;Youth&gt;', $da);
        self::assertStringNotContainsString('{FIRSTNAME', $da);
        self::assertStringContainsString('English Member', Message::html($message, 'en-GB'));
        self::assertStringContainsString('{UNSUBSCRIBE}', Message::html($message));
    }

    public function testCustomSuppressionAndGroupedRulesPreserveLegacySemantics(): void
    {
        $rules = CleverReachGateway::filterRules(['type' => 'newsletter', 'tags' => ['group.Youth', 'group.Senior'],
            'memberships' => ['membership.A', 'membership.B'], 'age_from' => 10, 'age_to' => 30, 'gender' => 'female',
            'definition' => ['suppression' => 'club-updates']], new \DateTimeImmutable('2026-09-30'));
        self::assertSame(['operator' => '', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => 'group.Youth,group.Senior'], $rules[0]);
        self::assertSame(['operator' => 'AND', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => 'membership.A,membership.B'], $rules[1]);
        self::assertSame('2016-10-01', $rules[2]['condition']);
        self::assertSame('1995-09-30', $rules[3]['condition']);
        self::assertSame(['operator' => 'AND', 'field' => 'suppression', 'logic' => 'NOCONTAINS', 'condition' => 'club-updates'], $rules[5]);
    }

    public function testNewCommunicationUsesSeparateComposeAndSendGrants(): void
    {
        $policy = new Policy(
            ['access' => true, 'compose' => true, 'send' => true, 'custom' => true, 'send_custom' => false],
            ['all' => true],
            ['custom' => ['require_group' => false]]
        );
        $policy->assertAllowed('custom', [], 'compose');
        $this->expectException(\RuntimeException::class);
        $policy->assertAllowed('custom', [], 'send');
    }
}

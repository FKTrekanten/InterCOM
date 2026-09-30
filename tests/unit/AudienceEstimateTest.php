<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Audience;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\RecipientCount;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Service\History;
use PHPUnit\Framework\TestCase;

final class AudienceEstimateTest extends TestCase
{
    public function testAudienceWithoutBodyAndCanonicalTagOrder(): void
    {
        $message = Message::validate(['type' => 'class', 'tags' => ['group.B', 'group.A', 'group.A']], false);
        self::assertSame('', $message['body_en']);
        self::assertSame('', $message['subject_en']);
        self::assertSame(['group.A', 'group.B'], $message['tags']);
        $this->expectException(\RuntimeException::class);
        Message::validate($message);
    }

    public function testFingerprintIgnoresTranslationButBindsAccountListAndRules(): void
    {
        $rules = [['field' => 'suppression', 'logic' => 'NOCONTAINS', 'condition' => 'news']];
        $definition = ['id' => 1, 'suppression' => 'news', 'translations' => ['en-GB' => ['name' => 'News']]];
        $fingerprint = Audience::fingerprint($rules, $definition, '231113', 'live', 758666);
        $definition['translations']['en-GB']['name'] = 'Updated';
        self::assertSame($fingerprint, Audience::fingerprint($rules, $definition, '231113', 'live', 758666));
        self::assertNotSame($fingerprint, Audience::fingerprint($rules, $definition, '999', 'live', 758666));
        self::assertNotSame($fingerprint, Audience::fingerprint($rules, $definition, '231113', 'live', 1));
        self::assertNotSame($fingerprint, Audience::fingerprint([], $definition, '231113', 'live', 758666));
    }

    public function testInclusiveAgeRangeIncludesBirthdayAndDayBeforeNextBirthday(): void
    {
        $message = Audience::validate(['type' => 'club', 'age_from' => 30, 'age_to' => 30]);
        $rules = CleverReachGateway::filterRules($message, new \DateTimeImmutable('2026-09-30'));
        self::assertSame('1996-10-01', $rules[0]['condition']);
        self::assertSame('1995-09-30', $rules[1]['condition']);
        foreach (['1996-09-30', '1995-10-01'] as $birthday) {
            self::assertTrue($birthday < $rules[0]['condition'] && $birthday > $rules[1]['condition']);
        }
        foreach (['1996-10-01', '1995-09-30'] as $birthday) {
            self::assertFalse($birthday < $rules[0]['condition'] && $birthday > $rules[1]['condition']);
        }
    }

    public function testStatsUsesActiveCountWithoutUnverifiedSubtraction(): void
    {
        self::assertSame(2, RecipientCount::active(['active_count' => '2', 'total_count' => 7, 'bounce_count' => 3]));
        self::assertSame(0, RecipientCount::active(['active_count' => 0]));
        foreach ([[], ['total_count' => 7], ['active_count' => -1], ['active_count' => 1.2], ['active_count' => true], ['active_count' => '4294967296']] as $invalid) {
            try {
                RecipientCount::active($invalid);
                self::fail('Expected malformed stats rejection');
            } catch (\RuntimeException $e) {
                self::assertSame('COM_INTERCOM_ESTIMATE_UNAVAILABLE', $e->getMessage());
            }
        }
    }

    public function testProviderEstimateNeverCreatesOrSendsMailings(): void
    {
        $calls = [];
        $gateway = new CleverReachGateway(fn () => 'stub', ['group_id' => 758666], static function ($method, $path, $body) use (&$calls) {
            $calls[] = [$method, $path, $body];
            return str_ends_with($path, '/stats') ? ['active_count' => 1] : null;
        });
        $gateway->updateAudience(123, [['field' => 'suppression', 'logic' => 'NOCONTAINS', 'condition' => 'news']]);
        self::assertSame(1, $gateway->statistics(123));
        self::assertSame('/v3/groups/758666/filters/123', $calls[0][1]);
        self::assertSame('/v3/groups/758666/filters/123/stats', $calls[1][1]);
        self::assertCount(2, $calls);
    }

    public function testReadBackResolvesProviderAttributeIdsAndRejectsRuleChanges(): void
    {
        $changed = false;
        $gateway = new CleverReachGateway(fn () => 'stub', ['group_id' => 758666], static function ($method, $path) use (&$changed) {
            if (str_ends_with($path, '/attributes')) {
                return [['id' => '1161076', 'name' => 'suppression']];
            }
            return ['id' => 123, 'rules' => [['operator' => 'AND', 'field' => 'a1161076.value', 'logic' => 'NOCONTAINS', 'condition' => $changed ? 'wrong' : 'news']]];
        });
        $rules = [['operator' => '', 'field' => 'suppression', 'logic' => 'NOCONTAINS', 'condition' => 'news']];
        $gateway->assertAudience(123, $rules);
        $changed = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COM_INTERCOM_AUDIENCE_CHANGED');
        $gateway->assertAudience(123, $rules);
    }

    public function testHistoryPreviewCannotLoadTrackingOrFollowUnsubscribe(): void
    {
        $safe = History::preview('<html><head></head><body><img src="https://example.invalid/track"><a href="https://example.invalid/unsubscribe">Unsubscribe</a></body></html>');
        self::assertStringContainsString('Content-Security-Policy', $safe);
        self::assertStringNotContainsString('https://', $safe);
        self::assertStringContainsString("default-src 'none'", $safe);
    }
}

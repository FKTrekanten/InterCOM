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

    public function testMemberRulesCoverAgeGenderAndOpenRanges(): void
    {
        foreach (
            [
            [['age_from' => 8, 'age_to' => 12], ['female', 'male', 'other'], 8, 12],
            [['gender' => 'female'], ['female'], 0, 120],
            [['gender' => 'male'], ['male'], 0, 120],
            [['gender' => 'female', 'age_from' => 12, 'age_to' => 14], ['female'], 12, 14],
            [['age_from' => 18], ['female', 'male', 'other'], 18, 120],
            [['age_to' => 9], ['female', 'male', 'other'], 0, 9],
            [['age_from' => 30, 'age_to' => 30], ['female', 'male', 'other'], 30, 30],
            ] as [$input, $genders, $from, $to]
        ) {
            $message = Audience::validate(['type' => 'club'] + $input);
            $rules = CleverReachGateway::filterRules($message);
            $expected = [];
            foreach ($genders as $gender) {
                foreach (range($from, $to) as $age) {
                    $expected[] = "member.$gender" . '_' . $age;
                }
            }
            self::assertSame(['operator' => '', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => implode(',', $expected)], $rules[0]);
            self::assertCount(2, $rules);
            self::assertSame('AND', $rules[1]['operator']);
            self::assertSame($rules, CleverReachGateway::filterRules($message, new \DateTimeImmutable('2030-01-01')));
        }
        self::assertSame([['operator' => '', 'field' => 'suppression', 'logic' => 'NOCONTAINS', 'condition' => 'club']], CleverReachGateway::filterRules(Audience::validate(['type' => 'club'])));
    }

    public function testDisciplineAndTrainingGroupRequireBothWithOtherRulesUnchanged(): void
    {
        foreach ([[], ['group.Youth', 'group.Senior']] as $groups) {
            $message = Audience::validate(['type' => 'club', 'tags' => $groups, 'disciplines' => ['discipline.epee', 'discipline.foil'], 'memberships' => ['membership.Active']]);
            $message['acceptance_email'] = 'approved@example.invalid';
            $message['definition'] = ['suppression' => 'news'];
            $expected = [['operator' => '', 'field' => 'email', 'logic' => 'EQ', 'condition' => 'approved@example.invalid']];
            if ($groups) {
                $expected[] = ['operator' => 'AND', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => implode(',', $message['tags'])];
            }
            $expected[] = ['operator' => 'AND', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => 'discipline.epee,discipline.foil'];
            $expected[] = ['operator' => 'AND', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => 'membership.Active'];
            $expected[] = ['operator' => 'AND', 'field' => 'suppression', 'logic' => 'NOCONTAINS', 'condition' => 'news'];
            self::assertSame($expected, CleverReachGateway::filterRules($message));
        }
    }

    public function testLongestMemberRuleSurvivesProviderReadbackAndChangesAreRejected(): void
    {
        $rules = CleverReachGateway::filterRules(Audience::validate(['type' => 'club', 'age_to' => 120]));
        self::assertCount(363, explode(',', $rules[0]['condition']));
        self::assertSame(5840, strlen($rules[0]['condition']));
        $remote = array_map(static fn ($rule) => array_merge($rule, ['operator' => 'and', 'logic' => strtolower($rule['logic'])]), $rules);
        $gateway = new CleverReachGateway(fn () => 'stub', ['group_id' => 758666], static function () use (&$remote) {
            return ['id' => 123, 'rules' => $remote];
        });
        $gateway->assertAudience(123, $rules);
        $remote[0]['condition'] = substr($remote[0]['condition'], 0, -1);
        $this->expectExceptionMessage('COM_INTERCOM_AUDIENCE_CHANGED');
        $gateway->assertAudience(123, $rules);
    }

    public function testSummaryUsesCriteriaRatherThanGeneratedMemberTags(): void
    {
        $message = Audience::validate(['type' => 'club', 'age_from' => 8, 'age_to' => 12, 'gender' => 'female']);
        self::assertSame('ages 8-12, women', Audience::summary($message));
        self::assertSame('alder 8-12, kvinder', Audience::summary($message, 'da-DK'));
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

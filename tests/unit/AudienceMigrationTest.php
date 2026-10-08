<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Audience;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Service\AudienceMigration;
use PHPUnit\Framework\TestCase;

final class AudienceMigrationTest extends TestCase
{
    public function testStoredDisciplineReferencesAndLabelsMoveWithoutLosingTrainingGroups(): void
    {
        $old = ['type' => 'club', 'tags' => ['group.epee', 'group.foil', 'group.sabre', 'group.Youth'],
            'disciplines' => ['discipline.epee'], 'tag_labels' => ['group.epee' => ['da-DK' => 'Kårde']], 'subject_en' => 'Keep me'];
        $new = AudienceMigration::message($old);
        self::assertSame(['group.Youth'], $new['tags']);
        self::assertSame(['discipline.epee', 'discipline.foil', 'discipline.sabre'], $new['disciplines']);
        self::assertSame(['da-DK' => 'Kårde'], $new['tag_labels']['discipline.epee']);
        self::assertArrayNotHasKey('group.epee', $new['tag_labels']);
        self::assertSame('Keep me', $new['subject_en']);
        self::assertSame($new, AudienceMigration::message($new));
        $rules = CleverReachGateway::filterRules(Audience::validate($new));
        self::assertSame('group.Youth', $rules[0]['condition']);
        self::assertSame(['operator' => 'AND', 'field' => 'tags', 'logic' => 'CONTAINS', 'condition' => 'discipline.epee,discipline.foil,discipline.sabre'], $rules[1]);
    }

    public function testMemberTagsCannotBeComposerSelections(): void
    {
        foreach (['tags', 'disciplines', 'memberships'] as $key) {
            try {
                Audience::validate(['type' => 'club', $key => ['member.female_9']]);
                self::fail('Member tags must not be selectable');
            } catch (\RuntimeException $error) {
                self::assertSame('COM_INTERCOM_INVALID_MESSAGE', $error->getMessage());
            }
        }
    }
}

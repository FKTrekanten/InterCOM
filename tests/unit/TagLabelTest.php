<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\TagLabel;
use PHPUnit\Framework\TestCase;

final class TagLabelTest extends TestCase
{
    public function testFormatsOnlyValidTrailingTimesAndKeepsWords(): void
    {
        self::assertSame('Adult, Ryparken, Monday 17:30', TagLabel::automatic('group.Adult_Ryparken_Monday_17_30'));
        self::assertSame('Youth, Friday, 25, 70', TagLabel::automatic('group.Youth_Friday_25_70'));
        self::assertSame('Club, Resource, Coach', TagLabel::automatic('membership.Club_resource_coach_'));
        self::assertSame('Epee', TagLabel::automatic('group.epee'));
        self::assertSame('Ældre, Østerbro', TagLabel::automatic('group.ældre_østerbro'));
        self::assertSame('Active member', TagLabel::automatic('membership.Active member'));
        self::assertSame('U17, Wednesday 09:05', TagLabel::automatic('group.U17_Wednesday_09_05'));
    }

    public function testUsesExactLanguageOverrideAndAutomaticFallback(): void
    {
        $row = ['tag' => 'group.Adult_Monday_17_30', 'labels' => '{"da-DK":"Voksne, mandag 17:30"}'];
        self::assertSame('Voksne, mandag 17:30', TagLabel::display($row, 'da-DK'));
        self::assertSame('Adult, Monday 17:30', TagLabel::display($row, 'en-GB'));
        self::assertSame(['da-DK' => 'Voksne'], TagLabel::validate(['da-DK' => ' Voksne ', 'en-GB' => ''], ['da-DK' => 'Dansk', 'en-GB' => 'English']));
    }

    public function testRejectsMarkupInOverrides(): void
    {
        $this->expectException(\RuntimeException::class);
        TagLabel::validate(['da-DK' => '<script>'], ['da-DK' => 'Dansk']);
    }
}

<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    private function coach(): Policy
    {
        return new Policy(['access' => true, 'compose' => true, 'send' => true, 'class' => true], ['all' => false, 'tags' => ['group.Youth']]);
    }
    public function testCoachCanSendAssignedTeam(): void
    {
        $this->coach()->assertAllowed('class', ['group.Youth'], 'send');
        $this->addToAssertionCount(1);
    }
    public function testGrantedDisciplineAndTrainingGroupCanBeTargetedTogether(): void
    {
        $policy = new Policy(['access' => true, 'compose' => true, 'class' => true], ['all' => false, 'tags' => ['group.Youth', 'discipline.foil']]);
        $policy->assertAllowed('class', \FKT\Component\Intercom\Administrator\Domain\Audience::targetedTags(['tags' => ['group.Youth'], 'disciplines' => ['discipline.foil']]), 'compose');
        $this->addToAssertionCount(1);
    }

    public function testTrainingGroupGrantCannotAuthorizeAnUngrantedDiscipline(): void
    {
        $this->expectExceptionMessage('COM_INTERCOM_SCOPE_DENIED');
        $this->coach()->assertAllowed('class', \FKT\Component\Intercom\Administrator\Domain\Audience::targetedTags(['tags' => ['group.Youth'], 'disciplines' => ['discipline.sabre']]), 'compose');
    }

    public function testForgedTeamIsDenied(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->coach()->assertAllowed('class', ['group.Senior'], 'send');
    }
    public function testEmptyScopeCannotBroadenAudience(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->coach()->assertAllowed('class', [], 'send');
    }
    public function testBoardTypeNotGrantedToCoach(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->coach()->assertAllowed('club', ['group.Youth'], 'send');
    }
    public function testDeniedActionWinsEvenWithAllAudience(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Policy(['access' => true, 'send' => false, 'newsletter' => true], ['all' => true]))->assertAllowed('newsletter', [], 'send');
    }
    public function testMultipleGroupScopesAreCombined(): void
    {
        self::assertSame(['all' => false, 'tags' => ['group.Youth', 'group.Senior']], Policy::audienceScope([12, 13], [
            ['group' => 12, 'tags' => ['group.Youth']], ['group' => 13, 'tags' => ['group.Senior']], ['group' => 99, 'all' => true]]));
    }
}

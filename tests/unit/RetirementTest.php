<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachRetirement;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetirementTest extends TestCase
{
    private function gateway(array $remote, array $catalogues = [], int $failure = 0): CleverReachRetirement
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', ['group_id' => 758666], static function ($method, $path, $data) use ($remote, $catalogues, $failure) {
            self::assertSame('GET', $method);
            self::assertNull($data);
            if (str_starts_with($path, '/v3/mailings?')) {
                parse_str(explode('?', $path)[1], $query);
                $state = $query['state'];
                return [$state => $catalogues[$state] ?? []];
            }
            return match (true) {
                $path === '/v3/debug/whoami' => ['id' => '231113'],
                $path === '/v3/groups/758666' => ['id' => '758666'],
                $path === '/v3/groups/758666/filters/783415' => ['id' => '783415'],
                $path === '/v3/mailings/17459494' => $failure ? throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR', $failure) : $remote,
                default => throw new \LogicException('Unexpected provider fixture path'),
            };
        });
        return new CleverReachRetirement($gateway);
    }

    private static function draft(): array
    {
        return ['id' => '17459494', 'started' => 0, 'finished' => 0, 'ready' => 0, 'send_on' => 0,
            'is_mailing' => true, 'is_campaign' => false, 'is_dynamic' => false, 'is_splittest' => false,
            'mailing_groups' => ['group_ids' => ['758666']]];
    }

    public function testKnownUnsentDraftIsInspectedWithoutProviderWrites(): void
    {
        $gateway = $this->gateway(self::draft(), ['draft' => [['id' => '17459494']]]);
        self::assertSame('231113', $gateway->account());
        $gateway->assertDraft(17459494, 758666, 783415);
        $this->expectExceptionMessage('COM_INTERCOM_ABANDON_EXISTS');
        $gateway->assertAbsent(17459494, 758666, 783415);
    }

    public static function unsafe(): array
    {
        $cases = [];
        foreach (['started', 'finished', 'ready', 'send_on'] as $key) {
            $cases[$key] = [array_replace(self::draft(), [$key => 1])];
            $cases[$key . ' missing'] = [array_diff_key(self::draft(), [$key => true])];
        }
        foreach (['is_campaign', 'is_dynamic', 'is_splittest'] as $key) {
            $cases[$key] = [array_replace(self::draft(), [$key => true])];
        }
        $cases['wrong ID'] = [array_replace(self::draft(), ['id' => '999'])];
        $cases['wrong list'] = [array_replace(self::draft(), ['mailing_groups' => ['group_ids' => ['999']]])];
        $cases['multiple lists'] = [array_replace(self::draft(), ['mailing_groups' => ['group_ids' => ['758666', '999']]])];
        return $cases;
    }

    #[DataProvider('unsafe')]
    public function testScheduledActiveMalformedAndWrongAudienceCannotBeAbandoned(array $remote): void
    {
        $this->expectExceptionMessage('COM_INTERCOM_ABANDON_UNSAFE');
        $this->gateway($remote, ['draft' => [['id' => 17459494]]])->assertDraft(17459494, 758666, 783415);
    }

    public function testZeroTimestampsWithoutDraftCatalogueEvidenceRemainBlocked(): void
    {
        $this->expectExceptionMessage('COM_INTERCOM_ABANDON_UNSAFE');
        $this->gateway(self::draft())->assertDraft(17459494, 758666, 783415);
    }

    public function testInitial404IsNotPositiveDraftEvidence(): void
    {
        $this->expectExceptionCode(404);
        $this->gateway([], [], 404)->assertDraft(17459494, 758666, 783415);
    }

    public function testMissingKnownMailingRequiresSuccessfulCatalogueChecks(): void
    {
        $gateway = $this->gateway([], [], 404);
        self::assertSame('231113', $gateway->account());
        $gateway->assertAbsent(17459494, 758666, 783415);
    }

    public function testARecoverableOrQueuedMailingInAnyCatalogueRetainsReservation(): void
    {
        foreach (['draft', 'waiting', 'running', 'automation', 'finished'] as $state) {
            try {
                $this->gateway([], [$state => [['id' => 17459494]]], 404)->assertAbsent(17459494, 758666, 783415);
                self::fail('Catalogue still contains the mailing');
            } catch (\RuntimeException $e) {
                self::assertSame('COM_INTERCOM_ABANDON_UNVERIFIED', $e->getMessage());
            }
        }
    }

    public function testReadPermissionsAndTimeoutsAreNotAbsence(): void
    {
        foreach ([403, 500] as $status) {
            try {
                $this->gateway([], [], $status)->assertAbsent(17459494, 758666, 783415);
                self::fail('Provider failure cannot be absence');
            } catch (\RuntimeException $e) {
                self::assertSame($status, $e->getCode());
            }
        }
    }

    public function testBoundedPaginationCannotMistakeIncompleteCatalogueForAbsence(): void
    {
        $pages = [];
        $gateway = new CleverReachGateway(fn () => 'fixture', [], static function ($method, $path) use (&$pages) {
            self::assertSame('GET', $method);
            parse_str(explode('?', $path)[1], $query);
            $pages[] = (int) $query['page'];
            return ['draft' => array_fill(0, 100, ['id' => 999])];
        });
        try {
            $gateway->retirementCatalogueContains(17459494, 'draft');
            self::fail('Truncated catalogue cannot be absence');
        } catch (\RuntimeException $e) {
            self::assertSame('COM_INTERCOM_ABANDON_UNVERIFIED', $e->getMessage());
            self::assertSame(range(0, 9), $pages);
        }
    }

    public function testWrappedPaginationFindsMailingAndRequiresAnActualLastPage(): void
    {
        foreach ([true, false] as $present) {
            $pages = [];
            $gateway = new CleverReachGateway(fn () => 'fixture', [], static function ($method, $path) use ($present, &$pages) {
                self::assertSame('GET', $method);
                parse_str(explode('?', $path)[1], $query);
                $page = (int) $query['page'];
                $pages[] = $page;
                return ['draft' => $page === 0 ? array_fill(0, 100, ['id' => 999]) : ($present ? [['id' => '17459494']] : [])];
            });
            self::assertSame($present, $gateway->retirementCatalogueContains(17459494, 'draft'));
            self::assertSame([0, 1], $pages);
        }
    }

    public static function invalidCatalogues(): array
    {
        return [
            'different state' => [['finished' => []]],
            'additional state' => [['draft' => [], 'waiting' => []]],
            'missing state' => [['total' => 0]],
            'malformed state' => [['draft' => null]],
            'keyed rows' => [['draft' => ['mailing' => ['id' => 17459494]]]],
            'missing row ID' => [['draft' => [[]]]],
            'invalid row ID' => [['draft' => [['id' => 'invalid']]]],
            'too many rows' => [['draft' => array_fill(0, 101, ['id' => 999])]],
        ];
    }

    #[DataProvider('invalidCatalogues')]
    public function testWrongStateOrMalformedWrapperNeverEstablishesAbsence(array $response): void
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', [], static fn () => $response);
        $this->expectExceptionMessage('COM_INTERCOM_ABANDON_UNVERIFIED');
        $gateway->retirementCatalogueContains(17459494, 'draft');
    }

    public function testFlatCatalogueRemainsSupported(): void
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', [], static fn () => [['id' => '17459494']]);
        self::assertTrue($gateway->retirementCatalogueContains(17459494, 'draft'));
    }

    public function testExpiredOverallBudgetStartsNoFurtherProviderRequest(): void
    {
        $calls = 0;
        $gateway = new CleverReachGateway(fn () => 'fixture', [], static function () use (&$calls) {
            $calls++;
            return ['id' => '231113'];
        });
        $gateway->retirementAccount();
        $property = new \ReflectionProperty($gateway, 'retirementBudget');
        $property->setValue($gateway, new \FKT\Component\Intercom\Administrator\Domain\MaintenanceBudget(0));
        try {
            $gateway->assertRetirementScope(758666, 783415);
            self::fail('No work after retirement budget exhaustion');
        } catch (\RuntimeException $e) {
            self::assertSame('COM_INTERCOM_ABANDON_UNVERIFIED', $e->getMessage());
            self::assertSame(1, $calls);
        }
    }
}

<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Domain\ReleaseBlocked;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AcceptanceTest extends TestCase
{
    private function config(): array
    {
        return ['group_id' => 758666, 'sender_name' => 'Club', 'sender_email' => 'club@example.org', 'unsubscribe_form_id' => '123', 'release_verified' => true];
    }
    public function testCheckboxCannotAuthorizeMemberRelease(): void
    {
        $g = new CleverReachGateway(fn () => 'fixture', $this->config(), static function (): void {
            self::fail('No API calls without a server approval');
        });
        $this->expectException(ReleaseBlocked::class);
        $this->expectExceptionMessage('COM_INTERCOM_RELEASE_NOT_VERIFIED');
        $g->release(123, 0);
    }
    public static function cases(): array
    {
        $ok = ['email' => 'approved@example.org', 'group_id' => '758666', 'activated' => 1234, 'deactivated' => 0, 'bounced' => 0];
        return [
            [1, [$ok], false, [], true], [0, [$ok], false, [], false], [2, [$ok], false, [], false],
            [1, [$ok, $ok], false, [], false], [1, [array_replace($ok, ['email' => 'someone@example.org'])], false, [], false],
            [1, [array_replace($ok, ['bounced' => 1])], false, [], false], [1, [array_replace($ok, ['bounced' => 'bad'])], false, [], false],
            [1, [array_replace($ok, ['deactivated' => 1234])], false, [], false], [1, [array_replace($ok, ['activated' => 0])], false, [], false],
            [1, [array_replace($ok, ['group_id' => '758666x'])], false, [], false], [1, [$ok], true, [], false],
            [1, [$ok], false, ['approved@example.org'], false], [1, [$ok], false, [['email' => 'approved@example.org']], false],
            [1, [$ok], false, ['other@example.org'], true], [1, [$ok], false, [false], false],
        ];
    }
    #[DataProvider('cases')]
    public function testOnlyAnEligibleApprovedRecipientPasses(int $count, array $rows, bool $global, array $list, bool $allowed): void
    {
        $g = new CleverReachGateway(fn () => 'fixture', $this->config(), static function ($method, $path) use ($count, $rows, $global, $list) {
            self::assertSame('GET', $method);
            if (str_ends_with($path, '/stats')) {
                return ['active_count' => $count];
            }
            if (str_contains($path, '/receivers?')) {
                return $rows;
            }
            if (str_starts_with($path, '/v3/blacklist/')) {
                if (!$global) {
                    throw new \RuntimeException('Not found', 404);
                } return ['email' => 'approved@example.org'];
            }
            return $list;
        });
        try {
            $g->assertOneRecipient(42, 'approved@example.org');
            self::assertTrue($allowed);
        } catch (\RuntimeException $e) {
            self::assertFalse($allowed);
            self::assertSame('COM_INTERCOM_ACCEPTANCE_RECIPIENT', $e->getMessage());
        }
    }
    public function testUnavailableBlacklistCannotBeTreatedAsAbsent(): void
    {
        $g = new CleverReachGateway(fn () => 'fixture', $this->config(), static function ($method, $path) {
            if (str_ends_with($path, '/stats')) {
                return ['active_count' => 1];
            }
            if (str_contains($path, '/receivers?')) {
                return [['email' => 'approved@example.org', 'group_id' => 758666, 'activated' => 1234, 'deactivated' => 0, 'bounced' => 0]];
            }
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        });
        $this->expectExceptionMessage('COM_INTERCOM_PROVIDER_ERROR');
        $g->assertOneRecipient(42, 'approved@example.org');
    }
    public function testWrongMailingListCannotBeReleasedEvenWithApproval(): void
    {
        $g = new CleverReachGateway(fn () => 'fixture', $this->config(), static function ($method, $path) {
            self::assertSame('GET', $method);
            if (str_ends_with($path, '/forms')) {
                return [['id' => 123, 'customer_tables_id' => 758666]];
            }
            return ['id' => 456, 'unsubscribe_form_id' => '123', 'sender_name' => 'Club', 'sender_email' => 'club@example.org', 'is_mailing' => true, 'is_campaign' => false, 'is_dynamic' => false, 'mailing_groups' => ['group_ids' => ['999']]];
        }, static function (): void {
        });
        $this->expectException(ReleaseBlocked::class);
        $this->expectExceptionMessage('COM_INTERCOM_ACCEPTANCE_MAILING');
        $g->release(456, 0);
    }
}

<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;

final class FakeGateway implements DeliveryGateway, \FKT\Component\Intercom\Administrator\Domain\AudienceGateway
{
    public function mode(): string
    {
        return 'fake';
    }

    public function tags(string $origin): array
    {
        return match ($origin) {
            'group' => ['group.Youth', 'group.Senior'],
            'discipline' => ['discipline.epee', 'discipline.foil', 'discipline.sabre'],
            'membership' => ['membership.Active', 'membership.Passive'],
            default => [],
        };
    }

    public function updateAudience(int $filterId, array $rules): void
    {
    }

    public function assertAudience(int $filterId, array $rules): void
    {
    }

    public function statistics(int $filterId): int
    {
        return 1; // Explicit simulation fixture, never a provider count.
    }

    public function prepare(array $message, int $filterId, int $mailingId): int
    {
        return $mailingId ?: random_int(100000, 999999);
    }

    public function preview(int $mailingId, string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_EMAIL');
        }
    }

    public function release(int $mailingId, int $timestamp): void
    {
        // No external requests. Used for local development and CI only.
    }
}

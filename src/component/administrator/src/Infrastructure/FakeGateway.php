<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;

final class FakeGateway implements DeliveryGateway
{
    public function tags(string $origin): array
    {
        return $origin === 'group' ? ['group.Youth', 'group.Senior'] : ['membership.Active', 'membership.Passive'];
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

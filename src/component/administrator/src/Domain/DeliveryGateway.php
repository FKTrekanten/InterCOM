<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface DeliveryGateway
{
    public function mode(): string;
    public function tags(string $origin): array;
    public function prepare(array $message, int $filterId, int $mailingId): int;
    public function preview(int $mailingId, string $email): void;
    public function release(int $mailingId, int $timestamp): void;
}

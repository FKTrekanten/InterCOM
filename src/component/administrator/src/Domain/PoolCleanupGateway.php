<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface PoolCleanupGateway
{
    public function account(): string;
    public function filters(int $group): array;
    public function assertFilterAbsent(int $group, int $filter): void;
    public function assertMailingAbsent(int $mailing): void;
}

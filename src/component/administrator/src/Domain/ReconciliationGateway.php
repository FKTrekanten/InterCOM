<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface ReconciliationGateway
{
    public function mailing(int $id): array;
    public function filters(int $groupId): array;
    public function filter(int $groupId, int $id): array;
}

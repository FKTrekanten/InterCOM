<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface FilterCreator
{
    public function createFilter(int $groupId, string $name): int;
}

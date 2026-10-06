<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface RetirementGateway
{
    public function account(): string;
    public function assertDraft(int $mailing, int $group, int $filter): void;
    public function assertAbsent(int $mailing, int $group, int $filter): void;
}

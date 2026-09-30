<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface ReleaseGateway
{
    public function preflightRelease(int $mailingId): void;
}

<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface AudienceGateway
{
    public function updateAudience(int $filterId, array $rules): void;
    public function assertAudience(int $filterId, array $rules): void;
    public function statistics(int $filterId): int;
}

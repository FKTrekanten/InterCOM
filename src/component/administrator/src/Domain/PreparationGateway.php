<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

interface PreparationGateway
{
    /** Report each boundary before remote writes; report mailing IDs before further work. */
    public function prepareWithProgress(array $message, int $filterId, int $mailingId, callable $progress): int;
}

<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class RecipientCount
{
    public static function active(mixed $stats): int
    {
        $value = is_array($stats) ? ($stats['active_count'] ?? null) : null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string) $value) || (int) $value > 4294967295) {
            throw new \RuntimeException('COM_INTERCOM_ESTIMATE_UNAVAILABLE');
        }
        return (int) $value;
    }
}

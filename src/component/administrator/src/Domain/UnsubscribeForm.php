<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class UnsubscribeForm
{
    public static function identifier(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_UNSUBSCRIBE', 422);
        }
        $id = trim((string) $value);
        if ($id === '' || $id === '0') {
            return '';
        }
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) || self::isFlow($id)) {
            return $id;
        }
        throw new \RuntimeException('COM_INTERCOM_INVALID_UNSUBSCRIBE', 422);
    }

    public static function isFlow(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id);
    }

    public static function belongsTo(array $flow, int $groupId): bool
    {
        return $groupId > 0 && is_string($flow['id'] ?? null) && self::isFlow($flow['id'])
            && is_array($flow['setup'] ?? null)
            && ($flow['playbook'] ?? '') === 'unsubscribe'
            && (string) ($flow['setup']['groupid'] ?? '') === (string) $groupId;
    }
}

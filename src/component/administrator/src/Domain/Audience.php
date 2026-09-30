<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class Audience
{
    public static function validate(array $input): array
    {
        $type = $input['type'] ?? null;
        if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        $result = ['type' => $type];
        foreach (['tags' => 'group.', 'memberships' => 'membership.'] as $key => $prefix) {
            $values = $input[$key] ?? [];
            if (!is_array($values) || count($values) > 100) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
            }
            foreach ($values as $value) {
                if (
                    !is_string($value) || !str_starts_with($value, $prefix) || strlen($value) > 200
                    || preg_match('/[{},\x00-\x1f]/', $value)
                ) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
                }
            }
            $result[$key] = array_values(array_unique($values));
            sort($result[$key], SORT_STRING);
        }
        foreach (['age_from', 'age_to'] as $key) {
            $value = filter_var($input[$key] ?? 0, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 120) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_AGE', 422);
            }
            $result[$key] = $value;
        }
        if ($result['age_from'] && $result['age_to'] && $result['age_from'] > $result['age_to']) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_AGE', 422);
        }
        $result['gender'] = $input['gender'] ?? '';
        if (!in_array($result['gender'], ['', 'male', 'female'], true)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        return $result;
    }

    public static function fingerprint(array $rules, array $definition, string $account, string $mode, int $list): string
    {
        return hash('sha256', json_encode([$account, $mode, $list, $definition['id'] ?? $definition['type_key'] ?? '', $definition['suppression'] ?? '', $rules], JSON_THROW_ON_ERROR));
    }
}

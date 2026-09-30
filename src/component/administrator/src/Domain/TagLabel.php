<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class TagLabel
{
    public static function automatic(string $tag): string
    {
        $name = preg_replace('/^(group|membership)\./', '', $tag);
        $name = preg_replace('/_([01][0-9]|2[0-3])_([0-5][0-9])$/D', ' $1:$2', $name);
        return preg_replace('/_+/', ', ', trim($name, '_'));
    }

    public static function display(array $row, string $language): string
    {
        $labels = json_decode($row['labels'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        return trim($labels[$language] ?? '') ?: self::automatic($row['tag']);
    }

    public static function validate(array $labels, array $languages): array
    {
        $clean = [];
        foreach ($labels as $language => $value) {
            if (!isset($languages[$language]) || !is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x1f{}<>]/', $value)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_TAG_LABEL', 422);
            }
            if (trim($value) !== '') {
                $clean[$language] = trim($value);
            }
        }
        return $clean;
    }
}

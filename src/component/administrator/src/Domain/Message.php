<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class Message
{
    public static function validate(array $input): array
    {
        $result = [];
        foreach (['type', 'sender', 'subject_da', 'subject_en', 'body_da', 'body_en'] as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > (str_starts_with($key, 'body') ? 100000 : 255)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
            }
            $result[$key] = trim($value);
        }
        if (!in_array($result['type'], Policy::TYPES, true)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        foreach (['tags' => 'group.', 'memberships' => 'membership.'] as $key => $prefix) {
            $values = $input[$key] ?? [];
            if (!is_array($values) || count($values) > 100) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
            }
            foreach ($values as $value) {
                if (
                    !is_string($value) || !str_starts_with($value, $prefix) || strlen($value) > 200
                    || preg_match('/[,\x00-\x1f]/', $value)
                ) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
                }
            }
            $result[$key] = array_values(array_unique($values));
        }
        foreach (['age_from', 'age_to'] as $key) {
            $value = filter_var($input[$key] ?? 0, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 120) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_AGE', 422);
            }
            $result[$key] = $value;
        }
        if ($result['age_from'] && $result['age_to'] && $result['age_from'] >= $result['age_to']) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_AGE', 422);
        }
        $result['gender'] = $input['gender'] ?? '';
        if (!in_array($result['gender'], ['', 'male', 'female'], true)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        return $result;
    }

    public static function html(array $message): string
    {
        $escape = static fn ($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $da = nl2br($escape($message['body_da']));
        $en = nl2br($escape($message['body_en']));
        return '<html><body style="font-family:Arial,sans-serif">'
            . '<!--#loopitem if="{IF[LANGUAGE==da-DK]}"#--><h1>' . $escape($message['subject_da']) . '</h1>' . $da
            . '<!--#/loopitem endif="{ENDIF[LANGUAGE]}"#-->'
            . '<!--#loopitem if="{IF[LANGUAGE!=da-DK]}"#--><h1>' . $escape($message['subject_en']) . '</h1>' . $en
            . '<!--#/loopitem endif="{ENDIF[LANGUAGE]}"#-->'
            . '<hr><a href="{ONLINE_VERSION}">Online</a> · <a href="{UNSUBSCRIBE}">Afmeld / Unsubscribe</a></body></html>';
    }
}

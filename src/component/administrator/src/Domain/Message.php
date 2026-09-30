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
            if (!str_starts_with($key, 'body') && preg_match('/[\x00-\x1f{}]/', $value)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
            }
            $result[$key] = trim($value);
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $result['type'])) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        if (!in_array($input['format'] ?? 'plain', ['plain', 'html'], true)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        $result['format'] = ($input['format'] ?? 'plain') === 'html' ? 'html' : 'plain';
        foreach (['body_da', 'body_en'] as $body) {
            $result[$body] = $result['format'] === 'html' ? EmailContent::sanitise($result[$body]) : EmailContent::placeholders($result[$body]);
            if (trim($result['format'] === 'html' ? EmailContent::text($result[$body]) : $result[$body]) === '') {
                throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
            }
        }
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

    public static function templateVersion(): string
    {
        return hash('sha256', implode('|', [hash_file('sha256', dirname(__DIR__, 2) . '/tmpl/email/newsletter.html'),
            hash_file('sha256', __FILE__), hash_file('sha256', __DIR__ . '/EmailDesign.php'), hash_file('sha256', __DIR__ . '/Footer.php'), hash_file('sha256', __DIR__ . '/TagLabel.php')]));
    }

    public static function bodyHtml(array $message, string $lang): string
    {
        return ($message['format'] ?? 'plain') === 'html' ? EmailContent::sanitise($message['body_' . $lang])
            : nl2br(htmlspecialchars($message['body_' . $lang], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public static function translation(array $definition, string $language): array
    {
        $translations = $definition['translations'] ?? [];
        $fallback = $translations[$definition['fallback'] ?? 'en-GB'] ?? $translations['en-GB'] ?? $translations['da-DK'] ?? [];
        $selected = $translations[$language] ?? [];
        foreach (['name', 'description', 'subject_prefix', 'heading'] as $field) {
            if (($selected[$field] ?? '') === '') {
                $selected[$field] = $fallback[$field] ?? '';
            }
        }
        return $selected;
    }

    public static function html(array $message, ?string $locale = null, ?string $theme = null): string
    {
        $escape = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footer = Footer::validate($message['footer'] ?? []);
        $definition = $message['definition'] ?? [];
        $da = self::translation($definition, 'da-DK');
        $en = self::translation($definition, 'en-GB');
        $design = EmailDesign::validate($message['design']['settings'] ?? []);
        $tokens = [];
        foreach ($design as $key => $value) {
            $tokens['{{' . strtoupper($key) . '}}'] = $escape($value);
        }
        foreach (['body', 'heading'] as $role) {
            $tokens['{{' . strtoupper($role) . '_FONT}}'] = EmailDesign::FONTS[$design[$role . '_font']];
        }
        $tokens['{{FONT_IMPORT}}'] = in_array('poppins', [$design['heading_font'], $design['body_font']], true)
            ? '@import url("https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&display=swap");' : '';
        $tokens['{{PREVIEW_CLASS}}'] = $theme === 'dark' ? 'preview-dark' : ($theme === 'light' ? 'preview-light' : '');
        $rules = ['' => 'background-color:' . $design['dark_outer'] . '!important;color:' . $design['dark_text'] . '!important',
            ' .ic-outer' => 'background-color:' . $design['dark_outer'] . '!important',
            ' .ic-surface' => 'background-color:' . $design['dark_surface'] . '!important;border-color:' . $design['dark_line'] . '!important',
            ' .ic-content' => 'color:' . $design['dark_text'] . '!important;border-top-color:' . $design['dark_accent'] . '!important',
            ' .ic-footer' => 'background-color:' . $design['dark_footer'] . '!important;border-top-color:' . $design['dark_line'] . '!important;color:' . $design['dark_muted'] . '!important',
            ' .ic-header' => 'background-color:' . $design['dark_header'] . '!important',
            ' .ic-brand,SELECTOR .ic-title' => 'color:' . $design['dark_header_text'] . '!important',
            ' a' => 'color:' . $design['dark_accent'] . '!important'];
        $darkCss = static function (string $selector) use ($rules): string {
            $css = '';
            foreach ($rules as $suffix => $style) {
                $css .= str_replace('SELECTOR', $selector, $selector . $suffix) . '{' . $style . '}';
            }
            return $css;
        };
        $tokens['{{DARK_CSS}}'] = $darkCss('body.preview-dark') . '@media (prefers-color-scheme:dark){'
            . $darkCss('body:not(.preview-light)') . '}' . $darkCss('[data-ogsc] body:not(.preview-light)');
        $groupsDa = $escape(self::groupLabels($message, 'da-DK'));
        $groupsEn = $escape(self::groupLabels($message, 'en-GB'));
        $html = strtr(file_get_contents(dirname(__DIR__, 2) . '/tmpl/email/newsletter.html'), array_merge($tokens, [
            '{{FOOTER_CONTACT}}' => Footer::html($footer, $design['light_accent']),
            '{{PROFILE_DA}}' => $escape($footer['footer_profile_da']), '{{PROFILE_EN}}' => $escape($footer['footer_profile_en']),
            '{{BODY_DA}}' => self::bodyHtml($message, 'da'), '{{BODY_EN}}' => self::bodyHtml($message, 'en'),
            '{{HEADER_DA}}' => $escape($da['heading'] ?: ($da['name'] ?: 'Trekanten informerer')),
            '{{HEADER_EN}}' => $escape($en['heading'] ?: ($en['name'] ?: 'Trekanten informs')),
            '{{FOOTER_REASON_DA}}' => $groupsDa ? 'fordi du er tilknyttet: <strong>' . $groupsDa . '</strong>' : 'som registreret medlem af Fægteklubben Trekanten',
            '{{FOOTER_REASON_EN}}' => $groupsEn ? 'because you belong to: <strong>' . $groupsEn . '</strong>' : 'as a registered member of Trekanten Fencing',
        ]));
        if ($locale !== null) {
            $html = preg_replace_callback(
                '/<!--#loopitem if="\{IF\[LANGUAGE(!=|==)da-DK\]\}"#-->(.*?)<!--#\/loopitem endif="\{ENDIF\[LANGUAGE\]\}"#-->/s',
                static fn ($match) => (($locale === 'da-DK') === ($match[1] === '==')) ? $match[2] : '',
                $html
            );
            $html = strtr($html, ['{FIRSTNAME[std:Medlem]}' => 'Medlem', '{FIRSTNAME[std:Member]}' => 'Member',
                '{ONLINE_VERSION}' => '#', '{UNSUBSCRIBE}' => '#', '{EMAIL}' => '[recipient]']);
        }
        return $html;
    }

    private static function groupLabels(array $message, string $language): string
    {
        return implode(', ', array_map(static fn (string $tag): string => $message['tag_labels'][$tag][$language] ?? TagLabel::automatic($tag), $message['tags'] ?? []));
    }

    public static function text(array $message): string
    {
        return "[DA]\n" . EmailContent::text(self::bodyHtml($message, 'da')) . (self::groupLabels($message, 'da-DK') ? "\n\nDu modtager denne besked, fordi du er tilknyttet: " . self::groupLabels($message, 'da-DK') : '') . "\n\n[EN]\n"
            . EmailContent::text(self::bodyHtml($message, 'en')) . (self::groupLabels($message, 'en-GB') ? "\n\nYou receive this message because you belong to: " . self::groupLabels($message, 'en-GB') : '') . "\n\n" . Footer::text(Footer::validate($message['footer'] ?? [])) . "\n\n{ONLINE_VERSION}\n{UNSUBSCRIBE}";
    }
}

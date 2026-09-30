<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class Footer
{
    public static function defaults(): array
    {
        return ['footer_profile_da' => 'https://trekanten.org/min-klub/member-profile', 'footer_profile_en' => 'https://trekanten.org/en/my-club/member-profile',
            'footer_address' => "Fægteklubben Trekanten · Lyngbyvej 104\n2100 København Ø · Denmark",
            'footer_phone' => '+45 3927 1313', 'footer_email' => 'info@trekanten.org', 'footer_website' => 'https://trekanten.org',
            'footer_instagram' => 'https://www.instagram.com/fktrekanten/', 'footer_linkedin' => 'https://www.linkedin.com/company/fktrekanten/',
            'footer_facebook' => 'https://www.facebook.com/faegteklubbentrekanten/', 'footer_youtube' => 'https://www.youtube.com/@fktrekanten'];
    }

    public static function validate(array $input): array
    {
        $clean = [];
        foreach (self::defaults() as $key => $default) {
            $value = $input[$key] ?? $default;
            if (!is_string($value) || strlen($value) > 1000 || preg_match('/[\x00-\x08\x0b-\x1f{}<>]/', $value)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_FOOTER', 422);
            }
            $value = trim(str_replace("\r\n", "\n", $value));
            if ($key !== 'footer_address' && preg_match('/[\r\n]/', $value)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_FOOTER', 422);
            }
            if (str_starts_with($key, 'footer_profile_') && $value === '') {
                throw new \RuntimeException('COM_INTERCOM_INVALID_FOOTER', 422);
            }
            if ($value !== '' && $key === 'footer_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_FOOTER', 422);
            }
            if ($value !== '' && !in_array($key, ['footer_address', 'footer_phone', 'footer_email'], true)) {
                if (!filter_var($value, FILTER_VALIDATE_URL) || parse_url($value, PHP_URL_SCHEME) !== 'https' || parse_url($value, PHP_URL_USER) !== null) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_FOOTER', 422);
                }
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    public static function html(array $settings, string $accent): string
    {
        $escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = static fn (string $url, string $label): string => '<a href="' . $escape($url) . '" style="color:' . $escape($accent) . ';text-decoration:underline">' . $escape($label) . '</a>';
        $social = [];
        foreach (['instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'youtube' => 'YouTube'] as $key => $label) {
            if ($settings['footer_' . $key] !== '') {
                $social[] = $link($settings['footer_' . $key], $label);
            }
        }
        $contact = [];
        if ($settings['footer_phone'] !== '') {
            $contact[] = $escape($settings['footer_phone']);
        }
        if ($settings['footer_email'] !== '') {
            $contact[] = $link('mailto:' . $settings['footer_email'], $settings['footer_email']);
        }
        if ($settings['footer_website'] !== '') {
            $contact[] = $link($settings['footer_website'], parse_url($settings['footer_website'], PHP_URL_HOST));
        }
        $html = $social ? '<p style="margin:0 0 12px">' . implode(' &nbsp;·&nbsp; ', $social) . '</p>' : '';
        if ($settings['footer_address'] !== '') {
            $html .= '<p style="margin:0 0 4px">' . nl2br($escape($settings['footer_address'])) . '</p>';
        }
        return $html . ($contact ? '<p style="margin:0 0 18px">' . implode(' &nbsp;·&nbsp; ', $contact) . '</p>' : '');
    }

    public static function text(array $settings): string
    {
        return implode("\n", array_filter($settings, static fn ($value) => $value !== ''));
    }
}

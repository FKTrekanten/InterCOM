<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class EmailDesign
{
    public const FONTS = [
        'system' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
        'poppins' => "Poppins,-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif",
        'arial' => 'Arial,Helvetica,sans-serif',
    ];

    public static function defaults(): array
    {
        return ['brand_da' => 'Fægteklubben Trekanten', 'brand_en' => 'Fægteklubben Trekanten',
            'logo_url' => 'https://s3.eu-west-1.amazonaws.com/files.crsend.com/231000/231113/images/2.2_FKT_LOGO_NEG_HVID_AFLEV.png',
            'logo_width' => 96, 'content_width' => 600, 'padding' => 32, 'body_size' => 16, 'heading_size' => 28,
            'heading_font' => 'poppins', 'body_font' => 'system',
            'light_outer' => '#F1F4F4', 'light_surface' => '#FFFFFF', 'light_text' => '#14181B',
            'light_muted' => '#4A545C', 'light_footer' => '#F4F7FA', 'light_header' => '#172534', 'light_header_text' => '#FFFFFF',
            'light_accent' => '#0F6B99', 'light_line' => '#CFD5D9',
            'dark_outer' => '#10171D', 'dark_surface' => '#1C252D', 'dark_text' => '#F1F4F4',
            'dark_muted' => '#BDC7CE', 'dark_footer' => '#17212B', 'dark_header' => '#172534', 'dark_header_text' => '#FFFFFF',
            'dark_accent' => '#81C5EA', 'dark_line' => '#4A545C'];
    }

    public static function validate(array $input): array
    {
        $clean = self::defaults();
        foreach ($clean as $key => $default) {
            $value = $input[$key] ?? $default;
            if (!is_scalar($value)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_DESIGN', 422);
            }
            if (str_starts_with($key, 'light_') || str_starts_with($key, 'dark_')) {
                if (!preg_match('/^#[0-9a-f]{6}$/iD', (string) $value)) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_DESIGN', 422);
                }
                $clean[$key] = strtoupper((string) $value);
            } elseif (str_ends_with($key, '_font')) {
                if (!isset(self::FONTS[$value])) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_DESIGN', 422);
                }
                $clean[$key] = $value;
            } elseif (is_int($default)) {
                $ranges = ['logo_width' => [40, 160], 'content_width' => [480, 800], 'padding' => [16, 48], 'body_size' => [14, 20], 'heading_size' => [22, 40]];
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if ($number === false || $number < $ranges[$key][0] || $number > $ranges[$key][1]) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_DESIGN', 422);
                }
                $clean[$key] = $number;
            } else {
                $value = trim((string) $value);
                if ($value === '' || strlen($value) > ($key === 'logo_url' ? 1000 : 255) || preg_match('/[\x00-\x1f{}<>]/', $value)) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_DESIGN', 422);
                }
                if ($key === 'logo_url' && (!filter_var($value, FILTER_VALIDATE_URL) || parse_url($value, PHP_URL_SCHEME) !== 'https' || parse_url($value, PHP_URL_USER) !== null)) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_DESIGN', 422);
                }
                $clean[$key] = $value;
            }
        }
        foreach (['light', 'dark'] as $theme) {
            foreach (['text', 'muted', 'accent'] as $role) {
                if (self::contrast($clean[$theme . '_' . $role], $clean[$theme . '_surface']) < 4.5) {
                    throw new \RuntimeException('COM_INTERCOM_DESIGN_CONTRAST', 422);
                }
            }
            foreach (['muted', 'accent'] as $role) {
                if (self::contrast($clean[$theme . '_' . $role], $clean[$theme . '_footer']) < 4.5) {
                    throw new \RuntimeException('COM_INTERCOM_DESIGN_CONTRAST', 422);
                }
            }
            if (self::contrast($clean[$theme . '_header_text'], $clean[$theme . '_header']) < 4.5) {
                throw new \RuntimeException('COM_INTERCOM_DESIGN_CONTRAST', 422);
            }
        }
        return $clean;
    }

    public static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $rgb = array_map(static function ($part): float {
                $c = hexdec($part) / 255;
                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));
            return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
        };
        $x = $luminance($a);
        $y = $luminance($b);
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}

<?php

namespace App\Support;

use Closure;

/**
 * Which values the System page may store for each setting it validates. A key without a rule is
 * stored as given (system-admin.md REQ-3). A new validated setting adds its rule here rather than
 * to SystemController.
 */
final class SettingRules
{
    public static function passes(string $key, mixed $value): bool
    {
        $rule = self::rules()[$key] ?? null;

        return $rule === null || (bool) $rule($value);
    }

    /**
     * @return array<string, Closure(mixed): (bool|int)>
     */
    private static function rules(): array
    {
        // Resize dimensions: empty disables that axis.
        $resizeDimension = fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000);

        return [
            'items_per_page' => fn ($v) => is_numeric($v) && $v >= 10 && $v <= 100,
            'rekognition_max_labels' => fn ($v) => is_numeric($v) && $v >= 1 && $v <= 20,
            'rekognition_language' => fn ($v) => in_array($v, array_keys(config('orca.rekognition_languages')), true),
            'rekognition_min_confidence' => fn ($v) => is_numeric($v) && $v >= 65 && $v <= 99,
            'timezone' => fn ($v) => in_array($v, timezone_identifiers_list(), true),
            'locale' => fn ($v) => in_array($v, array_keys(config('orca.ui_locales')), true),
            // Empty string (bucket root) or alphanumeric with hyphens, underscores, slashes
            's3_root_folder' => fn ($v) => $v === '' || preg_match('/^[a-zA-Z0-9_\-\/]+$/', $v),
            // Empty (disabled) or a valid URL starting with http(s)://
            'custom_domain' => fn ($v) => $v === '' || preg_match('/^https?:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}(\/.*)?$/', $v),
            'resize_s_width' => $resizeDimension,
            'resize_s_height' => $resizeDimension,
            'resize_m_width' => $resizeDimension,
            'resize_m_height' => $resizeDimension,
            'resize_l_width' => $resizeDimension,
            'resize_l_height' => $resizeDimension,
            'embed_allowed_domains' => function ($v) {
                if ($v === '' || $v === '[]') {
                    return true;
                }
                $decoded = json_decode($v, true);

                return is_array($decoded) && collect($decoded)->every(fn ($d) => preg_match('/^https?:\/\/[a-zA-Z0-9\-\.\*]+\.[a-zA-Z]{2,}(\/.*)?$/', $d));
            },
            'tikz_color_package_name' => fn ($v) => $v === '' || (strlen($v) <= 50 && preg_match('/^[a-zA-Z0-9_-]+$/', $v)),
            'tikz_color_package' => fn ($v) => strlen($v) <= 100000,
        ];
    }
}

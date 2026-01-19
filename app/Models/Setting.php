<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * Cache key prefix for settings
     */
    private const CACHE_PREFIX = 'setting:';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value by key
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            if (! $setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, mixed $value): bool
    {
        $setting = self::where('key', $key)->first();

        if (! $setting) {
            return false;
        }

        // Convert value to string for storage
        $stringValue = is_array($value) ? json_encode($value) : (string) $value;

        $setting->update(['value' => $stringValue]);

        // Clear cache
        Cache::forget(self::CACHE_PREFIX.$key);

        return true;
    }

    /**
     * Get all settings as key-value array
     */
    public static function allSettings(): array
    {
        return self::query()->get()->mapWithKeys(function ($setting) {
            return [$setting->key => self::castValue($setting->value, $setting->type)];
        })->toArray();
    }

    /**
     * Get all settings grouped by group
     */
    public static function allGrouped(): array
    {
        return self::query()->get()->groupBy('group')->map(function ($group) {
            return $group->mapWithKeys(function ($setting) {
                return [$setting->key => [
                    'value' => self::castValue($setting->value, $setting->type),
                    'type' => $setting->type,
                    'description' => $setting->description,
                ]];
            });
        })->toArray();
    }

    /**
     * Cast value to appropriate type
     */
    private static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache(): void
    {
        $keys = self::query()->pluck('key');

        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX.$key);
        }
    }
}

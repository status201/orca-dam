<?php

declare(strict_types=1);

namespace OrcaDam\Api;

/**
 * Tiny transient wrapper. We cache tag lists and folder lists; search results are
 * deliberately uncached because they change too often and the picker already
 * debounces input.
 */
final class Cache
{
    private const PREFIX = 'orca_dam_';

    public function remember(string $key, int $ttlSeconds, callable $factory): mixed
    {
        $stored = get_transient(self::PREFIX . $key);
        if ($stored !== false) {
            return $stored;
        }
        $value = $factory();
        set_transient(self::PREFIX . $key, $value, $ttlSeconds);
        return $value;
    }

    public function forget(string $key): void
    {
        delete_transient(self::PREFIX . $key);
    }
}

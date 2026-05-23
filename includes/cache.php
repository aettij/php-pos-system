<?php

declare(strict_types=1);

final class Cache
{
    private static ?string $dir = null;

    public static function init(): void
    {
        self::$dir = sys_get_temp_dir() . '/superma_cache';
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0755, true);
        }
    }

    public static function get(string $key, int $ttl = 30): ?string
    {
        self::init();
        $path = self::$dir . '/' . md5($key) . '.cache';
        if (!file_exists($path)) return null;
        if (time() - filemtime($path) > $ttl) {
            @unlink($path);
            return null;
        }
        $data = @file_get_contents($path);
        return $data !== false ? $data : null;
    }

    public static function set(string $key, string $value): void
    {
        self::init();
        $path = self::$dir . '/' . md5($key) . '.cache';
        @file_put_contents($path, $value, LOCK_EX);
    }

    public static function remember(string $key, int $ttl, callable $fn): string
    {
        $cached = self::get($key, $ttl);
        if ($cached !== null) return $cached;
        $value = $fn();
        self::set($key, $value);
        return $value;
    }

    public static function forget(string $key): void
    {
        self::init();
        $path = self::$dir . '/' . md5($key) . '.cache';
        if (file_exists($path)) @unlink($path);
    }

    public static function flush(): void
    {
        self::init();
        $files = glob(self::$dir . '/*.cache');
        foreach ($files as $f) @unlink($f);
    }
}

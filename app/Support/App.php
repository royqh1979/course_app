<?php

declare(strict_types=1);

namespace App\Support;

final class App
{
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
        date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai');
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}

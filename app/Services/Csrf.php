<?php

declare(strict_types=1);

namespace App\Services;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(mixed $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($token) && is_string($stored) && hash_equals($stored, $token);
    }

    /** 表单隐藏域 */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}

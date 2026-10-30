<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordService
{
    private const MIN_LENGTH = 8;

    /** 允许的字符集：ASCII 可见字符（0x21–0x7E），不含空格 */
    private const ASCII_VISIBLE = '\x21-\x7E';

    /**
     * 新密码强度策略：长度 ≥8，必须同时包含字母、数字、符号，且全部为 ASCII 可见字符。
     * 返回错误消息；通过则返回 null。
     */
    public static function validatePolicy(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return '密码长度至少 8 位。';
        }
        if (preg_match('/[^' . self::ASCII_VISIBLE . ']/', $password)) {
            return '密码只能使用英文、数字和键盘符号等 ASCII 可见字符，不能包含空格或中文。';
        }
        if (!preg_match('/[A-Za-z]/', $password)
            || !preg_match('/[0-9]/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return '密码必须同时包含字母、数字和符号（如 !@#$%^&*-_=+ 等）。';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}

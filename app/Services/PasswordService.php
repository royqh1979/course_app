<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordService
{
    private const MIN_LENGTH = 8;

    /** 允许的字符集：ASCII 可见字符（0x21–0x7E），不含空格 */
    private const ASCII_VISIBLE = '\x21-\x7E';

    /**
     * 新密码强度策略：长度 ≥8；大写字母、小写字母、数字、符号四类中至少出现三类；
     * 且全部为 ASCII 可见字符（0x21–0x7E，不含空格/中文）。
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
        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $password) ? 1 : 0;
        if ($classes < 3) {
            return '密码须包含大写字母、小写字母、数字、符号中的至少三类。';
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

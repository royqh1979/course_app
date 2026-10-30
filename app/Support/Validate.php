<?php

declare(strict_types=1);

namespace App\Support;

final class Validate
{
    /** 学号按字符串处理：仅清除首尾空格，保留前导零，不做模糊匹配 */
    public static function cleanStudentNo(?string $value): string
    {
        return trim((string) $value);
    }

    /** 姓名仅清除首尾空格 */
    public static function cleanName(?string $value): string
    {
        return trim((string) $value);
    }

    public static function isValidStudentNo(string $value): bool
    {
        return $value !== '' && mb_strlen($value) <= 32 && !preg_match('/\s/u', $value);
    }

    public static function isValidName(string $value): bool
    {
        return $value !== '' && mb_strlen($value) <= 50;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    public function testHashUsesBcrypt(): void
    {
        $hash = PasswordService::hash('secret-password');
        $this->assertStringStartsWith('$2y$', $hash);
    }

    public function testVerifyMatchesCorrectPassword(): void
    {
        $hash = PasswordService::hash('secret-password');
        $this->assertTrue(PasswordService::verify('secret-password', $hash));
    }

    public function testVerifyRejectsWrongPassword(): void
    {
        $hash = PasswordService::hash('secret-password');
        $this->assertFalse(PasswordService::verify('other-password', $hash));
    }

    public function testNeedsRehashDetectsLegacyHash(): void
    {
        // 非 bcrypt 哈希（如旧 MD5）需要重哈希；新生成的 bcrypt 不需要
        $this->assertTrue(PasswordService::needsRehash(md5('x')));
        $this->assertFalse(PasswordService::needsRehash(PasswordService::hash('x')));
    }

    /** 密码强度策略：≥8 位；大写、小写、数字、符号四类中至少三类；仅 ASCII 可见字符 */
    public function testValidatePolicyAcceptsStrongPasswords(): void
    {
        $this->assertNull(PasswordService::validatePolicy('abc12345!'));
        $this->assertNull(PasswordService::validatePolicy('student-pass-123'));
        $this->assertNull(PasswordService::validatePolicy('A1!bcdefg'));
        // 大写+小写+数字（无符号）亦为三类，合法
        $this->assertNull(PasswordService::validatePolicy('Xy9zwQ7a'));
        $this->assertNull(PasswordService::validatePolicy('ABCdef12'));
    }

    public function testValidatePolicyRejectsWeakPasswords(): void
    {
        // 纯数字（一类）
        $this->assertNotNull(PasswordService::validatePolicy('12345678'));
        // 小写+数字（两类）
        $this->assertNotNull(PasswordService::validatePolicy('abcd1234'));
        // 小写+符号（两类）
        $this->assertNotNull(PasswordService::validatePolicy('abcdefgh!'));
        // 大写+小写（两类）
        $this->assertNotNull(PasswordService::validatePolicy('Abcdefgh'));
        // 大写+数字（两类）
        $this->assertNotNull(PasswordService::validatePolicy('ABCD1234'));
        // 长度不足
        $this->assertNotNull(PasswordService::validatePolicy('a1!4567'));
        // 含中文等非 ASCII 可见字符
        $this->assertNotNull(PasswordService::validatePolicy('abc12345!中文'));
        // 含空格
        $this->assertNotNull(PasswordService::validatePolicy('abc 1234!'));
        // 空密码
        $this->assertNotNull(PasswordService::validatePolicy(''));
    }
}

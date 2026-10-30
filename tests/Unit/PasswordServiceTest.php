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
}

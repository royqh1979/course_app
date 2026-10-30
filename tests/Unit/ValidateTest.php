<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Validate;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase
{
    public function testCleanStudentNoTrimsWhitespace(): void
    {
        $this->assertSame('2024001', Validate::cleanStudentNo('  2024001  '));
        $this->assertSame('张三', Validate::cleanName(' 张三 '));
    }

    public function testCleanStudentNoKeepsLeadingZeros(): void
    {
        $this->assertSame('00123', Validate::cleanStudentNo('00123'));
    }

    public function testIsValidStudentNo(): void
    {
        $this->assertTrue(Validate::isValidStudentNo('2024001'));
        $this->assertTrue(Validate::isValidStudentNo('00123'));
        $this->assertFalse(Validate::isValidStudentNo(''));
        $this->assertFalse(Validate::isValidStudentNo('20 24'));
        $this->assertFalse(Validate::isValidStudentNo(str_repeat('1', 33)));
    }

    public function testIsValidName(): void
    {
        $this->assertTrue(Validate::isValidName('张三'));
        $this->assertFalse(Validate::isValidName(''));
        $this->assertFalse(Validate::isValidName(str_repeat('长', 51)));
    }
}

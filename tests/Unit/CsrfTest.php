<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenGeneratedAndStableWithinSession(): void
    {
        $first = Csrf::token();
        $this->assertSame(64, strlen($first));
        $this->assertSame($first, Csrf::token());
    }

    public function testValidateAcceptsCorrectToken(): void
    {
        $this->assertTrue(Csrf::validate(Csrf::token()));
    }

    public function testValidateRejectsWrongOrMissingToken(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::validate('wrong-token'));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
    }

    public function testFieldRendersHiddenInputWithToken(): void
    {
        $field = Csrf::field();
        $this->assertStringContainsString('name="_csrf"', $field);
        $this->assertStringContainsString(Csrf::token(), $field);
    }
}

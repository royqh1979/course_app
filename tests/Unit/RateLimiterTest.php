<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\AuthLogCounter;
use App\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private function limiterWithFailures(int $failures, int $maxAttempts = 5): RateLimiter
    {
        $counter = new class ($failures) implements AuthLogCounter {
            public function __construct(private int $failures)
            {
            }

            public function countRecentFailures(string $userType, string $identifier, int $windowSeconds): int
            {
                return $this->failures;
            }
        };
        return new RateLimiter($counter, $maxAttempts, 600);
    }

    public function testAllowsAttemptsBelowLimit(): void
    {
        $this->assertFalse($this->limiterWithFailures(4)->tooManyAttempts('student', '2024001'));
    }

    public function testBlocksAttemptsAtLimit(): void
    {
        $this->assertTrue($this->limiterWithFailures(5)->tooManyAttempts('student', '2024001'));
    }

    public function testBlocksAttemptsAboveLimit(): void
    {
        $this->assertTrue($this->limiterWithFailures(99)->tooManyAttempts('admin', 'teacher'));
    }
}

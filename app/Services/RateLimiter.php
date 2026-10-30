<?php

declare(strict_types=1);

namespace App\Services;

final class RateLimiter
{
    public function __construct(
        private readonly AuthLogCounter $logs,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 600,
    ) {
    }

    /** 时间窗内失败次数达到上限即拒绝继续尝试 */
    public function tooManyAttempts(string $userType, string $identifier): bool
    {
        return $this->logs->countRecentFailures($userType, $identifier, $this->windowSeconds) >= $this->maxAttempts;
    }
}

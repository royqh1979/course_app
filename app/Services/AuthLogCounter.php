<?php

declare(strict_types=1);

namespace App\Services;

/** 限流所需的失败计数能力，便于单元测试替换实现 */
interface AuthLogCounter
{
    public function countRecentFailures(string $userType, string $identifier, int $windowSeconds): int;
}

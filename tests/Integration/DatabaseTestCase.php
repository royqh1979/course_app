<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * 集成测试基类：每个用例在事务中运行，结束后回滚，互不污染。
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}

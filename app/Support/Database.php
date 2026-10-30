<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function init(array $db): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::init(App::config('db'));
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * 在事务中执行回调，异常时整体回滚。
     * 已处于外层事务时（如集成测试）使用 SAVEPOINT 嵌套，保证内层失败仍能局部回滚。
     */
    private static int $transactionLevel = 0;

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $nested = $pdo->inTransaction();
        $savepoint = 'sp_' . (++self::$transactionLevel);

        $nested ? $pdo->exec("SAVEPOINT {$savepoint}") : $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $nested ? $pdo->exec("RELEASE SAVEPOINT {$savepoint}") : $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $nested ? $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}") : $pdo->rollBack();
            throw $e;
        } finally {
            self::$transactionLevel--;
        }
    }
}

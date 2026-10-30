<?php

declare(strict_types=1);

// 备课区文件夹结构升级脚本（幂等，可重复执行）。
// 为既有数据库补 library_folders 表与 library_handouts/library_assignments 的 folder_id 列。
// 新装环境由 database/schema.sql 直接建全，无需本脚本；执行顺序：先本脚本，后 install_db.php。
// 用法: php scripts/migrate_library_folders.php

use App\Support\App;
use App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
App::init($config);
Database::init($config['db']);

$pdo = Database::pdo();

/** 表是否已存在 */
$tableExists = function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
};

/** 列是否已存在 */
$columnExists = function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$actions = [];

if (!$tableExists('library_folders')) {
    $pdo->exec(
        'CREATE TABLE library_folders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_folders_parent (parent_id),
            KEY idx_folders_type (item_type, parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $actions[] = '已创建表 library_folders';
} else {
    $actions[] = '表 library_folders 已存在，跳过';
}

foreach (['library_handouts', 'library_assignments'] as $table) {
    if (!$columnExists($table, 'folder_id')) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN folder_id INT UNSIGNED DEFAULT NULL AFTER id");
        $actions[] = "已给 {$table} 加 folder_id 列";
    } else {
        $actions[] = "{$table}.folder_id 已存在，跳过";
    }
}

echo '备课区文件夹迁移完成：' . $config['db']['name'] . PHP_EOL;
foreach ($actions as $action) {
    echo ' - ' . $action . PHP_EOL;
}

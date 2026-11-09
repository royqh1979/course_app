<?php

declare(strict_types=1);

// 学生提交多附件升级脚本（幂等，可重复执行）。
// 既有库：submissions.file_id（单文件）→ submission_files（每提交多文件）。
// 新装环境由 database/schema.sql 直接建全，无需本脚本；既有库执行顺序：先本脚本，后部署新代码。
//
// 默认（安全）模式只做三件事，不破坏旧结构，旧代码照常运行：
//   1. CREATE TABLE IF NOT EXISTS submission_files
//   2. 把 submissions.file_id 回填为 submission_files 行（幂等，重复执行不重复插）
//   3. 对账：行数与原提交数一致、逐条 submission_id↔file_id 一致；对账失败退出码 1
// 对账通过且新代码已上线后，再显式执行 --drop-column 清理旧列：
//   php scripts/migrate_submission_files.php --drop-column
// 用法: php scripts/migrate_submission_files.php [--drop-column]

use App\Support\App;
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit('仅允许命令行运行');
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
App::init($config);
Database::init($config['db']);

$pdo = Database::pdo();
$dropColumn = in_array('--drop-column', $argv, true);

$tableExists = function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
};

$columnExists = function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
};

// 1. 建表（纯增量，与 schema.sql 保持一致）
if (!$tableExists('submission_files')) {
    $pdo->exec(
        'CREATE TABLE submission_files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id INT UNSIGNED NOT NULL,
            file_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_submission_files_submission (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    echo "已创建表 submission_files\n";
} else {
    echo "表 submission_files 已存在，跳过创建\n";
}

// 2. 回填（幂等：只插缺失行；submissions.file_id 已不存在的环境跳过）
if ($columnExists('submissions', 'file_id')) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM submission_files')->fetchColumn();
    $pdo->exec(
        'INSERT INTO submission_files (submission_id, file_id)
         SELECT s.id, s.file_id FROM submissions s
         WHERE s.file_id IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM submission_files sf
               WHERE sf.submission_id = s.id AND sf.file_id = s.file_id
           )'
    );
    $after = (int) $pdo->query('SELECT COUNT(*) FROM submission_files')->fetchColumn();
    echo "回填完成：新增 " . ($after - $before) . " 行，submission_files 共 {$after} 行\n";
} else {
    echo "submissions.file_id 列不存在（已迁移），跳过回填\n";
}

// 3. 对账：行数 + 逐条一致（有旧列时才能全量比对）
$total = (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
$count = (int) $pdo->query('SELECT COUNT(*) FROM submission_files')->fetchColumn();
echo "对账：submissions {$total} 行，submission_files {$count} 行\n";
if ($count !== $total) {
    fwrite(STDERR, "对账失败：submission_files 行数（{$count}）≠ submissions 行数（{$total}）\n");
    exit(1);
}
if ($columnExists('submissions', 'file_id')) {
    $mismatch = (int) $pdo->query(
        'SELECT COUNT(*) FROM submissions s
         LEFT JOIN submission_files sf ON sf.submission_id = s.id AND sf.file_id = s.file_id
         WHERE sf.id IS NULL'
    )->fetchColumn();
    if ($mismatch > 0) {
        fwrite(STDERR, "对账失败：{$mismatch} 条提交缺少对应的 submission_files 行\n");
        exit(1);
    }
    echo "对账通过：逐条 submission_id↔file_id 与 submissions.file_id 一致\n";
}

// 4. 清理旧列（需显式 --drop-column；新代码不读此列，删与不删均不影响功能）
if ($dropColumn) {
    if ($columnExists('submissions', 'file_id')) {
        $pdo->exec('ALTER TABLE submissions DROP COLUMN file_id');
        echo "已删除 submissions.file_id 旧列\n";
    } else {
        echo "submissions.file_id 列不存在，跳过删除\n";
    }
} else {
    echo "（未加 --drop-column：保留旧列无害；确认新代码上线无误后可再执行本脚本加 --drop-column 清理）\n";
}

echo '提交多附件迁移完成：' . $config['db']['name'] . PHP_EOL;

<?php

declare(strict_types=1);

/**
 * 孤立文件检查：扫描 storage/ 下业务目录，找出没有 files 表记录对应的磁盘文件。
 *
 * 用法：
 *   php scripts/check_orphan_files.php            仅报告
 *   php scripts/check_orphan_files.php --delete   删除孤立文件与过期临时文件
 *
 * 规则：
 * - storage/courses/、storage/library/ 下的文件必须在 files 表有 stored_path 记录；
 * - storage/imports/、storage/exports/ 为临时目录，超过 24 小时的文件视为过期；
 * - 默认只报告不删除；--delete 时逐个打印删除动作。
 */

use App\Support\App;
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit('仅允许命令行运行');
}

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
App::init($config);

$delete = in_array('--delete', $argv, true);
$storage = rtrim((string) App::config('storage_path'), '/\\');
$staleSeconds = 24 * 3600;

$pdo = Database::pdo();
$knownPaths = array_flip(
    array_map('strval', $pdo->query('SELECT stored_path FROM files')->fetchAll(PDO::FETCH_COLUMN))
);

$orphans = [];
$stale = [];
$scanned = 0;

$scan = function (string $dir, callable $onFile) use (&$scan): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*', GLOB_MARK) ?: [] as $path) {
        if (is_dir($path)) {
            $scan($path, $onFile);
        } else {
            $onFile($path);
        }
    }
};

$collect = function (string $subdir) use ($storage, $scan, &$scanned, &$orphans, &$stale, $knownPaths, $staleSeconds): void {
    $scan($storage . '/' . $subdir, function (string $path) use ($storage, $subdir, &$scanned, &$orphans, &$stale, $knownPaths, $staleSeconds): void {
        $scanned++;
        $relative = str_replace('\\', '/', substr($path, strlen($storage) + 1));
        if (in_array($subdir, ['imports', 'exports', 'tmp'], true)) {
            if (time() - filemtime($path) > $staleSeconds) {
                $stale[] = [$path, $relative];
            }
            return;
        }
        if (!isset($knownPaths[$relative])) {
            $orphans[] = [$path, $relative];
        }
    });
};

foreach (['courses', 'library', 'imports', 'exports', 'tmp'] as $subdir) {
    $collect($subdir);
}

echo "扫描文件：{$scanned} 个\n";

echo "\n[孤立文件]（磁盘存在但 files 表无记录）：" . count($orphans) . " 个\n";
foreach ($orphans as [, $relative]) {
    echo "  - {$relative}\n";
}

echo "\n[过期临时文件]（imports/exports/tmp 超过 24 小时）：" . count($stale) . " 个\n";
foreach ($stale as [, $relative]) {
    echo "  - {$relative}\n";
}

if ($delete) {
    $removed = 0;
    foreach (array_merge($orphans, $stale) as [$path, $relative]) {
        if (unlink($path)) {
            $removed++;
            echo "已删除：{$relative}\n";
        } else {
            echo "删除失败：{$relative}\n";
        }
    }
    echo "\n共删除 {$removed} 个文件。\n";
} else {
    echo "\n（仅报告；加 --delete 执行删除）\n";
}

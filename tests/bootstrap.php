<?php

declare(strict_types=1);

// 测试引导：加载应用，连接测试库并重建表结构。
// 安全约束：只允许在以 _test 结尾的库名上重建，防止误清生产库。

use App\Support\App;
use App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$config['env'] = 'test';
$config['db']['name'] = getenv('TEST_DB_NAME') ?: 'class_app_test';

if (!str_ends_with($config['db']['name'], '_test')) {
    fwrite(STDERR, "测试库名必须以 _test 结尾，当前：{$config['db']['name']}\n");
    exit(1);
}

App::init($config);
Database::init($config['db']);

$pdo = Database::pdo();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}

$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $schema)));
foreach ($statements as $statement) {
    $pdo->exec($statement);
}

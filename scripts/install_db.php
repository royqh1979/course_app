<?php

declare(strict_types=1);

// 初始化数据库表（幂等，可重复执行）。只建表不建库。
// 用法: php scripts/install_db.php

use App\Support\App;
use App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
App::init($config);
Database::init($config['db']);

$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $schema)));

foreach ($statements as $statement) {
    Database::pdo()->exec($statement);
}

echo '数据库表初始化完成：' . $config['db']['name'] . PHP_EOL;

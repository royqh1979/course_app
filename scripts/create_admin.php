<?php

declare(strict_types=1);

// 创建管理员账号（不开放页面注册）。用法: php scripts/create_admin.php <用户名> <密码>

use App\Repositories\AdminRepository;
use App\Services\PasswordService;
use App\Support\App;
use App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "用法: php scripts/create_admin.php <用户名> <密码>\n");
    exit(1);
}

[, $username, $password] = $argv;
$username = trim($username);
if ($username === '') {
    fwrite(STDERR, "用户名不能为空。\n");
    exit(1);
}
$policyError = PasswordService::validatePolicy($password);
if ($policyError !== null) {
    fwrite(STDERR, "密码不符合要求：{$policyError}\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config/config.php';
App::init($config);
Database::init($config['db']);

$admins = new AdminRepository();
if ($admins->findByUsername($username) !== null) {
    fwrite(STDERR, "用户名 {$username} 已存在。\n");
    exit(1);
}

$id = $admins->create($username, PasswordService::hash($password));
echo "管理员已创建：{$username} (id={$id})\n";

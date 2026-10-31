<?php

declare(strict_types=1);

// 修改管理员（教师）账号密码，并使该账号其他已登录会话全部失效。
// 新密码须满足系统密码强度策略（≥8 位、字母+数字+符号、仅 ASCII 可见字符）。
// 用法: php scripts/change_admin_password.php <用户名> <新密码>

use App\Repositories\AdminRepository;
use App\Services\PasswordService;
use App\Support\App;
use App\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "用法: php scripts/change_admin_password.php <用户名> <新密码>\n");
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
$admin = $admins->findByUsername($username);
if ($admin === null) {
    fwrite(STDERR, "管理员 {$username} 不存在。\n");
    exit(1);
}

$admins->changePassword((int) $admin['id'], PasswordService::hash($password));

echo "管理员 {$username} 的密码已修改，其他会话已全部退出。\n";

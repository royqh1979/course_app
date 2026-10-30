<?php

declare(strict_types=1);

// 创建学生账号（学生自助注册属第二阶段，当前用脚本创建）。
// 用法: php scripts/create_student.php <学号> <姓名> <密码>

use App\Repositories\StudentRepository;
use App\Services\PasswordService;
use App\Support\App;
use App\Support\Database;
use App\Support\Validate;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 4) {
    fwrite(STDERR, "用法: php scripts/create_student.php <学号> <姓名> <密码>\n");
    exit(1);
}

[, $studentNo, $name, $password] = $argv;
$studentNo = Validate::cleanStudentNo($studentNo);
$name = Validate::cleanName($name);

if (!Validate::isValidStudentNo($studentNo) || !Validate::isValidName($name)) {
    fwrite(STDERR, "学号或姓名格式不正确。\n");
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

$students = new StudentRepository();
if ($students->findByStudentNo($studentNo) !== null) {
    fwrite(STDERR, "学号 {$studentNo} 已存在。\n");
    exit(1);
}

$id = $students->create($studentNo, $name, PasswordService::hash($password));
echo "学生已创建：{$studentNo} {$name} (id={$id})\n";

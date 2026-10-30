<?php
use App\Services\Csrf;
use App\Support\Session;

$layoutUser = $_SESSION['user'] ?? null;
$layoutIsAdmin = ($layoutUser['type'] ?? '') === 'admin';
$layoutIsStudent = ($layoutUser['type'] ?? '') === 'student';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> - 课程讲义与作业管理系统</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="?r=home">课程管理系统</a>
        <?php if ($layoutUser !== null): ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="展开导航">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($layoutIsAdmin): ?>
                <li class="nav-item"><a class="nav-link" href="?r=admin/courses">课程管理</a></li>
                <li class="nav-item"><a class="nav-link" href="?r=admin/students">学生账号</a></li>
                <li class="nav-item"><a class="nav-link" href="?r=admin/qrcode">注册二维码</a></li>
                <?php elseif ($layoutIsStudent): ?>
                <li class="nav-item"><a class="nav-link" href="?r=student/home">我的课程</a></li>
                <?php endif; ?>
            </ul>
            <div class="d-flex flex-wrap align-items-center text-light gap-2">
                <span class="me-lg-2"><?= e($layoutUser['display_name'] ?? '') ?>（<?= $layoutIsAdmin ? '教师' : '学生' ?>）</span>
                <a class="btn btn-sm btn-outline-light" href="?r=password">修改密码</a>
                <form method="post" action="?r=logout" class="m-0">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-light">退出登录</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container">
    <?php $flashSuccess = Session::flash('success'); if ($flashSuccess !== null): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php $flashError = Session::flash('error'); if ($flashError !== null): ?>
        <div class="alert alert-danger"><?= e($flashError) ?></div>
    <?php endif; ?>
    <?php require $file; ?>
</main>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>

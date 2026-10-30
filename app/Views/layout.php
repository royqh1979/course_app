<?php
use App\Services\Csrf;
use App\Support\Session;

$layoutUser = $_SESSION['user'] ?? null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> - 课程讲义与作业管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="?r=home">课程管理系统</a>
        <?php if ($layoutUser !== null): ?>
        <div class="d-flex align-items-center text-light gap-3">
            <span><?= e($layoutUser['display_name'] ?? '') ?>（<?= $layoutUser['type'] === 'admin' ? '教师' : '学生' ?>）</span>
            <a class="btn btn-sm btn-outline-light" href="?r=password">修改密码</a>
            <form method="post" action="?r=logout" class="m-0">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-sm btn-outline-light">退出登录</button>
            </form>
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
</body>
</html>

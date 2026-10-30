<?php
$actionText = [
    'login' => '登录', 'logout' => '退出', 'register' => '注册',
    'password_change' => '修改密码', 'password_reset' => '重置密码',
];
$resultText = [
    'success' => '成功', 'fail' => '失败', 'rate_limited' => '限流', 'archived' => '已归档',
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">登录与密码日志</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/logs/audit">查看管理操作日志</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="r" value="admin/logs/auth">
    <div class="col-auto">
        <label class="form-label" for="user_type">用户类型</label>
        <select class="form-select form-select-sm" id="user_type" name="user_type">
            <option value=""<?= $userType === '' ? ' selected' : '' ?>>全部</option>
            <option value="admin"<?= $userType === 'admin' ? ' selected' : '' ?>>教师</option>
            <option value="student"<?= $userType === 'student' ? ' selected' : '' ?>>学生</option>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="action">操作</label>
        <select class="form-select form-select-sm" id="action" name="action">
            <option value=""<?= $action === '' ? ' selected' : '' ?>>全部</option>
            <?php foreach ($actionText as $value => $text): ?>
            <option value="<?= e($value) ?>"<?= $action === $value ? ' selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">筛选</button>
    </div>
</form>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr><th>时间</th><th>用户类型</th><th>用户ID</th><th>账号</th><th>操作</th><th>结果</th><th>IP</th><th>User-Agent</th></tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
    <tr>
        <td class="text-nowrap"><?= e($log['created_at']) ?></td>
        <td><?= $log['user_type'] === 'admin' ? '教师' : '学生' ?></td>
        <td><?= $log['user_id'] !== null ? (int) $log['user_id'] : '—' ?></td>
        <td><?= e($log['identifier']) ?></td>
        <td><?= e($actionText[$log['action']] ?? $log['action']) ?></td>
        <td><?= e($resultText[$log['result']] ?? $log['result']) ?></td>
        <td><?= e($log['ip']) ?></td>
        <td class="text-muted small text-truncate" style="max-width: 220px;" title="<?= e($log['user_agent']) ?>"><?= e($log['user_agent']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($logs === []): ?>
    <tr><td colspan="8" class="text-muted">暂无日志。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/logs/auth';
$params = ['user_type' => $userType, 'action' => $action];
$pageParam = 'page';
require __DIR__ . '/../../_pagination.php';
?>

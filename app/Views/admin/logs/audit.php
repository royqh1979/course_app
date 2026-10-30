<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">管理操作日志</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/logs/auth">查看登录与密码日志</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="r" value="admin/logs/audit">
    <div class="col-auto">
        <label class="form-label" for="action">操作类型</label>
        <select class="form-select form-select-sm" id="action" name="action">
            <option value=""<?= $action === '' ? ' selected' : '' ?>>全部</option>
            <?php foreach ($actions as $value): ?>
            <option value="<?= e($value) ?>"<?= $action === $value ? ' selected' : '' ?>><?= e($value) ?></option>
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
    <tr><th>时间</th><th>操作者</th><th>动作</th><th>对象类型</th><th>对象ID</th><th>详情</th><th>IP</th></tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
    <tr>
        <td class="text-nowrap"><?= e($log['created_at']) ?></td>
        <td><?= $log['actor_type'] === 'admin' ? '教师' : e($log['actor_type']) ?>#<?= (int) $log['actor_id'] ?></td>
        <td><?= e($log['action']) ?></td>
        <td><?= e($log['target_type']) ?></td>
        <td><?= e($log['target_id']) ?></td>
        <td class="text-muted small text-truncate" style="max-width: 320px;" title="<?= e($log['detail'] ?? '') ?>"><?= e($log['detail'] ?? '') ?></td>
        <td><?= e($log['ip']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($logs === []): ?>
    <tr><td colspan="7" class="text-muted">暂无日志。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/logs/audit';
$params = ['action' => $action];
$pageParam = 'page';
require __DIR__ . '/../../_pagination.php';
?>

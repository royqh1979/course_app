<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">学生账号管理</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses">返回课程管理</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="r" value="admin/students">
    <div class="col-auto">
        <label class="form-label" for="keyword">姓名 / 学号</label>
        <input class="form-control" id="keyword" name="keyword" value="<?= e($keyword) ?>" placeholder="模糊搜索">
    </div>
    <div class="col-auto">
        <label class="form-label" for="status">账号状态</label>
        <select class="form-select" id="status" name="status">
            <option value=""<?= $status === '' ? ' selected' : '' ?>>全部</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>正常</option>
            <option value="archived"<?= $status === 'archived' ? ' selected' : '' ?>>已归档</option>
        </select>
    </div>
    <div class="col-auto form-check mb-2">
        <input class="form-check-input" type="checkbox" id="unmatched" name="unmatched" value="1"<?= $unmatchedOnly ? ' checked' : '' ?>>
        <label class="form-check-label" for="unmatched">仅看未匹配任何名单</label>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">筛选</button>
    </div>
</form>

<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
    <tr>
        <th>学号</th>
        <th>姓名</th>
        <th>状态</th>
        <th>匹配到的课程</th>
        <th>提交数</th>
        <th>注册时间</th>
        <th>操作</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($students as $student): ?>
    <tr>
        <td><?= e($student['student_no']) ?></td>
        <td><?= e($student['name']) ?></td>
        <td><?= $student['status'] === 'active' ? '正常' : '<span class="text-danger">已归档</span>' ?></td>
        <td><?= $student['matched_courses'] !== null && $student['matched_courses'] !== '' ? e($student['matched_courses']) : '<span class="text-muted">未匹配</span>' ?></td>
        <td><?= (int) $student['submission_count'] ?></td>
        <td><?= e($student['created_at']) ?></td>
        <td class="d-flex gap-1 flex-wrap">
            <?php if ($student['status'] === 'active'): ?>
            <form method="post" action="?r=admin/students/archive" onsubmit="return confirm('确认归档该学生账号？归档后不能登录，历史数据保留。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">归档</button>
            </form>
            <?php else: ?>
            <form method="post" action="?r=admin/students/restore">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">恢复</button>
            </form>
            <?php endif; ?>
            <form method="post" action="?r=admin/students/reset-password" onsubmit="return confirm('确认重置该学生的密码？原密码立即失效。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">重置密码</button>
            </form>
            <?php $deletable = ($student['matched_courses'] === null || $student['matched_courses'] === '') && (int) $student['submission_count'] === 0; ?>
            <?php if ($deletable): ?>
            <form method="post" action="?r=admin/students/delete" onsubmit="return confirm('确认永久删除该学生账号？此操作不可恢复。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($students === []): ?>
    <tr><td colspan="7" class="text-muted">没有符合条件的学生账号。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/students';
$params = ['keyword' => $keyword, 'status' => $status];
if ($unmatchedOnly) {
    $params['unmatched'] = '1';
}
$pageParam = 'page';
require __DIR__ . '/../../_pagination.php';
?>

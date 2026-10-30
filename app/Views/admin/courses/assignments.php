<?php use App\Services\Csrf; ?>
<?php require __DIR__ . '/_course_header.php'; ?>
<?php require __DIR__ . '/_course_tabs.php'; ?>

<?php
$assignmentStatus = ['draft' => ['草稿', 'secondary'], 'published' => ['已发布', 'success'], 'closed' => ['已关闭', 'dark']];
?>
<?php if ($course['status'] === 'active'): ?>
<p>
    <a class="btn btn-primary btn-sm" href="?r=admin/assignments/create&course_id=<?= (int) $course['id'] ?>">新建作业</a>
    <a class="btn btn-sm btn-outline-primary" href="?r=admin/library/import&course_id=<?= (int) $course['id'] ?>&type=assignment">从备课区导入</a>
</p>
<?php endif; ?>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr><th>标题</th><th>截止时间</th><th>允许补交</th><th>状态</th><th>操作</th></tr>
    </thead>
    <tbody>
    <?php foreach ($assignments as $assignment): [$statusText, $statusColor] = $assignmentStatus[$assignment['status']] ?? [$assignment['status'], 'secondary']; ?>
    <tr>
        <td><?= e($assignment['title']) ?></td>
        <td><?= $assignment['deadline_at'] !== null ? e($assignment['deadline_at']) : '不限' ?></td>
        <td><?= (bool) $assignment['allow_late'] ? '允许' : '不允许' ?></td>
        <td><span class="badge bg-<?= e($statusColor) ?>"><?= e($statusText) ?></span></td>
        <td class="d-flex gap-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="?r=admin/assignments/edit&id=<?= (int) $assignment['id'] ?>">编辑</a>
            <a class="btn btn-sm btn-outline-primary" href="?r=admin/assignments/submissions&id=<?= (int) $assignment['id'] ?>">提交/批改</a>
            <a class="btn btn-sm btn-outline-primary" href="?r=admin/assignments/stats&id=<?= (int) $assignment['id'] ?>">统计</a>
            <form method="post" action="?r=admin/library/save-back">
                <?= Csrf::field() ?>
                <input type="hidden" name="type" value="assignment">
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">存入备课区</button>
            </form>
            <?php if ($assignment['status'] !== 'published' && $course['status'] === 'active'): ?>
            <form method="post" action="?r=admin/assignments/publish">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">发布</button>
            </form>
            <?php endif; ?>
            <?php if ($assignment['status'] === 'published'): ?>
            <form method="post" action="?r=admin/assignments/close" onsubmit="return confirm('确认关闭该作业？学生将不能再提交。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">关闭</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($assignments === []): ?>
    <tr><td colspan="5" class="text-muted">暂无作业。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/courses/assignments';
$params = ['id' => (int) $course['id']];
$pageParam = 'page';
require dirname(__DIR__, 2) . '/_pagination.php';

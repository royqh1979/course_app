<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 m-0">提交情况 — <?= e($assignment['title']) ?></h1>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="?r=admin/assignments/export&id=<?= (int) $assignment['id'] ?>">导出 Excel</a>
        <a class="btn btn-outline-primary" href="?r=admin/assignments/zip&id=<?= (int) $assignment['id'] ?>">打包下载 ZIP</a>
        <a class="btn btn-outline-secondary" href="?r=admin/assignments/submissions&id=<?= (int) $assignment['id'] ?>">提交/批改</a>
        <a class="btn btn-outline-secondary" href="?r=admin/courses/show&id=<?= (int) $course['id'] ?>">返回课程</a>
    </div>
</div>
<p class="text-muted">
    <?= e($course['name']) ?>
    ｜截止：<?= $assignment['deadline_at'] !== null ? e($assignment['deadline_at']) : '不限' ?>
    ｜统计以当前课程名单为基准；ZIP 打包含全部有效提交（含已从名单移除学生的提交）。
</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center"><div class="fs-4"><?= $summary['total'] ?></div><div class="text-muted">名单总人数</div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center"><div class="fs-4 text-success"><?= $summary['submitted'] ?></div><div class="text-muted">已提交</div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center"><div class="fs-4 text-danger"><?= $summary['total'] - $summary['submitted'] ?></div><div class="text-muted">未提交</div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center"><div class="fs-4 text-warning"><?= $summary['late'] ?></div><div class="text-muted">迟交</div></div></div>
</div>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr>
        <th>#</th><th>学号</th><th>姓名</th><th>班级</th>
        <?php if ((bool) $assignment['is_group']): ?><th>小组</th><?php endif; ?>
        <th>状态</th><th>迟交</th><th>最近提交时间</th><th>文件</th><th>分数</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $row): $submitted = $row['submission_id'] !== null; ?>
    <tr>
        <td><?= $pagination->offset() + $i + 1 ?></td>
        <td><?= e($row['student_no']) ?></td>
        <td><?= e($row['roster_name']) ?></td>
        <td><?= e($row['class_name']) ?></td>
        <?php if ((bool) $assignment['is_group']): ?>
        <td><?= $row['group_name'] !== null ? e($row['group_name']) : '<span class="text-muted">未入组</span>' ?></td>
        <?php endif; ?>
        <td>
            <?php if ($submitted): ?>
                <span class="badge bg-success">已提交</span>
            <?php else: ?>
                <span class="badge bg-secondary">未提交</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($submitted): ?>
                <?= (bool) $row['is_late'] ? '<span class="badge bg-warning text-dark">迟交</span>' : '按时' ?>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= $submitted ? e($row['last_submitted_at']) : '—' ?></td>
        <td>
            <?php if ($submitted): ?>
                <a href="?r=submission/download&id=<?= (int) $row['submission_id'] ?>"><?= e($row['original_name']) ?></a>
                <span class="text-muted">（<?= e(format_size((int) $row['size'])) ?>）</span>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td>
            <?php if ($submitted && $row['graded_at'] !== null && $row['score'] !== null): ?>
                <?= (int) $row['score'] ?> 分
            <?php elseif ($submitted && $row['graded_at'] !== null): ?>
                已批改
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
    <tr><td colspan="10" class="text-muted">课程名单为空，请先在课程页导入名单。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/assignments/stats';
$params = ['id' => (int) $assignment['id']];
$pageParam = 'page';
require __DIR__ . '/../../_pagination.php';
?>

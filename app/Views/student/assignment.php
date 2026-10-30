<?php
use App\Services\Csrf;

$now = date('Y-m-d H:i:s');
$pastDeadline = $assignment['deadline_at'] !== null && $now > $assignment['deadline_at'];
$canSubmit = $submitBlock === null;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0"><?= e($assignment['title']) ?></h1>
    <a class="btn btn-outline-secondary" href="?r=student/course&id=<?= (int) $course['id'] ?>">返回课程</a>
</div>

<p class="text-muted">
    <?= e($course['name']) ?>
    ｜开始：<?= $assignment['start_at'] !== null ? e($assignment['start_at']) : '不限' ?>
    ｜截止：<?= $assignment['deadline_at'] !== null ? e($assignment['deadline_at']) : '不限' ?>
    <?php if ($assignment['status'] === 'closed'): ?>｜<span class="badge bg-secondary">已关闭</span><?php endif; ?>
</p>

<?php if (!empty($assignment['requirements'])): ?>
<div class="border rounded p-3 mb-3">
    <h2 class="h6">作业要求</h2>
    <p class="mb-0"><?= nl2br(e($assignment['requirements'])) ?></p>
</div>
<?php endif; ?>

<?php if ($attachments !== []): ?>
<h2 class="h6">附件</h2>
<div class="list-group mb-3">
    <?php foreach ($attachments as $att): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
       href="?r=attachment/download&id=<?= (int) $att['id'] ?>">
        <span><?= e($att['original_name']) ?></span>
        <span class="text-muted small"><?= e(format_size((int) $att['size'])) ?>　下载</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="h6 mt-4">我的提交</h2>
<?php if ($submission !== null): ?>
<div class="border rounded p-3 mb-3">
    <p class="mb-1">
        当前文件：<a href="?r=submission/download&id=<?= (int) $submission['id'] ?>"><?= e($submission['original_name']) ?></a>
        （<?= e(format_size((int) $submission['size'])) ?>）
        <?php if ((bool) $submission['is_late']): ?>
            <span class="badge bg-warning text-dark">迟交</span>
        <?php else: ?>
            <span class="badge bg-success">按时</span>
        <?php endif; ?>
    </p>
    <p class="text-muted small mb-0">
        首次提交：<?= e($submission['first_submitted_at']) ?>　最近提交：<?= e($submission['last_submitted_at']) ?>
    </p>
    <?php if ($submission['graded_at'] !== null): ?>
    <hr>
    <p class="mb-1">
        <strong>教师批改</strong>
        <?php if ($submission['score'] !== null): ?>
            <span class="badge bg-primary fs-6"><?= (int) $submission['score'] ?> 分</span>
        <?php endif; ?>
        <span class="text-muted small">（<?= e($submission['graded_at']) ?>）</span>
    </p>
    <?php if ($submission['comment'] !== null && $submission['comment'] !== ''): ?>
        <p class="mb-0"><?= nl2br(e($submission['comment'])) ?></p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>
<p class="text-muted">尚未提交。</p>
<?php endif; ?>

<?php if ($canSubmit): ?>
    <?php if ($pastDeadline): ?>
    <div class="alert alert-warning">已超过截止时间（<?= e($assignment['deadline_at']) ?>），现在提交将记为补交（迟交）。</div>
    <?php endif; ?>
<form method="post" action="?r=student/submissions/submit" enctype="multipart/form-data" class="border rounded p-3">
    <?= Csrf::field() ?>
    <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
    <div class="mb-2">
        <label class="form-label" for="file"><?= $submission === null ? '提交作业' : '重新提交（替换旧文件）' ?></label>
        <input class="form-control" type="file" id="file" name="file"
               accept=".<?= str_replace(',', ',.', e($assignment['allowed_extensions'])) ?>" required>
        <div class="form-text">
            允许类型：<?= e(implode('、', array_map(fn ($e2) => '.' . $e2, explode(',', (string) $assignment['allowed_extensions'])))) ?>
            ；单文件最大 <?= (int) round($assignment['max_file_size'] / 1024 / 1024) ?> MB。
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= $submission === null ? '提交' : '重新提交' ?></button>
</form>
<?php else: ?>
<div class="alert alert-secondary"><?= e($submitBlock) ?></div>
<?php endif; ?>

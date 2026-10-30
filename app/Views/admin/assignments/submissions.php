<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">提交批改 — <?= e($assignment['title']) ?></h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses/show&id=<?= (int) $course['id'] ?>">返回课程</a>
</div>
<p class="text-muted">
    <?= e($course['name']) ?>
    ｜截止：<?= $assignment['deadline_at'] !== null ? e($assignment['deadline_at']) : '不限' ?>
    ｜已提交 <?= count($submissions) ?> 份
</p>

<?php if ($submissions === []): ?>
<div class="alert alert-info">暂无学生提交。</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead>
    <tr>
        <th>学号</th>
        <th>姓名</th>
        <th>提交文件</th>
        <th>最近提交时间</th>
        <th>迟交</th>
        <th style="min-width: 260px;">批改（分数 0–100 / 评语）</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($submissions as $sub): $graded = $sub['graded_at'] !== null; ?>
    <tr>
        <td><?= e($sub['student_no']) ?></td>
        <td><?= e($sub['student_name']) ?></td>
        <td>
            <a href="?r=submission/download&id=<?= (int) $sub['id'] ?>"><?= e($sub['original_name']) ?></a>
            <span class="text-muted">（<?= e(format_size((int) $sub['size'])) ?>）</span>
        </td>
        <td><?= e($sub['last_submitted_at']) ?></td>
        <td>
            <?php if ((bool) $sub['is_late']): ?>
                <span class="badge bg-warning text-dark">迟交</span>
            <?php else: ?>
                <span class="badge bg-success">按时</span>
            <?php endif; ?>
        </td>
        <td>
            <form method="post" action="?r=admin/assignments/grade" class="row g-1 align-items-center">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $sub['id'] ?>">
                <div class="col-3">
                    <input class="form-control form-control-sm" type="number" name="score" min="0" max="100"
                           placeholder="分数" value="<?= $sub['score'] !== null ? (int) $sub['score'] : '' ?>">
                </div>
                <div class="col-6">
                    <input class="form-control form-control-sm" name="comment" maxlength="500"
                           placeholder="评语（可选）" value="<?= e($sub['comment'] ?? '') ?>">
                </div>
                <div class="col-3">
                    <button type="submit" class="btn btn-sm <?= $graded ? 'btn-outline-primary' : 'btn-primary' ?>">
                        <?= $graded ? '更新批改' : '批改' ?>
                    </button>
                </div>
            </form>
            <?php if ($graded): ?>
            <div class="text-muted small mt-1">已批改：<?= e($sub['graded_at']) ?>（学生已不能修改提交，可再次更新批改结果）</div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/assignments/submissions';
$params = ['id' => (int) $assignment['id']];
$pageParam = 'page';
require __DIR__ . '/../../_pagination.php';
?>
<?php endif; ?>

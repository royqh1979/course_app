<h1 class="h3 mb-1"><?= e($course['name']) ?></h1>
<p class="text-muted">学期：<?= e($course['term'] ?: '—') ?></p>
<?php require __DIR__ . '/_course_tabs.php'; ?>

<?php if ($assignments === []): ?>
    <p class="text-muted">暂无已发布的作业。</p>
<?php else: ?>
<div class="list-group">
    <?php foreach ($assignments as $assignment): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
       href="?r=student/assignment&id=<?= (int) $assignment['id'] ?>">
        <span>
            <strong><?= e($assignment['title']) ?></strong>
            <?php if ($assignment['status'] === 'closed'): ?>
                <span class="badge bg-secondary ms-2">已关闭</span>
            <?php endif; ?>
            <?php if (isset($submissionMap[(int) $assignment['id']])): ?>
                <?php if ((int) $submissionMap[(int) $assignment['id']]['is_late'] === 1): ?>
                    <span class="badge bg-warning text-dark ms-2">迟交</span>
                <?php else: ?>
                    <span class="badge bg-success ms-2">已提交</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge text-bg-light ms-2">未提交</span>
            <?php endif; ?>
        </span>
        <span class="text-muted small text-nowrap ms-2">
            <?= $assignment['deadline_at'] !== null ? '截止 ' . e($assignment['deadline_at']) : '无截止时间' ?>
        </span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

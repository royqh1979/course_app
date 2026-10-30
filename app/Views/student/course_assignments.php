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
        </span>
        <span class="text-muted small text-nowrap ms-2">
            <?= $assignment['deadline_at'] !== null ? '截止 ' . e($assignment['deadline_at']) : '无截止时间' ?>
        </span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

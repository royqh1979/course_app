<h1 class="h3 mb-1"><?= e($course['name']) ?></h1>
<p class="text-muted">学期：<?= e($course['term'] ?: '—') ?></p>
<?php if (!empty($course['description'])): ?>
    <p><?= nl2br(e($course['description'])) ?></p>
<?php endif; ?>

<h2 class="h5 mt-4">课程讲义</h2>
<?php if ($handouts === []): ?>
    <p class="text-muted">暂无已发布的讲义。</p>
<?php else: ?>
<div class="list-group mb-4">
    <?php foreach ($handouts as $handout): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
       href="?r=handout/download&id=<?= (int) $handout['id'] ?>">
        <span>
            <strong><?= e($handout['title']) ?></strong>
            <?php if (!empty($handout['description'])): ?>
                <span class="text-muted ms-2"><?= e($handout['description']) ?></span>
            <?php endif; ?>
        </span>
        <span class="text-muted small text-nowrap ms-2"><?= e(format_size((int) $handout['size'])) ?>　下载</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="h5 mt-4">作业</h2>
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

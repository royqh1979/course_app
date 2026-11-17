<h1 class="h3 mb-1"><?= e($course['name']) ?></h1>
<p class="text-muted">学期：<?= e($course['term'] ?: '—') ?></p>
<?php require __DIR__ . '/_course_tabs.php'; ?>

<?php if ($handouts === []): ?>
    <p class="text-muted">暂无已发布的讲义。</p>
<?php else: ?>
<div class="list-group">
    <?php foreach ($handouts as $handout): ?>
    <?php $handoutExists = storage_file_exists($handout['stored_path'] ?? null); ?>
    <a class="list-group-item list-group-item-action<?= $handoutExists ? '' : ' link-primary text-decoration-line-through' ?> d-flex justify-content-between align-items-center"
       href="?r=handout/download&id=<?= (int) $handout['id'] ?>"<?= $handoutExists ? '' : ' title="文件已缺失"' ?>>
        <span>
            <strong><?= e($handout['title']) ?></strong>
            <?php if (!empty($handout['description'])): ?>
                <span class="text-muted ms-2"><?= e($handout['description']) ?></span>
            <?php endif; ?>
        </span>
        <span class="text-muted small text-nowrap ms-2"><?= e(format_size((int) $handout['size'])) ?>　<?= $handoutExists ? '下载' : '文件已缺失' ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">从备课区导入 — <?= e($course['name']) ?></h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses/handouts&id=<?= (int) $course['id'] ?>">返回课程</a>
</div>
<p class="text-muted">导入后生成课程内的草稿副本，与模板各自独立，互不影响。</p>

<?php
$items = $type === 'handout' ? $handouts : $assignments;
$importRoute = 'admin/library/import&course_id=' . (int) $course['id'] . '&type=' . $type;
$linkBuilder = static fn (?int $folderId): string => '?r=' . $importRoute . ($folderId !== null ? '&folder_id=' . $folderId : '');
?>

<div class="row g-3">
    <div class="col-lg-3">
        <?php require __DIR__ . '/_folder_tree.php'; ?>
    </div>
    <div class="col-lg-9">

<nav aria-label="文件夹路径" class="small mb-2">
    <a class="text-decoration-none" href="<?= e($linkBuilder(null)) ?>">根目录</a>
    <?php foreach ($folderPath as $node): ?>
        <span class="text-muted">/</span>
        <?php if ((int) $node['id'] === $currentFolderId): ?>
            <span class="fw-bold"><?= e($node['name']) ?></span>
        <?php else: ?>
            <a class="text-decoration-none" href="<?= e($linkBuilder((int) $node['id'])) ?>"><?= e($node['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<?php if ($childFolders !== []): ?>
<div class="mb-3">
    <?php foreach ($childFolders as $child): ?>
    <a class="btn btn-sm btn-outline-secondary me-1 mb-1"
       href="<?= e($linkBuilder((int) $child['id'])) ?>">📁 <?= e($child['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($items === []): ?>
<div class="alert alert-info">本文件夹暂无可用模板<?= $currentFolderId === null && ($folders !== []) ? '，请从左侧文件夹进入' : '' ?>，也可先到 <a href="?r=<?= e($typeRoutes[$type]) ?>">备课区</a> 添加。</div>
<?php else: ?>
<form method="post" action="?r=admin/library/import">
    <?= Csrf::field() ?>
    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="list-group mb-3">
        <?php foreach ($items as $item): ?>
        <label class="list-group-item d-flex align-items-center gap-2">
            <input class="form-check-input m-0" type="radio" name="template_id" value="<?= (int) $item['id'] ?>" required>
            <span>
                <strong><?= e($item['title']) ?></strong>
                <?php if ($type === 'handout'): ?>
                    <span class="text-muted ms-2"><?= e($item['original_name']) ?></span>
                <?php else: ?>
                    <span class="text-muted ms-2">附件 <?= (int) $item['attachment_count'] ?> 个</span>
                <?php endif; ?>
            </span>
        </label>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-primary">导入为草稿</button>
</form>
<?php endif; ?>

    </div>
</div>

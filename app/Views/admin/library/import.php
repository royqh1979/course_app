<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">从备课区导入 — <?= e($course['name']) ?></h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses/show&id=<?= (int) $course['id'] ?>">返回课程</a>
</div>
<p class="text-muted">导入后生成课程内的草稿副本，与模板各自独立，互不影响。</p>

<?php
$items = $type === 'handout' ? $handouts : $assignments;
?>
<?php if ($items === []): ?>
<div class="alert alert-info">备课区暂无可用模板，请先到 <a href="?r=admin/library">备课区</a> 添加。</div>
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

<?php use App\Services\Csrf; ?>
<h1 class="h3 mb-3"><?= e($title) ?></h1>
<form method="post" action="<?= e($action) ?>" class="col-md-6">
    <?= Csrf::field() ?>
    <?php if ($course !== null): ?>
        <input type="hidden" name="id" value="<?= (int) $course['id'] ?>">
    <?php endif; ?>
    <div class="mb-3">
        <label class="form-label" for="name">课程名称 *</label>
        <input class="form-control" id="name" name="name" required maxlength="100"
               value="<?= e($course['name'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="code">课程代码（可选）</label>
        <input class="form-control" id="code" name="code" maxlength="50"
               value="<?= e($course['code'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label" for="term">学期 / 学年</label>
        <input class="form-control" id="term" name="term" maxlength="50"
               value="<?= e($course['term'] ?? '') ?>" placeholder="如 2025-2026-1">
    </div>
    <div class="mb-3">
        <label class="form-label" for="description">课程简介</label>
        <textarea class="form-control" id="description" name="description" rows="4"><?= e($course['description'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
    <a class="btn btn-outline-secondary" href="?r=admin/courses">返回</a>
</form>

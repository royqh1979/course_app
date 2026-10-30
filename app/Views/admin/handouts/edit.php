<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">编辑讲义</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses/show&id=<?= (int) $handout['course_id'] ?>">返回课程</a>
</div>

<div class="row">
    <div class="col-md-6">
        <form method="post" action="?r=admin/handouts/update" class="mb-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
            <div class="mb-3">
                <label class="form-label" for="title">标题 *</label>
                <input class="form-control" id="title" name="title" required maxlength="200"
                       value="<?= e($handout['title']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="description">说明（可选）</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= e($handout['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>

        <div class="border rounded p-3">
            <h2 class="h6">替换文件</h2>
            <p class="text-muted mb-2">当前文件：<?= e($handout['original_name']) ?>（<?= e(format_size((int) $handout['size'])) ?>）</p>
            <form method="post" action="?r=admin/handouts/replace" enctype="multipart/form-data" data-upload-progress
                  onsubmit="return confirm('确认替换文件？学生将下载到新文件。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <div class="mb-2">
                    <input class="form-control" type="file" name="file" accept=".doc,.docx,.ppt,.pptx,.pdf" required>
                </div>
                <button type="submit" class="btn btn-outline-primary">替换文件</button>
            </form>
        </div>
    </div>
</div>

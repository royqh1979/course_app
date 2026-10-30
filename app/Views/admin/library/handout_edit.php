<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">编辑课件模板</h1>
    <a class="btn btn-outline-secondary"
       href="?r=<?= e($typeRoute) ?><?= $handout['folder_id'] !== null ? '&folder_id=' . (int) $handout['folder_id'] : '' ?>">返回备课区</a>
</div>

<div class="row">
    <div class="col-md-6">
        <form method="post" action="?r=admin/library/handouts/update" class="mb-4">
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
            <div class="mb-3">
                <label class="form-label" for="folder_id">所属文件夹</label>
                <select class="form-select" id="folder_id" name="folder_id">
                    <option value="">根目录</option>
                    <?php foreach ($folderOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"
                            <?= (int) $handout['folder_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= str_repeat('　', $option['depth'] - 1) ?><?= e($option['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>

        <div class="border rounded p-3">
            <h2 class="h6">替换文件</h2>
            <p class="text-muted mb-2">当前文件：<?= e($handout['original_name']) ?>（<?= e(format_size((int) $handout['size'])) ?>）</p>
            <p class="text-muted small">替换只影响之后的导入，已导入的课程副本仍使用原文件。</p>
            <form method="post" action="?r=admin/library/handouts/replace" enctype="multipart/form-data"
                  onsubmit="return confirm('确认替换模板文件？');">
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

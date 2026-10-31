<?php
use App\Services\Csrf;

$selectedExtensions = $assignment !== null
    ? explode(',', (string) $assignment['allowed_extensions'])
    : $submitExtensions;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0"><?= e($title) ?></h1>
    <a class="btn btn-outline-secondary"
       href="?r=<?= e($typeRoute) ?><?= $currentFolderId !== null ? '&folder_id=' . (int) $currentFolderId : '' ?>">返回备课区</a>
</div>
<p class="text-muted">作业不设置开始/截止时间，导入课程后再设置。</p>

<div class="row">
    <div class="col-lg-7">
        <form method="post" action="<?= e($action) ?>">
            <?= Csrf::field() ?>
            <?php if ($assignment !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="title">作业标题 *</label>
                <input class="form-control" id="title" name="title" required maxlength="200"
                       value="<?= e($assignment['title'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="requirements">作业要求（支持多行）</label>
                <textarea class="form-control" id="requirements" name="requirements" rows="6"><?= e($assignment['requirements'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <span class="form-label d-block">允许提交的文件类型 *</span>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($submitExtensions as $ext): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ext_<?= e($ext) ?>"
                               name="allowed_extensions[]" value="<?= e($ext) ?>"
                               <?= in_array($ext, $selectedExtensions, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ext_<?= e($ext) ?>">.<?= e($ext) ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="row g-3 mb-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="max_file_size_mb">单文件大小上限（MB）</label>
                    <input class="form-control" type="number" id="max_file_size_mb" name="max_file_size_mb"
                           min="1" max="200"
                           value="<?= (int) round(($assignment['max_file_size'] ?? 20 * 1024 * 1024) / 1024 / 1024) ?>">
                </div>
                <div class="col-md-8">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="allow_late" name="allow_late" value="1"
                               <?= ($assignment === null || (bool) $assignment['allow_late']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allow_late">允许补交（截止后仍可提交，自动记为迟交）</label>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="folder_id">所属文件夹</label>
                <select class="form-select" id="folder_id" name="folder_id">
                    <option value="">根目录</option>
                    <?php foreach ($folderOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"
                            <?= $currentFolderId === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= str_repeat('　', $option['depth'] - 1) ?><?= e($option['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>
    </div>

    <?php if ($assignment !== null): ?>
    <div class="col-lg-5 mt-4 mt-lg-0">
        <div class="border rounded p-3">
            <h2 class="h6">附件（<?= count($attachments) ?>）</h2>
            <ul class="list-group list-group-flush mb-3">
                <?php foreach ($attachments as $att): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span>
                        <a href="?r=library/attachment/download&id=<?= (int) $att['id'] ?>"><?= e($att['original_name']) ?></a>
                        <span class="text-muted">（<?= e(format_size((int) $att['size'])) ?>）</span>
                    </span>
                    <form method="post" action="?r=admin/library/attachments/delete"
                          onsubmit="return confirm('确认删除该附件？已导入的课程副本不受影响。');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $att['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                    </form>
                </li>
                <?php endforeach; ?>
                <?php if ($attachments === []): ?>
                <li class="list-group-item px-0 text-muted">暂无附件。</li>
                <?php endif; ?>
            </ul>
            <form method="post" action="?r=admin/library/attachments/add" enctype="multipart/form-data" data-upload-batch>
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <div class="mb-2">
                    <input class="form-control" type="file" name="file"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip" required multiple>
                </div>
                <button type="submit" class="btn btn-outline-primary btn-sm">上传附件（可多选）</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

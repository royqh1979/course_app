<?php
/**
 * 备课区侧栏：文件夹树 + 新建文件夹表单。
 * 变量：$type、$folders、$folderCounts、$rootCount、$currentFolderId、$listRoute、$maxDepth
 */
use App\Services\Csrf;

$linkBuilder = static fn (?int $folderId): string => '?r=' . $listRoute . ($folderId !== null ? '&folder_id=' . $folderId : '');
require __DIR__ . '/_folder_tree.php';
?>
<div class="card mt-3">
    <div class="card-header py-2 fw-bold">新建文件夹</div>
    <div class="card-body">
        <form method="post" action="?r=admin/library/folders/store" class="row g-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="item_type" value="<?= e($type) ?>">
            <input type="hidden" name="parent_id" value="<?= $currentFolderId ?? '' ?>">
            <div class="col-12">
                <input class="form-control form-control-sm" name="name" required maxlength="100"
                       placeholder="<?= $currentFolderId !== null ? '在当前文件夹内新建' : '在根目录下新建' ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">新建</button>
            </div>
        </form>
        <?php if ($currentFolderId !== null): ?>
        <p class="text-muted small mt-2 mb-0">当前位于文件夹内，将建为其子文件夹（最多 <?= (int) $maxDepth ?> 层）。</p>
        <?php endif; ?>
    </div>
</div>

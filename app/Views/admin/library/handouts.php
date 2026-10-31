<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">备课区 · 课件模板</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses">返回课程管理</a>
</div>
<p class="text-muted">课件模板库，可按文件夹归类。导入课程后模板与课程副本各自独立：修改/删除模板不影响已导入的课程，反之亦然。</p>

<?php require __DIR__ . '/_library_tabs.php'; ?>

<div class="row g-3">
    <div class="col-lg-3">
        <?php
        $listRoute = $typeRoutes['handout'];
        require __DIR__ . '/_folder_sidebar.php';
        ?>
    </div>
    <div class="col-lg-9">

<div class="d-flex justify-content-between align-items-center mb-2">
    <nav aria-label="文件夹路径" class="small">
        <a class="text-decoration-none" href="?r=<?= e($typeRoutes['handout']) ?>">根目录</a>
        <?php foreach ($folderPath as $node): ?>
            <span class="text-muted">/</span>
            <?php if ((int) $node['id'] === $currentFolderId): ?>
                <span class="fw-bold"><?= e($node['name']) ?></span>
            <?php else: ?>
                <a class="text-decoration-none" href="?r=<?= e($typeRoutes['handout']) ?>&folder_id=<?= (int) $node['id'] ?>"><?= e($node['name']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($currentFolderId !== null): ?>
    <div class="d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#folderRename">重命名</button>
        <form method="post" action="?r=admin/library/folders/delete"
              onsubmit="return confirm('确认删除该文件夹？仅限空文件夹。');">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $currentFolderId ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">删除文件夹</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php if ($currentFolderId !== null): ?>
<div id="folderRename" class="collapse mb-2">
    <form method="post" action="?r=admin/library/folders/rename" class="d-flex gap-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $currentFolderId ?>">
        <input class="form-control form-control-sm w-auto" name="name" required maxlength="100" value="<?= e($folderPath === [] ? '' : $folderPath[count($folderPath) - 1]['name']) ?>">
        <button type="submit" class="btn btn-sm btn-primary">保存名称</button>
    </form>
</div>
<?php endif; ?>

<?php if ($childFolders !== []): ?>
<div class="mb-3">
    <?php foreach ($childFolders as $child): ?>
    <a class="btn btn-sm btn-outline-secondary me-1 mb-1"
       href="?r=<?= e($typeRoutes['handout']) ?>&folder_id=<?= (int) $child['id'] ?>">📁 <?= e($child['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="border rounded p-3 mb-3">
    <form method="post" action="?r=admin/library/handouts/store" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="folder_id" value="<?= $currentFolderId ?? '' ?>">
        <div class="col-md-3">
            <label class="form-label" for="lib_handout_title">标题</label>
            <input class="form-control" id="lib_handout_title" name="title" required maxlength="200">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="lib_handout_desc">说明（可选）</label>
            <input class="form-control" id="lib_handout_desc" name="description" maxlength="500">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="lib_handout_file">文件（doc/docx/ppt/pptx/pdf）</label>
            <input class="form-control" type="file" id="lib_handout_file" name="file" accept=".doc,.docx,.ppt,.pptx,.pdf" required
                   data-autotitle="#lib_handout_title">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">上传课件</button>
        </div>
    </form>
</div>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr><th>标题</th><th>说明</th><th>文件</th><th>更新时间</th><th>操作</th></tr>
    </thead>
    <tbody>
    <?php foreach ($handouts as $handout): ?>
    <tr>
        <td><?= e($handout['title']) ?></td>
        <td><?= e($handout['description'] ?? '') ?></td>
        <td>
            <a href="?r=library/handout/download&id=<?= (int) $handout['id'] ?>"><?= e($handout['original_name']) ?></a>
            <span class="text-muted">（<?= e(format_size((int) $handout['size'])) ?>）</span>
        </td>
        <td><?= e($handout['updated_at']) ?></td>
        <td class="d-flex gap-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="?r=admin/library/handouts/edit&id=<?= (int) $handout['id'] ?>">编辑</a>
            <form method="post" action="?r=admin/library/handouts/delete"
                  onsubmit="return confirm('确认删除该模板？已导入的课程副本不受影响。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($handouts === []): ?>
    <tr><td colspan="5" class="text-muted">本文件夹暂无课件模板。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = $typeRoutes['handout'];
$params = $currentFolderId !== null ? ['folder_id' => (int) $currentFolderId] : [];
$pageParam = 'page';
$pagination = $pagination;
require dirname(__DIR__, 2) . '/_pagination.php';
?>

    </div>
</div>

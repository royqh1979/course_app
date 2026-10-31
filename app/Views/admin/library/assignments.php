<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">备课区 · 作业</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses">返回课程管理</a>
</div>
<p class="text-muted">作业库（要求、允许类型、附件），可按文件夹归类。不设置开始/截止时间，导入课程后再设置。</p>

<?php require __DIR__ . '/_library_tabs.php'; ?>

<div class="row g-3">
    <div class="col-lg-3">
        <?php
        $listRoute = $typeRoutes['assignment'];
        require __DIR__ . '/_folder_sidebar.php';
        ?>
    </div>
    <div class="col-lg-9">

<div class="d-flex justify-content-between align-items-center mb-2">
    <nav aria-label="文件夹路径" class="small">
        <a class="text-decoration-none" href="?r=<?= e($typeRoutes['assignment']) ?>">根目录</a>
        <?php foreach ($folderPath as $node): ?>
            <span class="text-muted">/</span>
            <?php if ((int) $node['id'] === $currentFolderId): ?>
                <span class="fw-bold"><?= e($node['name']) ?></span>
            <?php else: ?>
                <a class="text-decoration-none" href="?r=<?= e($typeRoutes['assignment']) ?>&folder_id=<?= (int) $node['id'] ?>"><?= e($node['name']) ?></a>
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
       href="?r=<?= e($typeRoutes['assignment']) ?>&folder_id=<?= (int) $child['id'] ?>">📁 <?= e($child['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p>
    <a class="btn btn-primary btn-sm" href="?r=admin/library/assignments/create<?= $currentFolderId !== null ? '&folder_id=' . (int) $currentFolderId : '' ?>">新建作业</a>
</p>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr><th>标题</th><th>附件数</th><th>允许补交</th><th>更新时间</th><th>操作</th></tr>
    </thead>
    <tbody>
    <?php foreach ($assignments as $assignment): ?>
    <tr>
        <td><?= e($assignment['title']) ?></td>
        <td><?= (int) $assignment['attachment_count'] ?></td>
        <td><?= (bool) $assignment['allow_late'] ? '允许' : '不允许' ?></td>
        <td><?= e($assignment['updated_at']) ?></td>
        <td class="d-flex gap-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="?r=admin/library/assignments/edit&id=<?= (int) $assignment['id'] ?>">编辑</a>
            <form method="post" action="?r=admin/library/assignments/delete"
                  onsubmit="return confirm('确认删除该作业？已导入的课程副本不受影响。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($assignments === []): ?>
    <tr><td colspan="5" class="text-muted">本文件夹暂无作业。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = $typeRoutes['assignment'];
$params = $currentFolderId !== null ? ['folder_id' => (int) $currentFolderId] : [];
$pageParam = 'page';
$pagination = $pagination;
require dirname(__DIR__, 2) . '/_pagination.php';
?>

    </div>
</div>

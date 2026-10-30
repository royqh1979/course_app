<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">备课区</h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses">返回课程管理</a>
</div>
<p class="text-muted">课件与作业模板库。导入课程后模板与课程副本各自独立：修改/删除模板不影响已导入的课程，反之亦然。</p>

<h2 class="h5 mt-4">课件模板（<?= $handoutPagination->total ?>）</h2>
<div class="border rounded p-3 mb-3">
    <form method="post" action="?r=admin/library/handouts/store" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= Csrf::field() ?>
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
            <input class="form-control" type="file" id="lib_handout_file" name="file" accept=".doc,.docx,.ppt,.pptx,.pdf" required>
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
    <tr><td colspan="5" class="text-muted">暂无课件模板。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/library';
$params = ['ap' => $assignmentPagination->page];
$pageParam = 'hp';
$pagination = $handoutPagination;
require __DIR__ . '/../_pagination.php';
?>

<h2 class="h5 mt-4">作业模板（<?= $assignmentPagination->total ?>）</h2>
<p><a class="btn btn-primary btn-sm" href="?r=admin/library/assignments/create">新建作业模板</a></p>
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
                  onsubmit="return confirm('确认删除该模板？已导入的课程副本不受影响。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($assignments === []): ?>
    <tr><td colspan="5" class="text-muted">暂无作业模板。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/library';
$params = ['hp' => $handoutPagination->page];
$pageParam = 'ap';
$pagination = $assignmentPagination;
require __DIR__ . '/../_pagination.php';
?>

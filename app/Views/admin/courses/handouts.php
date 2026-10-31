<?php use App\Services\Csrf; ?>
<?php require __DIR__ . '/_course_header.php'; ?>
<?php require __DIR__ . '/_course_tabs.php'; ?>

<?php
$handoutStatus = ['draft' => ['草稿', 'secondary'], 'published' => ['已发布', 'success'], 'offline' => ['已下线', 'dark']];
?>
<?php if ($course['status'] === 'active'): ?>
<div class="border rounded p-3 mb-3">
    <form method="post" action="?r=admin/handouts/store" enctype="multipart/form-data" data-upload-progress data-upload-batch class="row g-2 align-items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
        <div class="col-md-3">
            <label class="form-label" for="handout_title">标题</label>
            <input class="form-control" id="handout_title" name="title" required maxlength="200">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="handout_desc">说明（可选）</label>
            <input class="form-control" id="handout_desc" name="description" maxlength="500">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="handout_file">文件（doc/docx/ppt/pptx/pdf/zip/xls/xlsx）</label>
            <input class="form-control" type="file" id="handout_file" name="file" accept=".doc,.docx,.ppt,.pptx,.pdf,.zip,.xls,.xlsx" required
                   multiple data-autotitle="#handout_title">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">上传讲义</button>
        </div>
        <div class="col-12">
            <div data-dropzone class="rounded p-3 text-center text-muted small" style="border: 2px dashed #ccc;">
                批量上传：将多个课件文件拖到此处，或在上方文件框按住 Ctrl/⌘ 多选——每个文件各生成一份讲义草稿
            </div>
        </div>
    </form>
</div>
<p>
    <a class="btn btn-sm btn-outline-primary" href="?r=admin/library/import&course_id=<?= (int) $course['id'] ?>&type=handout">从备课区导入</a>
</p>
<?php endif; ?>
<div id="handout-panel">
<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr><th>标题</th><th>文件</th><th>状态</th><th>发布时间</th><th>操作</th></tr>
    </thead>
    <tbody>
    <?php foreach ($handouts as $handout): [$statusText, $statusColor] = $handoutStatus[$handout['status']] ?? [$handout['status'], 'secondary']; ?>
    <tr>
        <td><?= e($handout['title']) ?></td>
        <td>
            <a href="?r=handout/download&id=<?= (int) $handout['id'] ?>"><?= e($handout['original_name']) ?></a>
            <span class="text-muted">（<?= e(format_size((int) $handout['size'])) ?>）</span>
        </td>
        <td><span class="badge bg-<?= e($statusColor) ?>"><?= e($statusText) ?></span></td>
        <td><?= $handout['published_at'] !== null ? e($handout['published_at']) : '—' ?></td>
        <td class="d-flex gap-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="?r=admin/handouts/edit&id=<?= (int) $handout['id'] ?>">编辑</a>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none save-back-trigger"
                    data-sb-type="handout" data-sb-id="<?= (int) $handout['id'] ?>" data-sb-title="<?= e($handout['title']) ?>">存入备课区</button>
            <noscript>
                <form method="post" action="?r=admin/library/save-back">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="type" value="handout">
                    <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">存入备课区</button>
                </form>
            </noscript>
            <?php if ($handout['status'] !== 'published' && $course['status'] === 'active'): ?>
            <form method="post" action="?r=admin/handouts/publish" data-async-action data-async-target="#handout-panel">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">发布</button>
            </form>
            <?php endif; ?>
            <?php if ($handout['status'] === 'published'): ?>
            <form method="post" action="?r=admin/handouts/offline" data-async-action data-async-target="#handout-panel">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">下线</button>
            </form>
            <?php endif; ?>
            <form method="post" action="?r=admin/handouts/delete" data-async-action data-async-target="#handout-panel"
                  onsubmit="return confirm('<?= $handout['status'] === 'published' ? '该讲义已发布，删除后学生将不可访问，确认删除？' : '确认删除该讲义？此操作不可恢复。' ?>');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($handouts === []): ?>
    <tr><td colspan="5" class="text-muted">暂无讲义。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/courses/handouts';
$params = ['id' => (int) $course['id']];
$pageParam = 'page';
require dirname(__DIR__, 2) . '/_pagination.php';
?>
</div>
<?php // #handout-panel 结束：发布/下线/删除为无刷新操作，成功后仅替换本面板（保留滚动位置）；存入备课区见下方模态框
require __DIR__ . '/_saveback_modal.php';

<?php use App\Services\Csrf; ?>
<?php require __DIR__ . '/_course_header.php'; ?>
<?php require __DIR__ . '/_course_tabs.php'; ?>

<p class="text-muted">名单与学生账号“学号 + 姓名”同时匹配后，学生即获得本课程访问权限；导入后权限即时生效。</p>

<?php if ($course['status'] === 'active'): ?>
<div class="border rounded p-3 mb-3">
    <h2 class="h6">Excel 批量导入（增量导入：新学号新增、已有学号更新姓名和班级）</h2>
    <form method="post" action="?r=admin/roster/import" enctype="multipart/form-data" data-upload-progress class="row g-2 align-items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
        <div class="col-auto">
            <label class="form-label" for="roster_file">名单文件（.xlsx / .xls，≤5MB）</label>
            <input class="form-control" type="file" id="roster_file" name="roster_file" accept=".xlsx,.xls" required>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">上传并预校验</button>
        </div>
        <div class="col-auto">
            <a class="btn btn-outline-secondary" href="?r=admin/roster/template">下载标准模板</a>
        </div>
    </form>
</div>

<form method="post" action="?r=admin/roster/add" class="row g-2 align-items-end mb-3">
    <?= Csrf::field() ?>
    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
    <div class="col-auto">
        <label class="form-label" for="student_no">学号</label>
        <input class="form-control" id="student_no" name="student_no" required maxlength="32">
    </div>
    <div class="col-auto">
        <label class="form-label" for="name">姓名</label>
        <input class="form-control" id="name" name="name" required maxlength="50">
    </div>
    <div class="col-auto">
        <label class="form-label" for="class_name">班级</label>
        <input class="form-control" id="class_name" name="class_name" maxlength="50">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">添加</button>
    </div>
</form>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-sm table-striped">
    <thead>
    <tr><th>学号</th><th>姓名</th><th>班级</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($roster as $entry): ?>
    <tr>
        <td><?= e($entry['student_no']) ?></td>
        <td><?= e($entry['name']) ?></td>
        <td><?= e($entry['class_name']) ?></td>
        <td>
            <form method="post" action="?r=admin/roster/delete" onsubmit="return confirm('确认移除该名单记录？');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">移除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($roster === []): ?>
    <tr><td colspan="4" class="text-muted">暂无名单。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php
$route = 'admin/courses/roster';
$params = ['id' => (int) $course['id']];
$pageParam = 'page';
require dirname(__DIR__, 2) . '/_pagination.php';

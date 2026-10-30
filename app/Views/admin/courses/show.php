<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0"><?= e($course['name']) ?></h1>
    <div>
        <a class="btn btn-outline-secondary" href="?r=admin/courses/edit&id=<?= (int) $course['id'] ?>">编辑课程</a>
        <a class="btn btn-outline-secondary" href="?r=admin/courses">返回列表</a>
    </div>
</div>
<p class="text-muted">
    课程代码：<?= e($course['code'] ?? '—') ?>　学期：<?= e($course['term'] ?: '—') ?>　状态：<?= $course['status'] === 'active' ? '进行中' : '已归档' ?>
</p>
<?php if (!empty($course['description'])): ?>
    <p><?= nl2br(e($course['description'])) ?></p>
<?php endif; ?>

<h2 class="h5 mt-4">课程名单（<?= count($roster) ?> 人）</h2>
<p class="text-muted">名单与学生账号“学号 + 姓名”同时匹配后，学生即获得本课程访问权限；导入后权限即时生效。</p>

<?php if ($course['status'] === 'active'): ?>
<div class="border rounded p-3 mb-3">
    <h3 class="h6">Excel 批量导入（增量导入：新学号新增、已有学号更新姓名和班级）</h3>
    <form method="post" action="?r=admin/roster/import" enctype="multipart/form-data" class="row g-2 align-items-end">
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

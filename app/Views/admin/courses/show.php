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

<h2 class="h5 mt-4">讲义（<?= count($handouts) ?>）</h2>
<?php
$handoutStatus = ['draft' => ['草稿', 'secondary'], 'published' => ['已发布', 'success'], 'offline' => ['已下线', 'dark']];
?>
<?php if ($course['status'] === 'active'): ?>
<div class="border rounded p-3 mb-3">
    <form method="post" action="?r=admin/handouts/store" enctype="multipart/form-data" class="row g-2 align-items-end">
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
            <label class="form-label" for="handout_file">文件（doc/docx/ppt/pptx/pdf）</label>
            <input class="form-control" type="file" id="handout_file" name="file" accept=".doc,.docx,.ppt,.pptx,.pdf" required>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">上传讲义</button>
        </div>
    </form>
</div>
<?php endif; ?>
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
            <?php if ($handout['status'] !== 'published' && $course['status'] === 'active'): ?>
            <form method="post" action="?r=admin/handouts/publish">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">发布</button>
            </form>
            <?php endif; ?>
            <?php if ($handout['status'] === 'published'): ?>
            <form method="post" action="?r=admin/handouts/offline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $handout['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">下线</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($handouts === []): ?>
    <tr><td colspan="5" class="text-muted">暂无讲义。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<h2 class="h5 mt-4">作业（<?= count($assignments) ?>）</h2>
<?php
$assignmentStatus = ['draft' => ['草稿', 'secondary'], 'published' => ['已发布', 'success'], 'closed' => ['已关闭', 'dark']];
?>
<?php if ($course['status'] === 'active'): ?>
<p><a class="btn btn-primary btn-sm" href="?r=admin/assignments/create&course_id=<?= (int) $course['id'] ?>">新建作业</a></p>
<?php endif; ?>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead>
    <tr><th>标题</th><th>截止时间</th><th>允许补交</th><th>状态</th><th>操作</th></tr>
    </thead>
    <tbody>
    <?php foreach ($assignments as $assignment): [$statusText, $statusColor] = $assignmentStatus[$assignment['status']] ?? [$assignment['status'], 'secondary']; ?>
    <tr>
        <td><?= e($assignment['title']) ?></td>
        <td><?= $assignment['deadline_at'] !== null ? e($assignment['deadline_at']) : '不限' ?></td>
        <td><?= (bool) $assignment['allow_late'] ? '允许' : '不允许' ?></td>
        <td><span class="badge bg-<?= e($statusColor) ?>"><?= e($statusText) ?></span></td>
        <td class="d-flex gap-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="?r=admin/assignments/edit&id=<?= (int) $assignment['id'] ?>">编辑</a>
            <a class="btn btn-sm btn-outline-primary" href="?r=admin/assignments/submissions&id=<?= (int) $assignment['id'] ?>">提交/批改</a>
            <?php if ($assignment['status'] !== 'published' && $course['status'] === 'active'): ?>
            <form method="post" action="?r=admin/assignments/publish">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">发布</button>
            </form>
            <?php endif; ?>
            <?php if ($assignment['status'] === 'published'): ?>
            <form method="post" action="?r=admin/assignments/close" onsubmit="return confirm('确认关闭该作业？学生将不能再提交。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">关闭</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($assignments === []): ?>
    <tr><td colspan="5" class="text-muted">暂无作业。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

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

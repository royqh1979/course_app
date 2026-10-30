<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">名单导入预校验 — <?= e($course['name']) ?></h1>
    <a class="btn btn-outline-secondary" href="?r=admin/courses/show&id=<?= (int) $course['id'] ?>">取消并返回</a>
</div>
<p class="text-muted">文件：<?= e($fileName) ?>（当前尚未写入数据库，确认后才生效）</p>

<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="border rounded p-3 text-center"><div class="fs-4"><?= (int) $result['total'] ?></div><div class="text-muted">总行数</div></div></div>
    <div class="col-md-2"><div class="border rounded p-3 text-center"><div class="fs-4 text-success"><?= count($result['valid']) ?></div><div class="text-muted">有效行</div></div></div>
    <div class="col-md-2"><div class="border rounded p-3 text-center"><div class="fs-4 text-danger"><?= count($result['errors']) ?></div><div class="text-muted">错误行</div></div></div>
    <div class="col-md-2"><div class="border rounded p-3 text-center"><div class="fs-4 text-warning"><?= count($result['duplicates']) ?></div><div class="text-muted">文件内重复行</div></div></div>
    <div class="col-md-4"><div class="border rounded p-3 text-center"><div class="fs-4"><?= (int) $result['insert_count'] ?> 新增 / <?= (int) $result['update_count'] ?> 更新</div><div class="text-muted">相对当前名单</div></div></div>
</div>

<?php if ($result['errors'] !== []): ?>
<h2 class="h5 text-danger">错误行（导入时将被跳过）</h2>
<table class="table table-sm table-striped mb-4">
    <thead><tr><th style="width:120px">Excel 行号</th><th>原因</th></tr></thead>
    <tbody>
    <?php foreach ($result['errors'] as $error): ?>
    <tr><td>第 <?= (int) $error['row'] ?> 行</td><td><?= e($error['message']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($result['duplicates'] !== []): ?>
<h2 class="h5 text-warning">文件内重复学号（导入时将被跳过）</h2>
<table class="table table-sm table-striped mb-4">
    <thead><tr><th style="width:120px">Excel 行号</th><th>学号</th><th>说明</th></tr></thead>
    <tbody>
    <?php foreach ($result['duplicates'] as $dup): ?>
    <tr>
        <td>第 <?= (int) $dup['row'] ?> 行</td>
        <td><?= e($dup['student_no']) ?></td>
        <td>与第 <?= (int) $dup['first_row'] ?> 行重复，以先出现的一行为准</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2 class="h5">有效数据预览（前 20 行）</h2>
<table class="table table-sm table-striped mb-4">
    <thead><tr><th>Excel 行号</th><th>学号</th><th>姓名</th><th>班级</th><th>导入动作</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($result['valid'], 0, 20) as $row): ?>
    <tr>
        <td><?= (int) $row['row'] ?></td>
        <td><?= e($row['student_no']) ?></td>
        <td><?= e($row['name']) ?></td>
        <td><?= e($row['class_name']) ?></td>
        <td><?= $row['action'] === 'update' ? '更新已有记录' : '新增' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($result['valid'] === []): ?>
    <tr><td colspan="5" class="text-muted">没有可导入的有效行。</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if ($result['valid'] !== []): ?>
<form method="post" action="?r=admin/roster/import/confirm" class="d-inline"
      onsubmit="return confirm('确认导入 <?= count($result['valid']) ?> 条有效记录？错误行与重复行将被跳过。');">
    <?= Csrf::field() ?>
    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
    <button type="submit" class="btn btn-primary">确认导入 <?= count($result['valid']) ?> 条记录</button>
</form>
<?php endif; ?>

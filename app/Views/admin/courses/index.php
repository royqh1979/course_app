<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">课程管理</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="?r=admin/students">学生账号管理</a>
        <a class="btn btn-primary" href="?r=admin/courses/create">新建课程</a>
    </div>
</div>
<table class="table table-striped align-middle">
    <thead>
    <tr>
        <th>课程名称</th>
        <th>课程代码</th>
        <th>学期</th>
        <th>状态</th>
        <th>操作</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($courses as $course): ?>
    <tr>
        <td><a href="?r=admin/courses/show&id=<?= (int) $course['id'] ?>"><?= e($course['name']) ?></a></td>
        <td><?= e($course['code'] ?? '') ?></td>
        <td><?= e($course['term']) ?></td>
        <td><?= $course['status'] === 'active' ? '进行中' : '已归档' ?></td>
        <td class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="?r=admin/courses/edit&id=<?= (int) $course['id'] ?>">编辑</a>
            <?php if ($course['status'] === 'active'): ?>
            <form method="post" action="?r=admin/courses/archive" onsubmit="return confirm('确认归档该课程？归档后学生不可见，历史数据保留。');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $course['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">归档</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($courses === []): ?>
    <tr><td colspan="5" class="text-muted">还没有课程，点击右上角新建。</td></tr>
    <?php endif; ?>
    </tbody>
</table>

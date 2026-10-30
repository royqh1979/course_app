<?php
/** 课程页头部。变量：$course */
?>
<div class="d-flex justify-content-between align-items-center mb-2">
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

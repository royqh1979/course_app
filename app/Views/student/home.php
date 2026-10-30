<h1 class="h3 mb-3">我的课程</h1>
<?php if ($courses === []): ?>
    <div class="alert alert-info">暂未匹配到课程名单。如已选课，请联系教师核对名单中的学号与姓名。</div>
<?php else: ?>
<div class="list-group">
    <?php foreach ($courses as $course): ?>
    <a class="list-group-item list-group-item-action" href="?r=student/course/handouts&id=<?= (int) $course['id'] ?>">
        <strong><?= e($course['name']) ?></strong>
        <span class="text-muted ms-2"><?= e($course['term']) ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

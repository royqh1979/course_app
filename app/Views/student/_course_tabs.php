<?php
/** 学生课程子导航。变量：$course、$tab（handouts/assignments/groups） */
$tabs = ['handouts' => '讲义', 'assignments' => '作业', 'groups' => '小组'];
?>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
           href="?r=student/course/<?= $key ?>&id=<?= (int) $course['id'] ?>"><?= $label ?></a>
    </li>
    <?php endforeach; ?>
</ul>

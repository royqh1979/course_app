<?php
/** 课程子导航。变量：$course、$tab（handouts/assignments/roster/groups）、$tabCounts */
$tabs = [
    'handouts' => '讲义',
    'assignments' => '作业',
    'roster' => '名单',
    'groups' => '分组',
];
?>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
           href="?r=admin/courses/<?= $key ?>&id=<?= (int) $course['id'] ?>">
            <?= $label ?> <span class="badge bg-secondary"><?= (int) ($tabCounts[$key] ?? 0) ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<?php
/** 备课区子导航。变量：$type（handout/assignment）、$typeRoutes（类型 → 列表页路由） */
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $type === 'handout' ? 'active' : '' ?>" href="?r=<?= e($typeRoutes['handout']) ?>">课件模板</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $type === 'assignment' ? 'active' : '' ?>" href="?r=<?= e($typeRoutes['assignment']) ?>">作业模板</a>
    </li>
</ul>

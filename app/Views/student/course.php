<h1 class="h3 mb-3"><?= e($course['name']) ?></h1>
<p class="text-muted">学期：<?= e($course['term'] ?: '—') ?></p>
<?php if (!empty($course['description'])): ?>
    <p><?= nl2br(e($course['description'])) ?></p>
<?php endif; ?>
<div class="alert alert-secondary mt-4">讲义下载与作业提交功能将在后续阶段开放。</div>

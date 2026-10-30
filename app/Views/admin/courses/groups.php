<?php require __DIR__ . '/_course_header.php'; ?>
<?php require __DIR__ . '/_course_tabs.php'; ?>

<p class="text-muted">
    小组人数限制：下限 <?= $course['group_min_members'] !== null ? (int) $course['group_min_members'] . ' 人（提交时检查）' : '不限' ?>
    ｜上限 <?= $course['group_max_members'] !== null ? (int) $course['group_max_members'] . ' 人（加入时检查）' : '不限' ?>
    （可在编辑课程中调整）。本页只读，分组由学生在课程小组页自行组建。
</p>

<?php if ($groups === []): ?>
<p class="text-muted">暂无小组。</p>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($groups as $group): ?>
    <div class="col-md-6 col-lg-4">
        <div class="border rounded p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong><?= e($group['name']) ?></strong>
                <span class="badge bg-secondary"><?= (int) $group['member_count'] ?> 人</span>
            </div>
            <div class="small text-muted mb-1">组长：<?= e($group['leader_name']) ?>（<?= e($group['leader_no']) ?>）</div>
            <div class="small">
                <?php
                $names = [];
                foreach ($groupMembers as $gm) {
                    if ((int) $gm['group_id'] === (int) $group['id'] && $gm['status'] === 'active') {
                        $names[] = e($gm['student_no'] . ' ' . $gm['name']);
                    }
                }
                echo $names === [] ? '<span class="text-muted">—</span>' : implode('<br>', $names);
                ?>
            </div>
            <?php if ((int) $group['pending_count'] > 0): ?>
            <div class="small text-warning mt-1"><?= (int) $group['pending_count'] ?> 条待审批申请</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

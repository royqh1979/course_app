<h1 class="h3 mb-1"><?= e($course['name']) ?> · 课程小组</h1>
<p class="text-muted">学期：<?= e($course['term'] ?: '—') ?></p>
<?php require __DIR__ . '/_course_tabs.php'; ?>
<?php use App\Services\Csrf; ?>

<?php if ($myMembership !== null): ?>
    <?php if ($myMembership['status'] === 'pending'): ?>
    <div class="border rounded p-3 mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>您已申请加入「<?= e($myMembership['group_name']) ?>」，<span class="text-warning">待组长审批</span>。</span>
        <form method="post" action="?r=student/group/cancel-apply">
            <?= Csrf::field() ?>
            <input type="hidden" name="member_id" value="<?= (int) $myMembership['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">撤销申请</button>
        </form>
    </div>
    <?php else: ?>
    <div class="border rounded p-3 mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
            我的小组：<strong><?= e($myMembership['group_name']) ?></strong>
            <?php if ((int) $myMembership['leader_student_id'] === (int) ($_SESSION['user']['id'] ?? 0)): ?>
                <span class="badge bg-primary">组长</span>
            <?php endif; ?>
        </span>
        <a class="btn btn-sm btn-outline-primary" href="?r=student/group&id=<?= (int) $myMembership['group_id'] ?>">小组管理</a>
    </div>
    <?php endif; ?>
<?php else: ?>
<div class="border rounded p-3 mb-3">
    <form method="post" action="?r=student/group/create" class="row g-2 align-items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
        <div class="col-auto">
            <label class="form-label" for="group_name">创建小组（创建者为组长）</label>
            <input class="form-control" id="group_name" name="name" required maxlength="50" placeholder="组名">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">创建</button>
        </div>
    </form>
</div>
    <?php if ($groups !== []): ?>
    <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
        <thead><tr><th>组名</th><th>组长</th><th>人数</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
        <tr>
            <td><?= e($group['name']) ?></td>
            <td><?= e($group['leader_name']) ?></td>
            <td><?= (int) $group['member_count'] ?><?= $course['group_max_members'] !== null ? ' / ' . (int) $course['group_max_members'] : '' ?></td>
            <td>
                <form method="post" action="?r=student/group/apply">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">申请加入</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p class="text-muted">还没有小组，您可以创建第一个。</p>
    <?php endif; ?>
<?php endif; ?>

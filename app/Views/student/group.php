<?php use App\Services\Csrf; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">小组：<?= e($group['name']) ?></h1>
    <a class="btn btn-outline-secondary" href="?r=student/course/groups&id=<?= (int) $course['id'] ?>">返回课程</a>
</div>
<p class="text-muted">
    <?= e($course['name']) ?>｜组长：<?= e($group['leader_name']) ?>（<?= e($group['leader_no']) ?>）
    <?php if ($course['group_min_members'] !== null): ?>｜提交要求至少 <?= (int) $course['group_min_members'] ?> 人<?php endif; ?>
    <?php if ($course['group_max_members'] !== null): ?>｜每组最多 <?= (int) $course['group_max_members'] ?> 人<?php endif; ?>
</p>

<?php if ($myMembership['status'] === 'pending'): ?>
<div class="alert alert-info">您的加入申请正在等待组长审批。</div>
<?php endif; ?>

<h2 class="h6 mt-4">成员（<?= count($members) ?> 人）</h2>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead><tr><th>学号</th><th>姓名</th><th>角色</th><?php if ($isLeader): ?><th>操作</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($members as $member): $isRowLeader = (int) $member['student_id'] === (int) $group['leader_student_id']; ?>
    <tr>
        <td><?= e($member['student_no']) ?></td>
        <td><?= e($member['name']) ?></td>
        <td><?= $isRowLeader ? '<span class="badge bg-primary">组长</span>' : '组员' ?></td>
        <?php if ($isLeader): ?>
        <td class="d-flex gap-1">
            <?php if (!$isRowLeader): ?>
            <form method="post" action="?r=student/group/transfer" onsubmit="return confirm('确认将组长转让给该成员？');">
                <?= Csrf::field() ?>
                <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">转让组长</button>
            </form>
            <form method="post" action="?r=student/group/remove-member" onsubmit="return confirm('确认移除该成员？');">
                <?= Csrf::field() ?>
                <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">移除</button>
            </form>
            <?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if (!$isLeader && $myMembership['status'] === 'active'): ?>
<form method="post" action="?r=student/group/leave" onsubmit="return confirm('确认退出该小组？');">
    <?= Csrf::field() ?>
    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
    <button type="submit" class="btn btn-outline-danger">退出小组</button>
</form>
<?php endif; ?>

<?php if ($isLeader): ?>
<h2 class="h6 mt-4">待审批申请（<?= count($pendingMembers) ?>）</h2>
<?php if ($pendingMembers === []): ?>
<p class="text-muted">暂无待审批申请。</p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
    <thead><tr><th>学号</th><th>姓名</th><th>申请时间</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($pendingMembers as $pending): ?>
    <tr>
        <td><?= e($pending['student_no']) ?></td>
        <td><?= e($pending['name']) ?></td>
        <td><?= e($pending['created_at']) ?></td>
        <td class="d-flex gap-1">
            <form method="post" action="?r=student/group/approve">
                <?= Csrf::field() ?>
                <input type="hidden" name="member_id" value="<?= (int) $pending['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">同意</button>
            </form>
            <form method="post" action="?r=student/group/reject">
                <?= Csrf::field() ?>
                <input type="hidden" name="member_id" value="<?= (int) $pending['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">拒绝</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<div class="border rounded p-3 mt-4">
    <h3 class="h6">组长操作</h3>
    <form method="post" action="?r=student/group/rename" class="row g-2 align-items-end mb-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
        <div class="col-auto">
            <label class="form-label" for="name">修改组名</label>
            <input class="form-control" id="name" name="name" required maxlength="50" value="<?= e($group['name']) ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">保存</button>
        </div>
    </form>
    <form method="post" action="?r=student/group/disband"
          onsubmit="return confirm('确认解散该小组？已有作业提交的小组不能解散。');">
        <?= Csrf::field() ?>
        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
        <button type="submit" class="btn btn-outline-danger">解散小组</button>
    </form>
</div>
<?php endif; ?>

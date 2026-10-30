<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CourseRepository;
use App\Repositories\GroupRepository;
use App\Services\AuthService;
use App\Support\Session;
use App\Support\View;
use PDOException;

/**
 * 学生端课程小组：创建（创建者为组长）、申请加入（组长审批）、退出、
 * 组长的改名/转让/移除成员/解散。小组属于课程，一课一组。
 */
final class GroupController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly GroupRepository $groups,
        private readonly CourseRepository $courses,
    ) {
        parent::__construct($auth);
    }

    /** 小组管理页：组长审批/改名/转让/解散，成员查看/退出 */
    public function show(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $group = $this->findGroup();
        $course = $this->requireCourseAccess($user, (int) $group['course_id'], false);

        $membership = $this->groups->membershipInCourse((int) $group['course_id'], (int) $user['id']);
        if ($membership === null || (int) $membership['group_id'] !== (int) $group['id']) {
            $this->forbidden('您不是该小组成员。');
        }

        View::render('student/group', [
            'title' => '小组 — ' . $group['name'],
            'course' => $course,
            'group' => $group,
            'members' => $this->groups->members((int) $group['id'], 'active'),
            'pendingMembers' => $this->groups->members((int) $group['id'], 'pending'),
            'isLeader' => (int) $group['leader_student_id'] === (int) $user['id'],
            'myMembership' => $membership,
        ]);
    }

    /** 创建小组：创建者自动为组长 */
    public function create(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $courseId = (int) ($_POST['course_id'] ?? 0);
        $course = $this->requireCourseAccess($user, $courseId, true);
        if ($this->groups->membershipInCourse($courseId, (int) $user['id']) !== null) {
            Session::flash('error', '您已在本课程的小组中，不能重复建组。');
            redirect('?r=student/course&id=' . $courseId);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            Session::flash('error', '组名必填且不超过 50 字。');
            redirect('?r=student/course&id=' . $courseId);
        }
        try {
            $this->groups->create($courseId, $name, (int) $user['id']);
        } catch (PDOException) {
            Session::flash('error', "组名「{$name}」在本课程已被使用。");
            redirect('?r=student/course&id=' . $courseId);
        }

        Session::flash('success', "小组「{$name}」已创建，您是组长。");
        redirect('?r=student/course&id=' . $courseId);
    }

    /** 申请加入：需组长同意；申请时检查人数上限 */
    public function apply(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $group = $this->findGroup();
        $course = $this->requireCourseAccess($user, (int) $group['course_id'], true);
        if ($this->groups->membershipInCourse((int) $group['course_id'], (int) $user['id']) !== null) {
            Session::flash('error', '您已在本课程的小组中（或已有待审批申请）。');
            redirect('?r=student/course&id=' . $group['course_id']);
        }
        if (($msg = $this->checkMaxMembers($course, (int) $group['id'])) !== null) {
            Session::flash('error', $msg);
            redirect('?r=student/course&id=' . $group['course_id']);
        }

        try {
            $this->groups->addMember((int) $group['id'], (int) $user['id'], 'pending');
        } catch (PDOException) {
            Session::flash('error', '请勿重复申请。');
            redirect('?r=student/course&id=' . $group['course_id']);
        }

        Session::flash('success', "已申请加入「{$group['name']}」，待组长同意。");
        redirect('?r=student/course&id=' . $group['course_id']);
    }

    /** 撤销自己的待审批申请 */
    public function cancelApply(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $member = $this->findMember();
        $group = $this->groups->findById((int) $member['group_id']);
        if ((int) $member['student_id'] !== (int) $user['id'] || $member['status'] !== 'pending') {
            $this->forbidden('只能撤销自己的待审批申请。');
        }
        $this->groups->removeMember((int) $member['id']);

        Session::flash('success', '已撤销申请。');
        redirect('?r=student/course&id=' . $group['course_id']);
    }

    /** 组长同意申请：再次检查人数上限 */
    public function approve(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        [$member, $group] = $this->requireLeaderOfMember($user);
        if ($member['status'] !== 'pending') {
            Session::flash('error', '该申请已处理。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        $course = $this->requireCourseAccess($user, (int) $group['course_id'], true);
        if (($msg = $this->checkMaxMembers($course, (int) $group['id'])) !== null) {
            Session::flash('error', $msg);
            redirect('?r=student/group&id=' . $group['id']);
        }

        // 目标学生在同一课程不得已有其他组
        $existing = $this->groups->membershipInCourse((int) $group['course_id'], (int) $member['student_id']);
        if ($existing !== null && (int) $existing['id'] !== (int) $member['id']) {
            $this->groups->removeMember((int) $member['id']);
            Session::flash('error', '该学生已加入其他小组，本申请已自动作废。');
            redirect('?r=student/group&id=' . $group['id']);
        }

        $this->groups->setMemberStatus((int) $member['id'], 'active');
        Session::flash('success', '已同意加入申请。');
        redirect('?r=student/group&id=' . $group['id']);
    }

    /** 组长拒绝申请（删除申请行，学生可再次申请） */
    public function reject(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        [$member, $group] = $this->requireLeaderOfMember($user);
        if ($member['status'] !== 'pending') {
            Session::flash('error', '该申请已处理。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        $this->groups->removeMember((int) $member['id']);

        Session::flash('success', '已拒绝该申请。');
        redirect('?r=student/group&id=' . $group['id']);
    }

    /** 普通成员退出；组长不能退出（只能转让或解散） */
    public function leave(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $group = $this->findGroup();
        $membership = $this->groups->membershipInCourse((int) $group['course_id'], (int) $user['id']);
        if ($membership === null || (int) $membership['group_id'] !== (int) $group['id'] || $membership['status'] !== 'active') {
            $this->forbidden('您不是该小组成员。');
        }
        if ((int) $group['leader_student_id'] === (int) $user['id']) {
            Session::flash('error', '组长不能退出，请先转让组长或解散小组。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        $this->groups->removeMember((int) $membership['id']);

        Session::flash('success', "已退出小组「{$group['name']}」。");
        redirect('?r=student/course&id=' . $group['course_id']);
    }

    /** 组长修改组名 */
    public function rename(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $group = $this->requireLeader($user);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            Session::flash('error', '组名必填且不超过 50 字。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        try {
            $this->groups->rename((int) $group['id'], $name);
        } catch (PDOException) {
            Session::flash('error', "组名「{$name}」在本课程已被使用。");
            redirect('?r=student/group&id=' . $group['id']);
        }

        Session::flash('success', '组名已修改。');
        redirect('?r=student/group&id=' . $group['id']);
    }

    /** 组长转让：目标须为 active 成员 */
    public function transfer(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $group = $this->requireLeader($user);
        $target = $this->findMember();
        if ((int) $target['group_id'] !== (int) $group['id'] || $target['status'] !== 'active') {
            Session::flash('error', '只能转让给本组成员。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        $this->groups->transferLeader((int) $group['id'], (int) $target['student_id']);

        Session::flash('success', '组长已转让。');
        redirect('?r=student/group&id=' . $group['id']);
    }

    /** 组长移除组员（不能移除自己） */
    public function removeMember(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        [$member, $group] = $this->requireLeaderOfMember($user);
        if ((int) $member['student_id'] === (int) $user['id']) {
            Session::flash('error', '不能移除自己，请转让组长或解散小组。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        $this->groups->removeMember((int) $member['id']);

        Session::flash('success', '已移除该成员。');
        redirect('?r=student/group&id=' . $group['id']);
    }

    /** 组长解散小组：已有任何作业提交则禁止 */
    public function disband(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $group = $this->requireLeader($user);
        if ($this->groups->hasSubmissions((int) $group['id'])) {
            Session::flash('error', '该小组已有作业提交记录，不能解散。');
            redirect('?r=student/group&id=' . $group['id']);
        }
        foreach (array_merge(
            $this->groups->members((int) $group['id'], 'active'),
            $this->groups->members((int) $group['id'], 'pending')
        ) as $member) {
            $this->groups->removeMember((int) $member['id']);
        }
        $this->groups->delete((int) $group['id']);

        Session::flash('success', "小组「{$group['name']}」已解散。");
        redirect('?r=student/course&id=' . $group['course_id']);
    }

    // ---- 内部工具 ----

    private function findGroup(): array
    {
        $group = $this->groups->findById((int) (($_POST['group_id'] ?? $_GET['id']) ?? 0));
        if ($group === null) {
            $this->notFound('小组不存在。');
        }
        return $group;
    }

    private function findMember(): array
    {
        $member = $this->groups->findMember((int) ($_POST['member_id'] ?? 0));
        if ($member === null) {
            $this->notFound('成员记录不存在。');
        }
        return $member;
    }

    /** 要求当前用户是指定小组的组长 */
    private function requireLeader(array $user): array
    {
        $group = $this->findGroup();
        $course = $this->courses->findById((int) $group['course_id']);
        if ($course === null || $course['status'] !== 'active') {
            Session::flash('error', '课程已归档，小组不可再变更。');
            redirect('?r=student/home');
        }
        if ((int) $group['leader_student_id'] !== (int) $user['id']) {
            $this->forbidden('只有组长能执行该操作。');
        }
        return $group;
    }

    /** 要求当前用户是 member 所在组的组长，返回 [member, group] */
    private function requireLeaderOfMember(array $user): array
    {
        $member = $this->findMember();
        $group = $this->groups->findById((int) $member['group_id']);
        if ($group === null) {
            $this->notFound('小组不存在。');
        }
        $course = $this->courses->findById((int) $group['course_id']);
        if ($course === null || $course['status'] !== 'active') {
            Session::flash('error', '课程已归档，小组不可再变更。');
            redirect('?r=student/home');
        }
        if ((int) $group['leader_student_id'] !== (int) $user['id']) {
            $this->forbidden('只有组长能执行该操作。');
        }
        return [$member, $group];
    }

    /** 课程权限复检 + 可选的要求课程未归档 */
    private function requireCourseAccess(array $user, int $courseId, bool $requireActive): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null || !$this->courses->studentCanAccess($courseId, $user['student_no'], $user['name'])) {
            $this->forbidden('您没有访问该课程的权限。');
        }
        if ($requireActive && $course['status'] !== 'active') {
            Session::flash('error', '课程已归档，小组不可再变更。');
            redirect('?r=student/course&id=' . $courseId);
        }
        return $course;
    }

    /** 人数上限检查（加入时）：返回拒绝原因或 null */
    private function checkMaxMembers(array $course, int $groupId): ?string
    {
        $max = $course['group_max_members'] !== null ? (int) $course['group_max_members'] : null;
        if ($max !== null && $this->groups->countActiveMembers($groupId) >= $max) {
            return "该小组人数已达上限（{$max} 人）。";
        }
        return null;
    }
}

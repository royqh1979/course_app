<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\GroupRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\SubmissionRepository;
use App\Services\AuthService;
use App\Services\FileStorage;
use App\Support\Database;
use App\Support\Session;
use App\Support\View;
use RuntimeException;

final class StudentController extends BaseController
{
    private readonly FileStorage $storage;

    public function __construct(
        AuthService $auth,
        private readonly CourseRepository $courses,
        private readonly HandoutRepository $handouts,
        private readonly AssignmentRepository $assignments,
        private readonly AttachmentRepository $attachments,
        private readonly SubmissionRepository $submissions,
        private readonly FileRepository $files,
        private readonly GroupRepository $groups,
        ?FileStorage $storage = null,
    ) {
        parent::__construct($auth);
        $this->storage = $storage ?? FileStorage::fromConfig();
    }

    public function home(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        View::render('student/home', [
            'title' => '我的课程',
            'courses' => $this->courses->listActiveByStudent($user['student_no'], $user['name']),
        ]);
    }

    /** 学生课程旧入口：跳转到讲义页（各区块已拆分为独立页面） */
    public function course(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $course = $this->requireCourseAccess($user);
        redirect('?r=student/course/handouts&id=' . $course['id']);
    }

    public function handouts(): void
    {
        $data = $this->coursePageData('handouts');
        View::render('student/course_handouts', $data + [
            'handouts' => $this->handouts->listByCourse((int) $data['course']['id'], true),
        ]);
    }

    public function assignments(): void
    {
        $data = $this->coursePageData('assignments');
        View::render('student/course_assignments', $data + [
            'assignments' => $this->assignments->listByCourse((int) $data['course']['id'], true),
        ]);
    }

    /** 课程小组页：我的申请/我的小组、创建小组、浏览并申请加入 */
    public function groups(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $course = $this->requireCourseAccess($user);
        View::render('student/course_groups', [
            'title' => $course['name'] . ' · 课程小组',
            'course' => $course,
            'tab' => 'groups',
            'groups' => $this->groups->listByCourse((int) $course['id']),
            'myMembership' => $this->groups->membershipInCourse((int) $course['id'], (int) $user['id']),
        ]);
    }

    /** 课程子页面公共数据（讲义/作业页共用；小组页需用户对象单独取） */
    private function coursePageData(string $tab): array
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $course = $this->requireCourseAccess($user);
        return [
            'title' => $course['name'],
            'course' => $course,
            'tab' => $tab,
        ];
    }

    public function assignment(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $assignment = $this->assignments->findById((int) ($_GET['id'] ?? 0));
        if ($assignment === null || !in_array($assignment['status'], ['published', 'closed'], true)) {
            $this->notFound('作业不存在或未发布。');
        }
        $course = $this->courses->findById((int) $assignment['course_id']);
        if ($course === null || !$this->courses->studentCanAccess((int) $course['id'], $user['student_no'], $user['name'])) {
            $this->forbidden('您没有访问该课程的权限。');
        }

        $myGroup = null;
        if ((bool) $assignment['is_group']) {
            $myGroup = $this->groups->activeGroupOfStudent((int) $course['id'], (int) $user['id']);
            $submission = $myGroup !== null
                ? $this->submissions->findByAssignmentAndGroup((int) $assignment['id'], (int) $myGroup['id'])
                : null;
            $block = $this->groupSubmitBlock($course, $assignment, $myGroup, $user, $submission);
        } else {
            $submission = $this->submissions->findByAssignmentAndStudent((int) $assignment['id'], (int) $user['id']);
            $block = $this->submitBlockReason($course, $assignment, $submission);
        }
        View::render('student/assignment', [
            'title' => $assignment['title'],
            'course' => $course,
            'assignment' => $assignment,
            'attachments' => $this->attachments->listByAssignment((int) $assignment['id']),
            'submission' => $submission,
            'submitBlock' => $block,
            'myGroup' => $myGroup,
        ]);
    }

    /**
     * 提交/重新提交作业。
     * 规则链：课程权限 → 课程未归档 → 作业已发布未关闭 → 到开始时间 → 截止判断
     * （默认允许补交：截止后仍可提交并自动判为迟交；教师关闭补交后截止即拒绝）。
     */
    public function submit(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $this->requireCsrf();

        $assignment = $this->assignments->findById((int) ($_POST['assignment_id'] ?? 0));
        if ($assignment === null || !in_array($assignment['status'], ['published', 'closed'], true)) {
            $this->notFound('作业不存在或未发布。');
        }
        $course = $this->courses->findById((int) $assignment['course_id']);
        if ($course === null || !$this->courses->studentCanAccess((int) $course['id'], $user['student_no'], $user['name'])) {
            $this->forbidden('您没有访问该课程的权限。');
        }

        $isGroup = (bool) $assignment['is_group'];
        $myGroup = null;
        if ($isGroup) {
            $myGroup = $this->groups->activeGroupOfStudent((int) $course['id'], (int) $user['id']);
            $existing = $myGroup !== null
                ? $this->submissions->findByAssignmentAndGroup((int) $assignment['id'], (int) $myGroup['id'])
                : null;
            $block = $this->groupSubmitBlock($course, $assignment, $myGroup, $user, $existing);
        } else {
            $existing = $this->submissions->findByAssignmentAndStudent((int) $assignment['id'], (int) $user['id']);
            $block = $this->submitBlockReason($course, $assignment, $existing);
        }
        if ($block !== null) {
            Session::flash('error', $block);
            redirect('?r=student/assignment&id=' . $assignment['id']);
        }

        $extensions = array_filter(array_map('trim', explode(',', (string) $assignment['allowed_extensions'])));
        try {
            $result = $this->storage->transactional(function () use ($assignment, $course, $user, $existing, $extensions, $myGroup) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'courses/' . $course['id'] . '/assignments/' . $assignment['id'] . '/submissions',
                    array_values($extensions),
                    (int) $assignment['max_file_size']
                );
                // 先保存新文件并更新数据库，提交成功后旧文件引用归零才清理
                $oldPath = Database::transaction(function () use ($meta, $assignment, $user, $existing, $myGroup) {
                    $fileId = $this->files->create($meta);
                    if ($existing === null) {
                        $this->submissions->insert(
                            (int) $assignment['id'],
                            (int) $user['id'],
                            $fileId,
                            $myGroup !== null ? (int) $myGroup['id'] : null
                        );
                        return null;
                    }
                    $this->submissions->replace((int) $existing['id'], $fileId);
                    return $this->files->deleteIfUnused((int) $existing['file_id']);
                });
                if ($oldPath !== null) {
                    $this->storage->delete($oldPath);
                }
                return ['resubmitted' => $existing !== null, 'original_name' => $meta['original_name']];
            });
        } catch (RuntimeException $e) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, $e->getMessage());
            }
            Session::flash('error', $e->getMessage());
            redirect('?r=student/assignment&id=' . $assignment['id']);
        }

        $message = $result['resubmitted']
            ? "《{$result['original_name']}》上传成功，已替换旧提交。"
            : "《{$result['original_name']}》上传成功。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message, '?r=student/assignment&id=' . $assignment['id']);
        }
        Session::flash('success', $message);
        redirect('?r=student/assignment&id=' . $assignment['id']);
    }

    /** 小组作业不可提交的原因；可提交返回 null */
    private function groupSubmitBlock(array $course, array $assignment, ?array $group, array $user, ?array $submission): ?string
    {
        if ($submission !== null && $submission['graded_at'] !== null) {
            return '教师已批改该作业，不能再修改提交。';
        }
        if ($group === null) {
            return '小组作业需要先创建或加入小组。';
        }
        if ((int) $group['leader_student_id'] !== (int) $user['id']) {
            return '只有组长能提交小组作业。';
        }
        $common = $this->submitBlockReason($course, $assignment, null);
        if ($common !== null) {
            return $common;
        }
        // 人数下限：提交作业时检查
        $min = $course['group_min_members'] !== null ? (int) $course['group_min_members'] : null;
        if ($min !== null && $this->groups->countActiveMembers((int) $group['id']) < $min) {
            return "小组成员不足 {$min} 人，暂不能提交。";
        }
        return null;
    }

    /** 不可提交的原因；可提交返回 null。已批改的提交学生不能再修改 */
    private function submitBlockReason(array $course, array $assignment, ?array $submission): ?string
    {
        if ($submission !== null && $submission['graded_at'] !== null) {
            return '教师已批改该作业，不能再修改提交。';
        }
        if ($course['status'] !== 'active') {
            return '课程已归档，不能继续提交作业。';
        }
        if ($assignment['status'] === 'closed') {
            return '作业已关闭，不能再提交。';
        }
        if ($assignment['status'] !== 'published') {
            return '作业未发布。';
        }
        $now = date('Y-m-d H:i:s');
        if ($assignment['start_at'] !== null && $now < $assignment['start_at']) {
            return '作业尚未到开始时间（' . $assignment['start_at'] . '）。';
        }
        if ($assignment['deadline_at'] !== null && $now > $assignment['deadline_at'] && !(bool) $assignment['allow_late']) {
            return '已超过截止时间（' . $assignment['deadline_at'] . '），该作业不允许补交。';
        }
        return null;
    }

    /** 学生课程权限复检：课程存在、未归档、学号+姓名实时匹配名单 */
    private function requireCourseAccess(array $user): array
    {
        $id = (int) ($_GET['id'] ?? 0);
        $course = $this->courses->findById($id);
        if ($course === null || !$this->courses->studentCanAccess($id, $user['student_no'], $user['name'])) {
            $this->forbidden('您没有访问该课程的权限。');
        }
        return $course;
    }
}

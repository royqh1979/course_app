<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
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

    public function course(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $course = $this->requireCourseAccess($user);

        View::render('student/course', [
            'title' => $course['name'],
            'course' => $course,
            'handouts' => $this->handouts->listByCourse((int) $course['id'], true),
            'assignments' => $this->assignments->listByCourse((int) $course['id'], true),
        ]);
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

        $submission = $this->submissions->findByAssignmentAndStudent((int) $assignment['id'], (int) $user['id']);
        View::render('student/assignment', [
            'title' => $assignment['title'],
            'course' => $course,
            'assignment' => $assignment,
            'attachments' => $this->attachments->listByAssignment((int) $assignment['id']),
            'submission' => $submission,
            'submitBlock' => $this->submitBlockReason($course, $assignment, $submission),
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

        $existing = $this->submissions->findByAssignmentAndStudent((int) $assignment['id'], (int) $user['id']);
        $block = $this->submitBlockReason($course, $assignment, $existing);
        if ($block !== null) {
            Session::flash('error', $block);
            redirect('?r=student/assignment&id=' . $assignment['id']);
        }

        $extensions = array_filter(array_map('trim', explode(',', (string) $assignment['allowed_extensions'])));
        try {
            $resubmitted = $this->storage->transactional(function () use ($assignment, $course, $user, $existing, $extensions) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'courses/' . $course['id'] . '/assignments/' . $assignment['id'] . '/submissions',
                    array_values($extensions),
                    (int) $assignment['max_file_size']
                );
                // 先保存新文件并更新数据库，提交成功后旧文件引用归零才清理
                $oldPath = Database::transaction(function () use ($meta, $assignment, $user, $existing) {
                    $fileId = $this->files->create($meta);
                    if ($existing === null) {
                        $this->submissions->insert((int) $assignment['id'], (int) $user['id'], $fileId);
                        return null;
                    }
                    $this->submissions->replace((int) $existing['id'], $fileId);
                    return $this->files->deleteIfUnused((int) $existing['file_id']);
                });
                if ($oldPath !== null) {
                    $this->storage->delete($oldPath);
                }
                return $existing !== null;
            });
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            redirect('?r=student/assignment&id=' . $assignment['id']);
        }

        Session::flash('success', $resubmitted ? '已重新提交，旧文件已被替换。' : '提交成功。');
        redirect('?r=student/assignment&id=' . $assignment['id']);
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

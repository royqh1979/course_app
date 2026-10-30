<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\LogRepository;
use App\Repositories\SubmissionRepository;
use App\Services\AuthService;
use App\Services\FileStorage;
use App\Support\App;
use App\Support\Database;
use App\Support\Pagination;
use App\Support\Session;
use App\Support\View;
use RuntimeException;

/**
 * 教师端作业管理：新建/修改/发布/关闭、附件上传与删除。
 * 作业在任何状态、任何时间都可编辑（含已过的截止时间），改后实时生效。
 */
final class AssignmentController extends BaseController
{
    public const SUBMIT_EXTENSIONS = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
    private const ATTACHMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

    private readonly FileStorage $storage;

    public function __construct(
        AuthService $auth,
        private readonly AssignmentRepository $assignments,
        private readonly CourseRepository $courses,
        private readonly AttachmentRepository $attachments,
        private readonly FileRepository $files,
        private readonly LogRepository $logs,
        private readonly SubmissionRepository $submissions,
        ?FileStorage $storage = null,
    ) {
        parent::__construct($auth);
        $this->storage = $storage ?? FileStorage::fromConfig();
    }

    public function create(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $course = $this->courses->findById((int) ($_GET['course_id'] ?? 0));
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        View::render('admin/assignments/form', [
            'title' => '新建作业',
            'course' => $course,
            'assignment' => null,
            'attachments' => [],
            'action' => '?r=admin/assignments/store',
            'submitExtensions' => self::SUBMIT_EXTENSIONS,
        ]);
    }

    public function store(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $course = $this->courses->findById((int) ($_POST['course_id'] ?? 0));
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        if ($course['status'] !== 'active') {
            Session::flash('error', '已归档课程不能再发布作业。');
            redirect('?r=admin/courses/show&id=' . $course['id']);
        }

        $data = $this->readForm();
        if ($data === null) {
            redirect('?r=admin/assignments/create&course_id=' . $course['id']);
        }
        $id = $this->assignments->create((int) $course['id'], $data);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.create', 'assignment', (string) $id, $data['title'], $this->ip());

        Session::flash('success', '作业已创建（草稿），可继续添加附件，发布后学生才可见。');
        redirect('?r=admin/assignments/edit&id=' . $id);
    }

    public function edit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $assignment = $this->findAssignment();
        $course = $this->courses->findById((int) $assignment['course_id']);
        View::render('admin/assignments/form', [
            'title' => '编辑作业',
            'course' => $course,
            'assignment' => $assignment,
            'attachments' => $this->attachments->listByAssignment((int) $assignment['id']),
            'action' => '?r=admin/assignments/update',
            'submitExtensions' => self::SUBMIT_EXTENSIONS,
        ]);
    }

    public function update(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findAssignment();
        $data = $this->readForm();
        if ($data === null) {
            redirect('?r=admin/assignments/edit&id=' . $assignment['id']);
        }
        $this->assignments->update((int) $assignment['id'], $data);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.update', 'assignment', (string) $assignment['id'], $data['title'], $this->ip());

        Session::flash('success', '作业已保存，修改（含截止时间）立即生效。');
        redirect('?r=admin/courses/show&id=' . $assignment['course_id']);
    }

    public function publish(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findAssignment();
        $course = $this->courses->findById((int) $assignment['course_id']);
        if ($course === null || $course['status'] !== 'active') {
            Session::flash('error', '已归档课程不能再发布作业。');
            redirect('?r=admin/courses/show&id=' . $assignment['course_id']);
        }
        $this->assignments->setStatus((int) $assignment['id'], 'published');
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.publish', 'assignment', (string) $assignment['id'], $assignment['title'], $this->ip());

        Session::flash('success', "作业《{$assignment['title']}》已发布，学生可见并可提交。");
        redirect('?r=admin/courses/show&id=' . $assignment['course_id']);
    }

    public function close(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findAssignment();
        $this->assignments->setStatus((int) $assignment['id'], 'closed');
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.close', 'assignment', (string) $assignment['id'], $assignment['title'], $this->ip());

        Session::flash('success', "作业《{$assignment['title']}》已关闭，学生不能再提交（可重新发布恢复）。");
        redirect('?r=admin/courses/show&id=' . $assignment['course_id']);
    }

    /** 提交列表与批改页 */
    public function submissions(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $assignment = $this->findAssignment();
        $course = $this->courses->findById((int) $assignment['course_id']);
        $pagination = Pagination::fromRequest('page', $this->submissions->countByAssignment((int) $assignment['id']));
        View::render('admin/assignments/submissions', [
            'title' => '提交批改 — ' . $assignment['title'],
            'course' => $course,
            'assignment' => $assignment,
            'submissions' => $this->submissions->listByAssignment((int) $assignment['id'], $pagination),
            'pagination' => $pagination,
        ]);
    }

    /**
     * 批改提交：分数（0–100，可选）与评语（可选）至少填一项。
     * 教师可随时修改批改结果；批改后学生不能再重交（锁定只针对学生）。
     */
    public function grade(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $submission = $this->submissions->findById((int) ($_POST['id'] ?? 0));
        if ($submission === null) {
            $this->notFound('提交记录不存在。');
        }
        $assignmentId = (int) $submission['assignment_id'];

        $scoreRaw = trim((string) ($_POST['score'] ?? ''));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $score = null;
        if ($scoreRaw !== '') {
            if (!ctype_digit($scoreRaw) || (int) $scoreRaw > 100) {
                Session::flash('error', '分数须为 0–100 的整数。');
                redirect('?r=admin/assignments/submissions&id=' . $assignmentId);
            }
            $score = (int) $scoreRaw;
        }
        if ($score === null && $comment === '') {
            Session::flash('error', '分数与评语至少填写一项。');
            redirect('?r=admin/assignments/submissions&id=' . $assignmentId);
        }
        if (mb_strlen($comment) > 500) {
            Session::flash('error', '评语不超过 500 字。');
            redirect('?r=admin/assignments/submissions&id=' . $assignmentId);
        }

        $regraded = $submission['graded_at'] !== null;
        $this->submissions->grade((int) $submission['id'], $score, $comment === '' ? null : $comment);
        $this->logs->addAuditLog(
            'admin',
            (int) $admin['id'],
            $regraded ? 'submission.regrade' : 'submission.grade',
            'assignment',
            (string) $assignmentId,
            "提交 {$submission['id']}：" . ($score !== null ? "{$score} 分" : '仅评语'),
            $this->ip()
        );

        Session::flash('success', $regraded ? '批改结果已更新。' : '批改完成，学生不能再修改该提交。');
        redirect('?r=admin/assignments/submissions&id=' . $assignmentId);
    }

    public function addAttachment(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findAssignment();
        try {
            $originalName = $this->storage->transactional(function () use ($assignment) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'courses/' . $assignment['course_id'] . '/assignments/' . $assignment['id'] . '/attachments',
                    self::ATTACHMENT_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                Database::transaction(function () use ($meta, $assignment) {
                    $fileId = $this->files->create($meta);
                    $this->attachments->add((int) $assignment['id'], $fileId);
                });
                return $meta['original_name'];
            });
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/assignments/edit&id=' . $assignment['id']);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.attachment_add', 'assignment', (string) $assignment['id'], $originalName, $this->ip());

        Session::flash('success', '附件已添加。');
        redirect('?r=admin/assignments/edit&id=' . $assignment['id']);
    }

    public function deleteAttachment(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $attachment = $this->attachments->findById((int) ($_POST['id'] ?? 0));
        if ($attachment === null) {
            $this->notFound('附件不存在。');
        }
        $this->storage->transactional(function () use ($attachment) {
            // 先解除引用；files 行与磁盘文件仅当引用归零才删除
            $oldPath = Database::transaction(function () use ($attachment) {
                $this->attachments->remove((int) $attachment['id']);
                return $this->files->deleteIfUnused((int) $attachment['file_id']);
            });
            if ($oldPath !== null) {
                $this->storage->delete($oldPath);
            }
        });
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.attachment_delete', 'assignment', (string) $attachment['assignment_id'], $attachment['original_name'], $this->ip());

        Session::flash('success', '附件已删除。');
        redirect('?r=admin/assignments/edit&id=' . $attachment['assignment_id']);
    }

    /**
     * 读取并校验作业表单。失败写 flash 并返回 null。
     *
     * @return array{title: string, requirements: ?string, start_at: ?string, deadline_at: ?string,
     *               allowed_extensions: string, max_file_size: int, allow_late: bool}|null
     */
    private function readForm(): ?array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '作业标题必填且不超过 200 字。');
            return null;
        }
        $startAt = $this->parseDatetime($_POST['start_at'] ?? null);
        $deadlineAt = $this->parseDatetime($_POST['deadline_at'] ?? null);
        if ($startAt === false || $deadlineAt === false) {
            Session::flash('error', '开始或截止时间格式不正确。');
            return null;
        }
        if ($startAt !== null && $deadlineAt !== null && $startAt >= $deadlineAt) {
            Session::flash('error', '截止时间必须晚于开始时间。');
            return null;
        }

        $extensions = array_values(array_intersect(
            self::SUBMIT_EXTENSIONS,
            array_map('strval', (array) ($_POST['allowed_extensions'] ?? []))
        ));
        if ($extensions === []) {
            Session::flash('error', '请至少选择一种允许提交的文件类型。');
            return null;
        }

        $maxMb = (int) ($_POST['max_file_size_mb'] ?? 20);
        if ($maxMb < 1 || $maxMb > 200) {
            Session::flash('error', '单文件大小上限需在 1–200 MB 之间。');
            return null;
        }

        return [
            'title' => $title,
            'requirements' => trim((string) ($_POST['requirements'] ?? '')),
            'start_at' => $startAt,
            'deadline_at' => $deadlineAt,
            'allowed_extensions' => implode(',', $extensions),
            'max_file_size' => $maxMb * 1024 * 1024,
            'allow_late' => ($_POST['allow_late'] ?? '') === '1',
        ];
    }

    /** datetime-local 输入转 MySQL DATETIME；空为 null；非法返回 false */
    private function parseDatetime(mixed $value): string|null|false
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $value);
        return $dt === false ? false : $dt->format('Y-m-d H:i:s');
    }

    private function findAssignment(): array
    {
        $assignment = $this->assignments->findById((int) (($_POST['id'] ?? $_GET['id']) ?? 0));
        if ($assignment === null) {
            $this->notFound('作业不存在。');
        }
        return $assignment;
    }

    private function teacherMaxSize(): int
    {
        return (int) App::config('upload.teacher_max_size', 50 * 1024 * 1024);
    }
}

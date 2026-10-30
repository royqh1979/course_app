<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\SubmissionRepository;
use App\Services\AuthService;
use App\Services\FileStorage;

/**
 * 私有文件统一下载：登录 → 账号未归档（AuthService 保证）→ 学生实时课程权限
 * → 文件与课程/讲义/作业归属 → 按 stored_path 定位 → 以原始文件名输出。
 */
final class FileController extends BaseController
{
    private readonly FileStorage $storage;

    public function __construct(
        AuthService $auth,
        private readonly HandoutRepository $handouts,
        private readonly AssignmentRepository $assignments,
        private readonly AttachmentRepository $attachments,
        private readonly SubmissionRepository $submissions,
        private readonly CourseRepository $courses,
        ?FileStorage $storage = null,
    ) {
        parent::__construct($auth);
        $this->storage = $storage ?? FileStorage::fromConfig();
    }

    /** 讲义下载：教师任意；学生需讲义已发布且有课程权限 */
    public function handout(): void
    {
        $user = $this->requireAnyUser();
        $handout = $this->handouts->findById((int) ($_GET['id'] ?? 0));
        if ($handout === null) {
            $this->notFound('文件不存在。');
        }
        if ($user['type'] === AuthService::TYPE_STUDENT) {
            if ($handout['status'] !== 'published'
                || !$this->courses->studentCanAccess((int) $handout['course_id'], $user['student_no'], $user['name'])) {
                $this->forbidden('您没有下载该文件的权限。');
            }
        }
        $this->storage->output((string) $handout['stored_path'], (string) $handout['original_name'], (string) $handout['mime_type']);
    }

    /** 作业附件下载：教师任意；学生需作业已发布/已关闭且有课程权限 */
    public function attachment(): void
    {
        $user = $this->requireAnyUser();
        $attachment = $this->attachments->findById((int) ($_GET['id'] ?? 0));
        if ($attachment === null) {
            $this->notFound('文件不存在。');
        }
        if ($user['type'] === AuthService::TYPE_STUDENT) {
            $assignment = $this->assignments->findById((int) $attachment['assignment_id']);
            if ($assignment === null
                || !in_array($assignment['status'], ['published', 'closed'], true)
                || !$this->courses->studentCanAccess((int) $assignment['course_id'], $user['student_no'], $user['name'])) {
                $this->forbidden('您没有下载该文件的权限。');
            }
        }
        $this->storage->output((string) $attachment['stored_path'], (string) $attachment['original_name'], (string) $attachment['mime_type']);
    }

    /** 提交文件下载：教师任意；学生仅限本人提交且仍有课程权限 */
    public function submission(): void
    {
        $user = $this->requireAnyUser();
        $submission = $this->submissions->findById((int) ($_GET['id'] ?? 0));
        if ($submission === null) {
            $this->notFound('文件不存在。');
        }
        if ($user['type'] === AuthService::TYPE_STUDENT) {
            $assignment = $this->assignments->findById((int) $submission['assignment_id']);
            if ((int) $submission['student_id'] !== (int) $user['id']
                || $assignment === null
                || !$this->courses->studentCanAccess((int) $assignment['course_id'], $user['student_no'], $user['name'])) {
                $this->forbidden('您没有下载该文件的权限。');
            }
        }
        $this->storage->output((string) $submission['stored_path'], (string) $submission['original_name'], (string) $submission['mime_type']);
    }

    /** 要求已登录（角色不限），返回当前用户 */
    private function requireAnyUser(): array
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            redirect('?r=login');
        }
        return $user;
    }
}

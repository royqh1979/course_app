<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LogRepository;
use App\Services\AuthService;
use App\Services\FileStorage;
use App\Support\App;
use App\Support\Database;
use App\Support\Session;
use App\Support\View;
use RuntimeException;

/**
 * 教师端讲义管理：上传（草稿）、修改标题说明、替换文件、发布/下线、删除。
 */
final class HandoutController extends BaseController
{
    private const ALLOWED_EXTENSIONS = ['doc', 'docx', 'ppt', 'pptx', 'pdf'];

    private readonly FileStorage $storage;

    public function __construct(
        AuthService $auth,
        private readonly HandoutRepository $handouts,
        private readonly CourseRepository $courses,
        private readonly FileRepository $files,
        private readonly LogRepository $logs,
        ?FileStorage $storage = null,
    ) {
        parent::__construct($auth);
        $this->storage = $storage ?? FileStorage::fromConfig();
    }

    public function store(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $course = $this->requireActiveCourse((int) ($_POST['course_id'] ?? 0));
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, '讲义标题必填且不超过 200 字。');
            }
            Session::flash('error', '讲义标题必填且不超过 200 字。');
            redirect('?r=admin/courses/handouts&id=' . $course['id']);
        }

        $description = trim((string) ($_POST['description'] ?? ''));
        try {
            $result = $this->storage->transactional(function () use ($course, $title, $description) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'courses/' . $course['id'] . '/handouts',
                    self::ALLOWED_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                $id = Database::transaction(function () use ($meta, $course, $title, $description) {
                    $fileId = $this->files->create($meta);
                    return $this->handouts->create((int) $course['id'], $title, $description, $fileId);
                });
                return ['id' => $id, 'original_name' => $meta['original_name']];
            });
        } catch (RuntimeException $e) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, $e->getMessage());
            }
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/courses/handouts&id=' . $course['id']);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.upload', 'handout', (string) $result['id'], "课程 {$course['id']}：{$title}", $this->ip());

        $message = "《{$result['original_name']}》上传成功（草稿），发布后学生才可见。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message, '?r=admin/courses/handouts&id=' . $course['id']);
        }
        Session::flash('success', $message);
        redirect('?r=admin/courses/handouts&id=' . $course['id']);
    }

    public function edit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $handout = $this->findHandout();
        View::render('admin/handouts/edit', [
            'title' => '编辑讲义',
            'handout' => $handout,
        ]);
    }

    public function update(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findHandout();
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '讲义标题必填且不超过 200 字。');
            redirect('?r=admin/handouts/edit&id=' . $handout['id']);
        }
        $this->handouts->update((int) $handout['id'], $title, trim((string) ($_POST['description'] ?? '')));
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.update', 'handout', (string) $handout['id'], $title, $this->ip());

        Session::flash('success', '讲义已保存。');
        redirect('?r=admin/courses/handouts&id=' . $handout['course_id']);
    }

    /** 替换讲义文件：先保存新文件并更新数据库，成功后再清理旧文件 */
    public function replace(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findHandout();
        try {
            $originalName = $this->storage->transactional(function () use ($handout) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'courses/' . $handout['course_id'] . '/handouts',
                    self::ALLOWED_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                // 数据库先指向新文件；旧文件引用归零才删行，磁盘文件在提交后清理
                $oldPath = Database::transaction(function () use ($meta, $handout) {
                    $newFileId = $this->files->create($meta);
                    $this->handouts->replaceFile((int) $handout['id'], $newFileId);
                    return $this->files->deleteIfUnused((int) $handout['file_id']);
                });
                if ($oldPath !== null) {
                    $this->storage->delete($oldPath);
                }
                return $meta['original_name'];
            });
        } catch (RuntimeException $e) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, $e->getMessage());
            }
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/handouts/edit&id=' . $handout['id']);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.replace_file', 'handout', (string) $handout['id'], $originalName, $this->ip());

        $message = "讲义文件已替换为《{$originalName}》。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message, '?r=admin/courses/handouts&id=' . $handout['course_id']);
        }
        Session::flash('success', $message);
        redirect('?r=admin/courses/handouts&id=' . $handout['course_id']);
    }

    public function publish(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findHandout();
        $course = $this->courses->findById((int) $handout['course_id']);
        if ($course === null || $course['status'] !== 'active') {
            Session::flash('error', '已归档课程不能再发布讲义。');
            redirect('?r=admin/courses/handouts&id=' . $handout['course_id']);
        }
        $this->handouts->setStatus((int) $handout['id'], 'published');
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.publish', 'handout', (string) $handout['id'], $handout['title'], $this->ip());

        $message = "讲义《{$handout['title']}》已发布，学生立即可见。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message);
        }
        Session::flash('success', $message);
        redirect('?r=admin/courses/handouts&id=' . $handout['course_id']);
    }

    public function offline(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findHandout();
        $this->handouts->setStatus((int) $handout['id'], 'offline');
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.offline', 'handout', (string) $handout['id'], $handout['title'], $this->ip());

        $message = "讲义《{$handout['title']}》已下线，学生端不再显示。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message);
        }
        Session::flash('success', $message);
        redirect('?r=admin/courses/handouts&id=' . $handout['course_id']);
    }

    /** 删除讲义（任意状态，含已发布）；文件仅当不再被任何引用时删除 */
    public function delete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findHandout();
        $this->storage->transactional(function () use ($handout) {
            $oldPath = Database::transaction(function () use ($handout) {
                $this->handouts->delete((int) $handout['id']);
                return $this->files->deleteIfUnused((int) $handout['file_id']);
            });
            if ($oldPath !== null) {
                $this->storage->delete($oldPath);
            }
        });
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.delete', 'handout', (string) $handout['id'], $handout['title'], $this->ip());

        $message = "讲义《{$handout['title']}》已删除。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message);
        }
        Session::flash('success', $message);
        redirect('?r=admin/courses/handouts&id=' . $handout['course_id']);
    }

    private function findHandout(): array
    {
        $handout = $this->handouts->findById((int) (($_POST['id'] ?? $_GET['id']) ?? 0));
        if ($handout === null) {
            $this->notFound('讲义不存在。');
        }
        return $handout;
    }

    private function requireActiveCourse(int $courseId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        if ($course['status'] !== 'active') {
            Session::flash('error', '已归档课程不能再上传讲义。');
            redirect('?r=admin/courses/handouts&id=' . $courseId);
        }
        return $course;
    }

    private function teacherMaxSize(): int
    {
        return (int) App::config('upload.teacher_max_size', 50 * 1024 * 1024);
    }
}

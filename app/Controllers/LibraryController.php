<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryAssignmentRepository;
use App\Repositories\LibraryHandoutRepository;
use App\Repositories\LogRepository;
use App\Services\AuthService;
use App\Services\FileStorage;
use App\Support\App;
use App\Support\Database;
use App\Support\Pagination;
use App\Support\Session;
use App\Support\View;
use RuntimeException;

/**
 * 备课区（课件/作业模板库）。
 * 导入课程 = 逻辑快照：复制业务行、共享同一 files 行（file_id 引用）；
 * 模板修改/删除只影响之后的导入，不影响已导入的课程副本。
 */
final class LibraryController extends BaseController
{
    private const HANDOUT_EXTENSIONS = ['doc', 'docx', 'ppt', 'pptx', 'pdf'];
    private const ATTACHMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

    private readonly FileStorage $storage;

    public function __construct(
        AuthService $auth,
        private readonly LibraryHandoutRepository $libraryHandouts,
        private readonly LibraryAssignmentRepository $libraryAssignments,
        private readonly HandoutRepository $handouts,
        private readonly AssignmentRepository $assignments,
        private readonly AttachmentRepository $attachments,
        private readonly CourseRepository $courses,
        private readonly FileRepository $files,
        private readonly LogRepository $logs,
        ?FileStorage $storage = null,
    ) {
        parent::__construct($auth);
        $this->storage = $storage ?? FileStorage::fromConfig();
    }

    public function index(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $handoutPagination = Pagination::fromRequest('hp', $this->libraryHandouts->countAll());
        $assignmentPagination = Pagination::fromRequest('ap', $this->libraryAssignments->countAll());
        View::render('admin/library/index', [
            'title' => '备课区',
            'handouts' => $this->libraryHandouts->listAll($handoutPagination),
            'assignments' => $this->libraryAssignments->listAll($assignmentPagination),
            'handoutPagination' => $handoutPagination,
            'assignmentPagination' => $assignmentPagination,
        ]);
    }

    // ---- 课件模板 ----

    public function handoutStore(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '课件标题必填且不超过 200 字。');
            redirect('?r=admin/library');
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        try {
            $id = $this->storage->transactional(function () use ($title, $description) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'library/handouts',
                    self::HANDOUT_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                return Database::transaction(function () use ($meta, $title, $description) {
                    $fileId = $this->files->create($meta);
                    return $this->libraryHandouts->create($title, $description, $fileId);
                });
            });
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/library');
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.handout_upload', 'library_handout', (string) $id, $title, $this->ip());

        Session::flash('success', '课件已存入备课区。');
        redirect('?r=admin/library');
    }

    public function handoutEdit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        View::render('admin/library/handout_edit', [
            'title' => '编辑课件模板',
            'handout' => $this->findLibraryHandout(),
        ]);
    }

    public function handoutUpdate(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findLibraryHandout();
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '课件标题必填且不超过 200 字。');
            redirect('?r=admin/library/handouts/edit&id=' . $handout['id']);
        }
        $this->libraryHandouts->update((int) $handout['id'], $title, trim((string) ($_POST['description'] ?? '')));
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.handout_update', 'library_handout', (string) $handout['id'], $title, $this->ip());

        Session::flash('success', '课件模板已保存。');
        redirect('?r=admin/library');
    }

    /** 替换模板文件：只影响之后的导入，已导入的课程副本仍指向原文件 */
    public function handoutReplace(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findLibraryHandout();
        try {
            $originalName = $this->storage->transactional(function () use ($handout) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'library/handouts',
                    self::HANDOUT_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                $oldPath = Database::transaction(function () use ($meta, $handout) {
                    $newFileId = $this->files->create($meta);
                    $this->libraryHandouts->replaceFile((int) $handout['id'], $newFileId);
                    return $this->files->deleteIfUnused((int) $handout['file_id']);
                });
                if ($oldPath !== null) {
                    $this->storage->delete($oldPath);
                }
                return $meta['original_name'];
            });
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/library/handouts/edit&id=' . $handout['id']);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.handout_replace', 'library_handout', (string) $handout['id'], $originalName, $this->ip());

        Session::flash('success', '模板文件已替换（已导入的课程副本不受影响）。');
        redirect('?r=admin/library');
    }

    /** 删除模板：仅解除引用；文件仍被课程引用时保留 */
    public function handoutDelete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findLibraryHandout();
        $this->storage->transactional(function () use ($handout) {
            $oldPath = Database::transaction(function () use ($handout) {
                $this->libraryHandouts->delete((int) $handout['id']);
                return $this->files->deleteIfUnused((int) $handout['file_id']);
            });
            if ($oldPath !== null) {
                $this->storage->delete($oldPath);
            }
        });
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.handout_delete', 'library_handout', (string) $handout['id'], $handout['title'], $this->ip());

        Session::flash('success', '模板已删除；已导入的课程副本不受影响。');
        redirect('?r=admin/library');
    }

    // ---- 作业模板 ----

    public function assignmentCreate(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        View::render('admin/library/assignment_form', [
            'title' => '新建作业模板',
            'assignment' => null,
            'attachments' => [],
            'action' => '?r=admin/library/assignments/store',
            'submitExtensions' => AssignmentController::SUBMIT_EXTENSIONS,
        ]);
    }

    public function assignmentStore(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $data = $this->readAssignmentForm();
        if ($data === null) {
            redirect('?r=admin/library/assignments/create');
        }
        $id = $this->libraryAssignments->create($data);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.assignment_create', 'library_assignment', (string) $id, $data['title'], $this->ip());

        Session::flash('success', '作业模板已创建，可继续添加附件。');
        redirect('?r=admin/library/assignments/edit&id=' . $id);
    }

    public function assignmentEdit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $assignment = $this->findLibraryAssignment();
        View::render('admin/library/assignment_form', [
            'title' => '编辑作业模板',
            'assignment' => $assignment,
            'attachments' => $this->libraryAssignments->listAttachments((int) $assignment['id']),
            'action' => '?r=admin/library/assignments/update',
            'submitExtensions' => AssignmentController::SUBMIT_EXTENSIONS,
        ]);
    }

    public function assignmentUpdate(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findLibraryAssignment();
        $data = $this->readAssignmentForm();
        if ($data === null) {
            redirect('?r=admin/library/assignments/edit&id=' . $assignment['id']);
        }
        $this->libraryAssignments->update((int) $assignment['id'], $data);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.assignment_update', 'library_assignment', (string) $assignment['id'], $data['title'], $this->ip());

        Session::flash('success', '作业模板已保存。');
        redirect('?r=admin/library');
    }

    public function assignmentDelete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findLibraryAssignment();
        $this->storage->transactional(function () use ($assignment) {
            $paths = Database::transaction(function () use ($assignment) {
                $paths = [];
                foreach ($this->libraryAssignments->listAttachments((int) $assignment['id']) as $att) {
                    $this->libraryAssignments->removeAttachment((int) $att['id']);
                    $path = $this->files->deleteIfUnused((int) $att['file_id']);
                    if ($path !== null) {
                        $paths[] = $path;
                    }
                }
                $this->libraryAssignments->delete((int) $assignment['id']);
                return $paths;
            });
            foreach ($paths as $path) {
                $this->storage->delete($path);
            }
        });
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.assignment_delete', 'library_assignment', (string) $assignment['id'], $assignment['title'], $this->ip());

        Session::flash('success', '作业模板已删除；已导入的课程副本不受影响。');
        redirect('?r=admin/library');
    }

    public function attachmentAdd(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findLibraryAssignment();
        try {
            $originalName = $this->storage->transactional(function () use ($assignment) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'library/assignments/' . $assignment['id'] . '/attachments',
                    self::ATTACHMENT_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                Database::transaction(function () use ($meta, $assignment) {
                    $fileId = $this->files->create($meta);
                    $this->libraryAssignments->addAttachment((int) $assignment['id'], $fileId);
                });
                return $meta['original_name'];
            });
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/library/assignments/edit&id=' . $assignment['id']);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.attachment_add', 'library_assignment', (string) $assignment['id'], $originalName, $this->ip());

        Session::flash('success', '附件已添加。');
        redirect('?r=admin/library/assignments/edit&id=' . $assignment['id']);
    }

    public function attachmentDelete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $attachment = $this->libraryAssignments->findAttachmentById((int) ($_POST['id'] ?? 0));
        if ($attachment === null) {
            $this->notFound('附件不存在。');
        }
        $this->storage->transactional(function () use ($attachment) {
            $oldPath = Database::transaction(function () use ($attachment) {
                $this->libraryAssignments->removeAttachment((int) $attachment['id']);
                return $this->files->deleteIfUnused((int) $attachment['file_id']);
            });
            if ($oldPath !== null) {
                $this->storage->delete($oldPath);
            }
        });
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.attachment_delete', 'library_assignment', (string) $attachment['library_assignment_id'], $attachment['original_name'], $this->ip());

        Session::flash('success', '附件已删除。');
        redirect('?r=admin/library/assignments/edit&id=' . $attachment['library_assignment_id']);
    }

    // ---- 导入 / 存回 ----

    /** 导入选择页：课件或作业模板 */
    public function importSelect(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $course = $this->courses->findById((int) ($_GET['course_id'] ?? 0));
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        $type = (string) ($_GET['type'] ?? '');
        if (!in_array($type, ['handout', 'assignment'], true)) {
            $this->notFound('导入类型不正确。');
        }
        View::render('admin/library/import', [
            'title' => '从备课区导入',
            'course' => $course,
            'type' => $type,
            'handouts' => $type === 'handout' ? $this->libraryHandouts->listAll() : [],
            'assignments' => $type === 'assignment' ? $this->libraryAssignments->listAll() : [],
        ]);
    }

    /** 导入 = 逻辑快照：复制业务行、共享 file_id，模板与课程副本此后互不影响 */
    public function import(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $course = $this->courses->findById((int) ($_POST['course_id'] ?? 0));
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        if ($course['status'] !== 'active') {
            Session::flash('error', '已归档课程不能再导入。');
            redirect('?r=admin/courses/show&id=' . $course['id']);
        }
        $type = (string) ($_POST['type'] ?? '');
        $templateId = (int) ($_POST['template_id'] ?? 0);

        if ($type === 'handout') {
            $template = $this->libraryHandouts->findById($templateId);
            if ($template === null) {
                $this->notFound('课件模板不存在。');
            }
            $id = Database::transaction(fn () => $this->handouts->create(
                (int) $course['id'],
                $template['title'],
                $template['description'] !== null ? (string) $template['description'] : '',
                (int) $template['file_id']
            ));
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.import', 'course', (string) $course['id'], "模板 {$templateId} → 讲义 {$id}：{$template['title']}", $this->ip());
            Session::flash('success', "已导入课件《{$template['title']}》（草稿），发布后学生可见。");
            redirect('?r=admin/courses/show&id=' . $course['id']);
        }

        if ($type === 'assignment') {
            $template = $this->libraryAssignments->findById($templateId);
            if ($template === null) {
                $this->notFound('作业模板不存在。');
            }
            $id = Database::transaction(function () use ($course, $template) {
                $assignmentId = $this->assignments->create((int) $course['id'], [
                    'title' => $template['title'],
                    'requirements' => $template['requirements'],
                    'start_at' => null,
                    'deadline_at' => null,
                    'allowed_extensions' => $template['allowed_extensions'],
                    'max_file_size' => (int) $template['max_file_size'],
                    'allow_late' => (bool) $template['allow_late'],
                ]);
                foreach ($this->libraryAssignments->listAttachments((int) $template['id']) as $att) {
                    $this->attachments->add($assignmentId, (int) $att['file_id']);
                }
                return $assignmentId;
            });
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.import', 'course', (string) $course['id'], "模板 {$templateId} → 作业 {$id}：{$template['title']}", $this->ip());
            Session::flash('success', "已导入作业《{$template['title']}》（草稿），请设置开始/截止时间后发布。");
            redirect('?r=admin/courses/show&id=' . $course['id']);
        }

        $this->notFound('导入类型不正确。');
    }

    /** 把课程内的讲义/作业存回备课区（复制业务行、共享 file_id） */
    public function saveBack(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $type = (string) ($_POST['type'] ?? '');
        $sourceId = (int) ($_POST['id'] ?? 0);

        if ($type === 'handout') {
            $handout = $this->handouts->findById($sourceId);
            if ($handout === null) {
                $this->notFound('讲义不存在。');
            }
            $id = Database::transaction(fn () => $this->libraryHandouts->create(
                $handout['title'],
                $handout['description'] !== null ? (string) $handout['description'] : '',
                (int) $handout['file_id']
            ));
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.save_back_handout', 'library_handout', (string) $id, $handout['title'], $this->ip());
            Session::flash('success', "已将讲义《{$handout['title']}》存入备课区。");
            redirect('?r=admin/courses/show&id=' . $handout['course_id']);
        }

        if ($type === 'assignment') {
            $assignment = $this->assignments->findById($sourceId);
            if ($assignment === null) {
                $this->notFound('作业不存在。');
            }
            $id = Database::transaction(function () use ($assignment) {
                $libraryId = $this->libraryAssignments->create([
                    'title' => $assignment['title'],
                    'requirements' => $assignment['requirements'],
                    'allowed_extensions' => $assignment['allowed_extensions'],
                    'max_file_size' => (int) $assignment['max_file_size'],
                    'allow_late' => (bool) $assignment['allow_late'],
                ]);
                foreach ($this->attachments->listByAssignment((int) $assignment['id']) as $att) {
                    $this->libraryAssignments->addAttachment($libraryId, (int) $att['file_id']);
                }
                return $libraryId;
            });
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.save_back_assignment', 'library_assignment', (string) $id, $assignment['title'], $this->ip());
            Session::flash('success', "已将作业《{$assignment['title']}》存入备课区。");
            redirect('?r=admin/courses/show&id=' . $assignment['course_id']);
        }

        $this->notFound('类型不正确。');
    }

    private function readAssignmentForm(): ?array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '作业标题必填且不超过 200 字。');
            return null;
        }
        $extensions = array_values(array_intersect(
            AssignmentController::SUBMIT_EXTENSIONS,
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
            'allowed_extensions' => implode(',', $extensions),
            'max_file_size' => $maxMb * 1024 * 1024,
            'allow_late' => ($_POST['allow_late'] ?? '') === '1',
        ];
    }

    private function findLibraryHandout(): array
    {
        $handout = $this->libraryHandouts->findById((int) (($_POST['id'] ?? $_GET['id']) ?? 0));
        if ($handout === null) {
            $this->notFound('课件模板不存在。');
        }
        return $handout;
    }

    private function findLibraryAssignment(): array
    {
        $assignment = $this->libraryAssignments->findById((int) (($_POST['id'] ?? $_GET['id']) ?? 0));
        if ($assignment === null) {
            $this->notFound('作业模板不存在。');
        }
        return $assignment;
    }

    private function teacherMaxSize(): int
    {
        return (int) App::config('upload.teacher_max_size', 50 * 1024 * 1024);
    }
}

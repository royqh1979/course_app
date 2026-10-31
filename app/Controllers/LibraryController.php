<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryAssignmentRepository;
use App\Repositories\LibraryFolderRepository;
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
 * 备课区（课件/作业库）：两棵按类型独立的文件夹树（深度 ≤3），条目经编辑归入文件夹。
 * 导入课程 = 逻辑快照：复制业务行、共享同一 files 行（file_id 引用）；
 * 模板修改/删除只影响之后的导入，不影响已导入的课程副本。
 */
final class LibraryController extends BaseController
{
    private const HANDOUT_EXTENSIONS = ['doc', 'docx', 'ppt', 'pptx', 'pdf', 'zip', 'xls', 'xlsx'];
    private const ATTACHMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
    private const TYPES = ['handout', 'assignment'];
    /** 各类型列表页路由（用于跳转与 tabs） */
    private const TYPE_ROUTES = ['handout' => 'admin/library/handouts', 'assignment' => 'admin/library/assignments'];
    private const TYPE_NAMES = ['handout' => '课件', 'assignment' => '作业'];

    private readonly FileStorage $storage;

    public function __construct(
        AuthService $auth,
        private readonly LibraryHandoutRepository $libraryHandouts,
        private readonly LibraryAssignmentRepository $libraryAssignments,
        private readonly LibraryFolderRepository $folders,
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
        redirect('?r=' . self::TYPE_ROUTES['handout']);
    }

    // ---- 列表页（按文件夹浏览） ----

    public function handouts(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $folderId = $this->folderIdFromRequest('handout');
        $pagination = Pagination::fromRequest('page', $this->libraryHandouts->countByFolder($folderId));
        View::render('admin/library/handouts', $this->listPageData('handout', $folderId, $pagination) + [
            'handouts' => $this->libraryHandouts->listByFolder($folderId, $pagination),
            'pagination' => $pagination,
        ]);
    }

    public function assignments(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $folderId = $this->folderIdFromRequest('assignment');
        $pagination = Pagination::fromRequest('page', $this->libraryAssignments->countByFolder($folderId));
        View::render('admin/library/assignments', $this->listPageData('assignment', $folderId, $pagination) + [
            'assignments' => $this->libraryAssignments->listByFolder($folderId, $pagination),
            'pagination' => $pagination,
        ]);
    }

    /** 列表页公共数据（文件夹树、面包屑、子文件夹、可选项） */
    private function listPageData(string $type, ?int $folderId, Pagination $pagination): array
    {
        return [
            'title' => '备课区 · ' . self::TYPE_NAMES[$type],
            'type' => $type,
            'typeRoutes' => self::TYPE_ROUTES,
            'folders' => $this->folders->listByType($type),
            'folderCounts' => $this->folders->itemCountsByType($type),
            'rootCount' => $this->folders->countRootItems($type),
            'currentFolderId' => $folderId,
            'folderPath' => $folderId !== null ? $this->folderPath($folderId) : [],
            'childFolders' => $this->folders->childrenOf($folderId, $type),
            'folderOptions' => $this->folderOptions($type),
            'maxDepth' => $this->folders->maxDepth(),
        ];
    }

    // ---- 文件夹 ----

    public function folderStore(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $type = (string) ($_POST['item_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            $this->notFound('类型不正确。');
        }
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        if ($parentId !== null) {
            $parent = $this->folders->findById($parentId);
            if ($parent === null || $parent['item_type'] !== $type) {
                $this->notFound('父文件夹不存在。');
            }
            if ($this->folders->depthOf($parentId) >= $this->folders->maxDepth()) {
                Session::flash('error', '文件夹最多 ' . $this->folders->maxDepth() . ' 层，不能在此下再建子文件夹。');
                redirect('?r=' . self::TYPE_ROUTES[$type] . '&folder_id=' . $parentId);
            }
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            Session::flash('error', '文件夹名称必填且不超过 100 字。');
            redirect('?r=' . self::TYPE_ROUTES[$type] . ($parentId !== null ? '&folder_id=' . $parentId : ''));
        }
        $id = $this->folders->create($parentId, $name, $type);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.folder_create', 'library_folder', (string) $id, $name, $this->ip());

        Session::flash('success', "文件夹「{$name}」已创建。");
        redirect('?r=' . self::TYPE_ROUTES[$type] . '&folder_id=' . $id);
    }

    public function folderRename(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $folder = $this->findFolder();
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            Session::flash('error', '文件夹名称必填且不超过 100 字。');
            redirect('?r=' . self::TYPE_ROUTES[$folder['item_type']] . '&folder_id=' . $folder['id']);
        }
        $this->folders->rename((int) $folder['id'], $name);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.folder_rename', 'library_folder', (string) $folder['id'], $name, $this->ip());

        Session::flash('success', '文件夹已重命名。');
        redirect('?r=' . self::TYPE_ROUTES[$folder['item_type']] . '&folder_id=' . $folder['id']);
    }

    public function folderDelete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $folder = $this->findFolder();
        if ($this->folders->countChildren((int) $folder['id']) > 0 || $this->folders->countItems((int) $folder['id'], $folder['item_type']) > 0) {
            Session::flash('error', '文件夹非空（含有子文件夹或条目），请先清空后再删除。');
            redirect('?r=' . self::TYPE_ROUTES[$folder['item_type']] . '&folder_id=' . $folder['id']);
        }
        $this->folders->delete((int) $folder['id']);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.folder_delete', 'library_folder', (string) $folder['id'], $folder['name'], $this->ip());

        Session::flash('success', "文件夹「{$folder['name']}」已删除。");
        redirect('?r=' . self::TYPE_ROUTES[$folder['item_type']]
            . ($folder['parent_id'] !== null ? '&folder_id=' . (int) $folder['parent_id'] : ''));
    }

    // ---- 课件 ----

    public function handoutStore(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $folderId = $this->itemFolderIdFromRequest('handout');
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '课件标题必填且不超过 200 字。');
            redirect('?r=' . self::TYPE_ROUTES['handout'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        try {
            $id = $this->storage->transactional(function () use ($title, $description, $folderId) {
                $meta = $this->storage->store(
                    $_FILES['file'] ?? [],
                    'library/handouts',
                    self::HANDOUT_EXTENSIONS,
                    $this->teacherMaxSize()
                );
                return Database::transaction(function () use ($meta, $title, $description, $folderId) {
                    $fileId = $this->files->create($meta);
                    return $this->libraryHandouts->create($title, $description, $fileId, $folderId);
                });
            });
        } catch (RuntimeException $e) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, $e->getMessage());
            }
            Session::flash('error', $e->getMessage());
            redirect('?r=' . self::TYPE_ROUTES['handout'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.handout_upload', 'library_handout', (string) $id, $title, $this->ip());

        $message = "《{$title}》已存入备课区。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message, '?r=' . self::TYPE_ROUTES['handout'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
        }
        Session::flash('success', $message);
        redirect('?r=' . self::TYPE_ROUTES['handout'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
    }

    public function handoutEdit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        View::render('admin/library/handout_edit', [
            'title' => '编辑课件',
            'handout' => $this->findLibraryHandout(),
            'folderOptions' => $this->folderOptions('handout'),
            'typeRoute' => self::TYPE_ROUTES['handout'],
        ]);
    }

    public function handoutUpdate(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findLibraryHandout();
        $folderId = $this->itemFolderIdFromRequest('handout');
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '课件标题必填且不超过 200 字。');
            redirect('?r=admin/library/handouts/edit&id=' . $handout['id']);
        }
        $this->libraryHandouts->update((int) $handout['id'], $title, trim((string) ($_POST['description'] ?? '')), $folderId);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.handout_update', 'library_handout', (string) $handout['id'], $title, $this->ip());

        Session::flash('success', '课件已保存。');
        redirect('?r=' . self::TYPE_ROUTES['handout'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
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

        Session::flash('success', '文件已替换（已导入的课程副本不受影响）。');
        redirect('?r=' . self::TYPE_ROUTES['handout']
            . ($handout['folder_id'] !== null ? '&folder_id=' . (int) $handout['folder_id'] : ''));
    }

    /** 删除模板：仅解除引用；文件仍被课程引用时保留 */
    public function handoutDelete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $handout = $this->findLibraryHandout();
        $folderId = $handout['folder_id'] !== null ? (int) $handout['folder_id'] : null;
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

        Session::flash('success', '课件已删除；已导入的课程副本不受影响。');
        redirect('?r=' . self::TYPE_ROUTES['handout'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
    }

    // ---- 作业 ----

    public function assignmentCreate(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $folderId = $this->itemFolderIdFromRequest('assignment', true);
        View::render('admin/library/assignment_form', [
            'title' => '新建作业',
            'assignment' => null,
            'attachments' => [],
            'action' => '?r=admin/library/assignments/store',
            'extensionGroups' => AssignmentController::EXTENSION_GROUPS,
            'extensionGroupLabels' => AssignmentController::EXTENSION_GROUP_LABELS,
            'selectedGroups' => [],
            'folderOptions' => $this->folderOptions('assignment'),
            'currentFolderId' => $folderId,
            'typeRoute' => self::TYPE_ROUTES['assignment'],
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
        $data['folder_id'] = $this->itemFolderIdFromRequest('assignment', true);
        // 作业行与随表单多附件在同一事务落库；单个附件校验失败不中断创建
        [$id, $attached, $failures] = $this->storage->transactional(function () use ($data) {
            $libraryId = Database::transaction(fn () => $this->libraryAssignments->create($data));
            $okNames = [];
            $failNames = [];
            foreach ($this->normalizedUploads($_FILES['attachments'] ?? []) as $file) {
                if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                try {
                    $meta = $this->storage->store(
                        $file,
                        'library/assignments/' . $libraryId . '/attachments',
                        self::ATTACHMENT_EXTENSIONS,
                        $this->teacherMaxSize()
                    );
                    Database::transaction(function () use ($meta, $libraryId) {
                        $fileId = $this->files->create($meta);
                        $this->libraryAssignments->addAttachment($libraryId, $fileId);
                    });
                    $okNames[] = $meta['original_name'];
                } catch (RuntimeException $e) {
                    $failNames[] = $file['name'] . '：' . $e->getMessage();
                }
            }
            return [$libraryId, $okNames, $failNames];
        });
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.assignment_create', 'library_assignment', (string) $id, $data['title'], $this->ip());

        $message = '作业已创建，可继续添加附件。';
        if ($attached !== []) {
            $message .= ' 随表单附件 ' . count($attached) . ' 个上传成功。';
        }
        if ($failures !== []) {
            $message .= ' 附件失败 ' . count($failures) . ' 个：' . implode('；', $failures) . '。';
        }
        Session::flash('success', $message);
        redirect('?r=admin/library/assignments/edit&id=' . $id);
    }

    public function assignmentEdit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $assignment = $this->findLibraryAssignment();
        View::render('admin/library/assignment_form', [
            'title' => '编辑作业',
            'assignment' => $assignment,
            'attachments' => $this->libraryAssignments->listAttachments((int) $assignment['id']),
            'action' => '?r=admin/library/assignments/update',
            'extensionGroups' => AssignmentController::EXTENSION_GROUPS,
            'extensionGroupLabels' => AssignmentController::EXTENSION_GROUP_LABELS,
            'selectedGroups' => AssignmentController::selectedGroups($assignment['allowed_extensions'] ?? null),
            'folderOptions' => $this->folderOptions('assignment'),
            'currentFolderId' => $assignment['folder_id'] !== null ? (int) $assignment['folder_id'] : null,
            'typeRoute' => self::TYPE_ROUTES['assignment'],
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
        $data['folder_id'] = $this->itemFolderIdFromRequest('assignment', true);
        $this->libraryAssignments->update((int) $assignment['id'], $data);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.assignment_update', 'library_assignment', (string) $assignment['id'], $data['title'], $this->ip());

        Session::flash('success', '作业已保存。');
        redirect('?r=' . self::TYPE_ROUTES['assignment']
            . ($data['folder_id'] !== null ? '&folder_id=' . $data['folder_id'] : ''));
    }

    public function assignmentDelete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $assignment = $this->findLibraryAssignment();
        $folderId = $assignment['folder_id'] !== null ? (int) $assignment['folder_id'] : null;
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

        Session::flash('success', '作业课件已删除；已导入的课程副本不受影响。');
        redirect('?r=' . self::TYPE_ROUTES['assignment'] . ($folderId !== null ? '&folder_id=' . $folderId : ''));
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
            if ($this->wantsJson()) {
                $this->jsonResponse(false, $e->getMessage());
            }
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/library/assignments/edit&id=' . $assignment['id']);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.attachment_add', 'library_assignment', (string) $assignment['id'], $originalName, $this->ip());

        $message = "附件《{$originalName}》已添加。";
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message, '?r=admin/library/assignments/edit&id=' . $assignment['id']);
        }
        Session::flash('success', $message);
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

    /** 导入选择页：课件或作业模板，支持按文件夹浏览 */
    public function importSelect(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $course = $this->courses->findById((int) ($_GET['course_id'] ?? 0));
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        $type = (string) ($_GET['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            $this->notFound('导入类型不正确。');
        }
        $folderId = $this->folderIdFromRequest($type);
        View::render('admin/library/import', [
            'title' => '从备课区导入 — ' . $course['name'],
            'course' => $course,
            'type' => $type,
            'typeRoutes' => self::TYPE_ROUTES,
            'folders' => $this->folders->listByType($type),
            'folderCounts' => $this->folders->itemCountsByType($type),
            'rootCount' => $this->folders->countRootItems($type),
            'currentFolderId' => $folderId,
            'folderPath' => $folderId !== null ? $this->folderPath($folderId) : [],
            'childFolders' => $this->folders->childrenOf($folderId, $type),
            'handouts' => $type === 'handout' ? $this->libraryHandouts->listByFolder($folderId) : [],
            'assignments' => $type === 'assignment' ? $this->libraryAssignments->listByFolder($folderId) : [],
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
            if ($this->wantsJson()) {
                $this->jsonResponse(false, '已归档课程不能再导入。');
            }
            Session::flash('error', '已归档课程不能再导入。');
            redirect('?r=admin/courses/handouts&id=' . $course['id']);
        }
        $type = (string) ($_POST['type'] ?? '');
        // 批量导入由前端队列逐条发起（每次一个 template_id）；template_ids[] 为无 JS 原生提交的兼容字段，仅取第一条
        $rawIds = $_POST['template_ids'] ?? $_POST['template_id'] ?? 0;
        $templateId = (int) (is_array($rawIds) ? ($rawIds[0] ?? 0) : $rawIds);
        if ($templateId <= 0) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, '请先选择要导入的内容。');
            }
            Session::flash('error', '请先选择要导入的内容。');
            redirect('?r=admin/library/import&course_id=' . $course['id'] . '&type=' . $type);
        }
        $multiNote = is_array($rawIds) && count($rawIds) > 1 ? '（浏览器未启用 JavaScript，一次仅导入第一个所选条目）' : '';
        $courseRoute = $type === 'assignment' ? 'admin/courses/assignments' : 'admin/courses/handouts';

        if ($type === 'handout') {
            $template = $this->libraryHandouts->findById($templateId);
            if ($template === null) {
                if ($this->wantsJson()) {
                    $this->jsonResponse(false, '课件不存在。');
                }
                $this->notFound('课件不存在。');
            }
            $id = Database::transaction(fn () => $this->handouts->create(
                (int) $course['id'],
                $template['title'],
                $template['description'] !== null ? (string) $template['description'] : '',
                (int) $template['file_id']
            ));
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'handout.import', 'course', (string) $course['id'], "备课区课件 {$templateId} → 讲义 {$id}：{$template['title']}", $this->ip());
            $message = "已导入课件《{$template['title']}》，发布后学生可见。" . $multiNote;
            if ($this->wantsJson()) {
                $this->jsonResponse(true, $message, '?r=' . $courseRoute . '&id=' . $course['id']);
            }
            Session::flash('success', $message);
            redirect('?r=' . $courseRoute . '&id=' . $course['id']);
        }

        if ($type === 'assignment') {
            $template = $this->libraryAssignments->findById($templateId);
            if ($template === null) {
                if ($this->wantsJson()) {
                    $this->jsonResponse(false, '作业不存在。');
                }
                $this->notFound('作业不存在。');
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
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'assignment.import', 'course', (string) $course['id'], "备课区作业 {$templateId} → 作业 {$id}：{$template['title']}", $this->ip());
            $message = "已导入作业《{$template['title']}》，请设置开始/截止时间后发布。" . $multiNote;
            if ($this->wantsJson()) {
                $this->jsonResponse(true, $message, '?r=' . $courseRoute . '&id=' . $course['id']);
            }
            Session::flash('success', $message);
            redirect('?r=' . $courseRoute . '&id=' . $course['id']);
        }

        $this->notFound('导入类型不正确。');
    }

    /**
     * 把课程内的讲义/作业存入备课区（复制业务行、共享 file_id）。
     * 支持选择目标文件夹；同标题冲突时可选择 覆盖（替换既有条目）/ 改名（以新标题新建）/ 放弃。
     */
    public function saveBack(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $type = (string) ($_POST['type'] ?? '');
        $sourceId = (int) ($_POST['id'] ?? 0);
        $folderId = $this->itemFolderIdFromRequest($type);
        $onConflict = (string) ($_POST['on_conflict'] ?? '');
        $newTitle = trim((string) ($_POST['new_title'] ?? ''));

        if ($type === 'handout') {
            $handout = $this->handouts->findById($sourceId);
            if ($handout === null) {
                $this->notFound('讲义不存在。');
            }
            $redirect = '?r=admin/courses/handouts&id=' . $handout['course_id'];
            $title = $handout['title'];
            $existing = $this->libraryHandouts->findByFolderTitle($folderId, $title);

            if ($existing !== null && $onConflict === '') {
                $this->saveBackConflict("目标文件夹已存在同名课件《{$title}》，请选择覆盖、改名或放弃。", $redirect);
            }
            if ($existing !== null && $onConflict === 'rename') {
                if ($newTitle === '' || mb_strlen($newTitle) > 200) {
                    $this->saveBackFail('请填写新标题（不超过 200 字）。', $redirect);
                }
                if ($this->libraryHandouts->findByFolderTitle($folderId, $newTitle) !== null) {
                    $this->saveBackFail("目标文件夹已存在《{$newTitle}》，请换一个标题。", $redirect);
                }
                $title = $newTitle;
                $existing = null;
            }

            if ($existing !== null) {
                // 覆盖：用课程侧内容替换既有备课区条目，旧文件引用计数回收
                $this->storage->transactional(function () use ($existing, $handout, $title) {
                    $oldPath = Database::transaction(function () use ($existing, $handout, $title) {
                        $this->libraryHandouts->update((int) $existing['id'], $title, (string) ($handout['description'] ?? ''), $existing['folder_id'] !== null ? (int) $existing['folder_id'] : null);
                        $this->libraryHandouts->replaceFile((int) $existing['id'], (int) $handout['file_id']);
                        return $this->files->deleteIfUnused((int) $existing['file_id']);
                    });
                    if ($oldPath !== null) {
                        $this->storage->delete($oldPath);
                    }
                });
                $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.save_back_handout', 'library_handout', (string) $existing['id'], "覆盖：{$title}", $this->ip());
                $this->saveBackDone("已用课程版本覆盖备课区课件《{$title}》。", $redirect);
            }

            $id = Database::transaction(fn () => $this->libraryHandouts->create(
                $title,
                $handout['description'] !== null ? (string) $handout['description'] : '',
                (int) $handout['file_id'],
                $folderId
            ));
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.save_back_handout', 'library_handout', (string) $id, $title, $this->ip());
            $this->saveBackDone("已将讲义《{$title}》存入备课区。", $redirect);
        }

        if ($type === 'assignment') {
            $assignment = $this->assignments->findById($sourceId);
            if ($assignment === null) {
                $this->notFound('作业不存在。');
            }
            $redirect = '?r=admin/courses/assignments&id=' . $assignment['course_id'];
            $title = $assignment['title'];
            $existing = $this->libraryAssignments->findByFolderTitle($folderId, $title);

            if ($existing !== null && $onConflict === '') {
                $this->saveBackConflict("目标文件夹已存在同名作业《{$title}》，请选择覆盖、改名或放弃。", $redirect);
            }
            if ($existing !== null && $onConflict === 'rename') {
                if ($newTitle === '' || mb_strlen($newTitle) > 200) {
                    $this->saveBackFail('请填写新标题（不超过 200 字）。', $redirect);
                }
                if ($this->libraryAssignments->findByFolderTitle($folderId, $newTitle) !== null) {
                    $this->saveBackFail("目标文件夹已存在《{$newTitle}》，请换一个标题。", $redirect);
                }
                $title = $newTitle;
                $existing = null;
            }

            if ($existing !== null) {
                // 覆盖：字段更新 + 附件整体替换（旧附件引用计数回收）
                $this->storage->transactional(function () use ($existing, $assignment, $title) {
                    $oldPaths = Database::transaction(function () use ($existing, $assignment, $title) {
                        $this->libraryAssignments->update((int) $existing['id'], [
                            'title' => $title,
                            'requirements' => $assignment['requirements'],
                            'allowed_extensions' => $assignment['allowed_extensions'],
                            'max_file_size' => (int) $assignment['max_file_size'],
                            'allow_late' => (bool) $assignment['allow_late'],
                            'folder_id' => $existing['folder_id'] !== null ? (int) $existing['folder_id'] : null,
                        ]);
                        $paths = [];
                        foreach ($this->libraryAssignments->listAttachments((int) $existing['id']) as $att) {
                            $this->libraryAssignments->removeAttachment((int) $att['id']);
                            $path = $this->files->deleteIfUnused((int) $att['file_id']);
                            if ($path !== null) {
                                $paths[] = $path;
                            }
                        }
                        foreach ($this->attachments->listByAssignment((int) $assignment['id']) as $att) {
                            $this->libraryAssignments->addAttachment((int) $existing['id'], (int) $att['file_id']);
                        }
                        return $paths;
                    });
                    foreach ($oldPaths as $path) {
                        $this->storage->delete($path);
                    }
                });
                $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.save_back_assignment', 'library_assignment', (string) $existing['id'], "覆盖：{$title}", $this->ip());
                $this->saveBackDone("已用课程版本覆盖备课区作业《{$title}》。", $redirect);
            }

            $id = Database::transaction(function () use ($assignment, $title, $folderId) {
                $libraryId = $this->libraryAssignments->create([
                    'title' => $title,
                    'requirements' => $assignment['requirements'],
                    'allowed_extensions' => $assignment['allowed_extensions'],
                    'max_file_size' => (int) $assignment['max_file_size'],
                    'allow_late' => (bool) $assignment['allow_late'],
                    'folder_id' => $folderId,
                ]);
                foreach ($this->attachments->listByAssignment((int) $assignment['id']) as $att) {
                    $this->libraryAssignments->addAttachment($libraryId, (int) $att['file_id']);
                }
                return $libraryId;
            });
            $this->logs->addAuditLog('admin', (int) $admin['id'], 'library.save_back_assignment', 'library_assignment', (string) $id, $title, $this->ip());
            $this->saveBackDone("已将作业《{$title}》存入备课区。", $redirect);
        }

        $this->notFound('类型不正确。');
    }

    /** 存入备课区：同标题冲突响应（XHR 带 conflict 标记；原生 flash 报错） */
    private function saveBackConflict(string $message, string $redirect): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'conflict' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Session::flash('error', $message . '（浏览器未启用 JavaScript：请先在课程中改名后再存，或在备课区手动处理同名条目。）');
        redirect($redirect);
    }

    private function saveBackFail(string $message, string $redirect): void
    {
        if ($this->wantsJson()) {
            $this->jsonResponse(false, $message);
        }
        Session::flash('error', $message);
        redirect($redirect);
    }

    private function saveBackDone(string $message, string $redirect): void
    {
        if ($this->wantsJson()) {
            $this->jsonResponse(true, $message, $redirect);
        }
        Session::flash('success', $message);
        redirect($redirect);
    }

    // ---- 表单与查找辅助 ----

    private function readAssignmentForm(): ?array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            Session::flash('error', '作业标题必填且不超过 200 字。');
            return null;
        }
        $extensions = array_values(array_intersect(
            AssignmentController::SUBMIT_EXTENSIONS,
            array_map(
                'strval',
                array_merge(
                    (array) ($_POST['allowed_extensions'] ?? []),
                    AssignmentController::expandGroups((array) ($_POST['allowed_groups'] ?? []))
                )
            )
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
            $this->notFound('课件不存在。');
        }
        return $handout;
    }

    private function findLibraryAssignment(): array
    {
        $assignment = $this->libraryAssignments->findById((int) (($_POST['id'] ?? $_GET['id']) ?? 0));
        if ($assignment === null) {
            $this->notFound('作业不存在。');
        }
        return $assignment;
    }

    private function findFolder(): array
    {
        $folder = $this->folders->findById((int) (($_POST['id'] ?? $_GET['id']) ?? 0));
        if ($folder === null) {
            $this->notFound('文件夹不存在。');
        }
        return $folder;
    }

    /** 读取并校验当前文件夹（列表/导入页浏览用）：必须与类型匹配 */
    private function folderIdFromRequest(string $type): ?int
    {
        $folderId = (int) ($_GET['folder_id'] ?? 0) ?: null;
        if ($folderId !== null) {
            $folder = $this->folders->findById($folderId);
            if ($folder === null || $folder['item_type'] !== $type) {
                $this->notFound('文件夹不存在。');
            }
        }
        return $folderId;
    }

    /** 读取并校验条目表单提交的 folder_id（空 = 根目录；类型必须匹配） */
    private function itemFolderIdFromRequest(string $type, bool $nullableInvalid = false): ?int
    {
        $raw = trim((string) ($_POST['folder_id'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $folder = $this->folders->findById((int) $raw);
        if ($folder === null || $folder['item_type'] !== $type) {
            if ($nullableInvalid) {
                return null;
            }
            $this->notFound('文件夹不存在。');
        }
        return (int) $raw;
    }

    /** 根到当前文件夹的路径（不含根） */
    private function folderPath(int $folderId): array
    {
        $path = [];
        $current = $this->folders->findById($folderId);
        while ($current !== null) {
            array_unshift($path, $current);
            $current = $current['parent_id'] !== null ? $this->folders->findById((int) $current['parent_id']) : null;
        }
        return $path;
    }

    /** 该类型全部文件夹的扁平缩进列表（select 用）：[id, name, depth] */
    private function folderOptions(string $type): array
    {
        $byParent = [];
        foreach ($this->folders->listByType($type) as $folder) {
            $byParent[$folder['parent_id'] ?? 0][] = $folder;
        }
        $options = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$options, $byParent): void {
            foreach ($byParent[$parentId ?? 0] ?? [] as $folder) {
                $options[] = ['id' => (int) $folder['id'], 'name' => (string) $folder['name'], 'depth' => $depth];
                $walk((int) $folder['id'], $depth + 1);
            }
        };
        $walk(null, 1);
        return $options;
    }

    /** 将 $_FILES['attachments'] 归一化为逐文件数组 */
    private function normalizedUploads(array $files): array
    {
        $out = [];
        foreach ((array) ($files['name'] ?? []) as $i => $name) {
            $out[] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    private function teacherMaxSize(): int
    {
        return (int) App::config('upload.teacher_max_size', 50 * 1024 * 1024);
    }
}

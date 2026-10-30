<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CourseRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Services\AuthService;
use App\Services\RosterImportService;
use App\Support\App;
use App\Support\Session;
use App\Support\Validate;
use App\Support\View;
use PDOException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Ramsey\Uuid\Uuid;

final class RosterController extends BaseController
{
    /** 名单导入文件大小上限：5 MB */
    private const IMPORT_MAX_SIZE = 5 * 1024 * 1024;

    private const IMPORT_EXTENSIONS = ['xlsx', 'xls'];

    private const IMPORT_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];

    public function __construct(
        AuthService $auth,
        private readonly RosterRepository $roster,
        private readonly CourseRepository $courses,
        private readonly LogRepository $logs,
        private readonly RosterImportService $importService = new RosterImportService(),
    ) {
        parent::__construct($auth);
    }

    /** 手工逐条添加名单 */
    public function add(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $courseId = (int) ($_POST['course_id'] ?? 0);
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }

        $studentNo = Validate::cleanStudentNo($_POST['student_no'] ?? '');
        $name = Validate::cleanName($_POST['name'] ?? '');
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if (!Validate::isValidStudentNo($studentNo) || !Validate::isValidName($name)) {
            Session::flash('error', '学号或姓名格式不正确。');
            redirect('?r=admin/courses/show&id=' . $courseId);
        }

        try {
            $this->roster->add($courseId, $studentNo, $name, $className);
        } catch (PDOException) {
            Session::flash('error', "学号 {$studentNo} 已在本课程名单中。");
            redirect('?r=admin/courses/show&id=' . $courseId);
        }
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'roster.add', 'course', (string) $courseId, "{$className} {$studentNo} {$name}", $this->ip());

        Session::flash('success', '名单已添加，匹配学生即时获得课程权限。');
        redirect('?r=admin/courses/show&id=' . $courseId);
    }

    public function delete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $entry = $this->roster->findById($id);
        if ($entry === null) {
            $this->notFound('名单记录不存在。');
        }
        $this->roster->remove($id);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'roster.delete', 'course', (string) $entry['course_id'], "{$entry['class_name']} {$entry['student_no']} {$entry['name']}", $this->ip());

        Session::flash('success', '名单已移除，该学生即时失去课程权限（历史数据保留）。');
        redirect('?r=admin/courses/show&id=' . $entry['course_id']);
    }

    /** 下载名单导入标准模板（姓名 / 班级 / 学号，学号列为文本格式） */
    public function template(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);

        $spreadsheet = $this->importService->createTemplate();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="roster_template.xlsx"; filename*=UTF-8\'\'' . rawurlencode('课程名单导入模板.xlsx'));
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    /**
     * 导入第一步：接收上传文件并预校验，展示总行/有效/错误/重复统计，不写库。
     * XHR 上传返回 JSON，跳转 GET 预校验页；原生表单直接渲染预校验页。
     */
    public function import(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $courseId = (int) ($_POST['course_id'] ?? 0);
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }

        try {
            $storedPath = $this->storeUpload();
            try {
                $rows = $this->importService->parse($this->absolutePath($storedPath));
            } catch (\Throwable) {
                $this->discardUpload($storedPath);
                throw new RuntimeException('无法解析该文件，请使用标准模板（.xlsx / .xls）。');
            }

            $result = $this->importService->validate($rows, $this->roster->existingStudentNos($courseId));
            if ($result['total'] === 0) {
                $this->discardUpload($storedPath);
                throw new RuntimeException('文件中没有可导入的数据行（第 1 行为表头）。');
            }
        } catch (RuntimeException $e) {
            if ($this->wantsJson()) {
                $this->jsonResponse(false, $e->getMessage());
            }
            Session::flash('error', $e->getMessage());
            redirect('?r=admin/courses/show&id=' . $courseId);
        }

        // 暂存待确认导入，确认时以暂存文件为准重新解析
        $_SESSION['roster_import'] = [
            'course_id' => $courseId,
            'path' => $storedPath,
            'original_name' => (string) ($_FILES['roster_file']['name'] ?? ''),
        ];

        if ($this->wantsJson()) {
            $this->jsonResponse(true, '预校验完成，请确认导入内容。', '?r=admin/roster/import/preview');
        }
        View::render('admin/roster/import_preview', [
            'title' => '名单导入预校验',
            'course' => $course,
            'result' => $result,
            'fileName' => (string) ($_FILES['roster_file']['name'] ?? ''),
        ]);
    }

    /** GET 预校验页（XHR 上传后跳转；以暂存文件重新解析校验） */
    public function preview(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);

        $pending = $_SESSION['roster_import'] ?? null;
        if (!is_array($pending) || !is_file($this->absolutePath((string) $pending['path']))) {
            Session::flash('error', '导入会话已失效，请重新上传名单文件。');
            redirect('?r=admin/courses');
        }
        $course = $this->courses->findById((int) $pending['course_id']);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }

        $rows = $this->importService->parse($this->absolutePath((string) $pending['path']));
        $result = $this->importService->validate($rows, $this->roster->existingStudentNos((int) $course['id']));
        View::render('admin/roster/import_preview', [
            'title' => '名单导入预校验',
            'course' => $course,
            'result' => $result,
            'fileName' => (string) $pending['original_name'],
        ]);
    }

    /**
     * 导入第二步：确认后按暂存文件重新解析校验，在事务中增量导入，失败整体回滚。
     */
    public function confirmImport(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $pending = $_SESSION['roster_import'] ?? null;
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        if (!is_array($pending) || (int) $pending['course_id'] !== $courseId || !is_file($this->absolutePath($pending['path']))) {
            Session::flash('error', '导入会话已失效，请重新上传名单文件。');
            redirect('?r=admin/courses/show&id=' . $courseId);
        }

        // 以暂存文件为唯一数据源，重新解析并按当前名单重新校验
        $rows = $this->importService->parse($this->absolutePath($pending['path']));
        $result = $this->importService->validate($rows, $this->roster->existingStudentNos($courseId));

        $summary = $this->roster->importBatch($courseId, $result['valid']);
        $this->discardUpload($pending['path']);
        unset($_SESSION['roster_import']);

        $this->logs->addAuditLog(
            'admin',
            (int) $admin['id'],
            'roster.import',
            'course',
            (string) $courseId,
            sprintf(
                '文件：%s；新增 %d 人，更新 %d 人，跳过错误 %d 行、重复 %d 行',
                $pending['original_name'],
                $summary['inserted'],
                $summary['updated'],
                count($result['errors']),
                count($result['duplicates'])
            ),
            $this->ip()
        );

        Session::flash('success', "导入完成：新增 {$summary['inserted']} 人，更新 {$summary['updated']} 人；权限即时生效。");
        redirect('?r=admin/courses/show&id=' . $courseId);
    }

    /**
     * 保存上传文件到 storage/imports/，校验顺序：后缀白名单 → 大小 → MIME。
     * 返回相对 storage/ 的路径。
     *
     * @throws RuntimeException 校验或保存失败（消息可直接展示给用户）
     */
    private function storeUpload(): string
    {
        $file = $_FILES['roster_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('请选择要上传的名单文件。');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::IMPORT_EXTENSIONS, true)) {
            throw new RuntimeException('仅支持 .xlsx 或 .xls 格式的名单文件。');
        }
        if ((int) $file['size'] > self::IMPORT_MAX_SIZE) {
            throw new RuntimeException('名单文件大小不能超过 5 MB。');
        }
        $mime = (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), (string) $file['tmp_name']) ?: '');
        if (!in_array($mime, self::IMPORT_MIMES, true)) {
            throw new RuntimeException('文件内容类型不符合 Excel 格式要求。');
        }

        $storedPath = 'imports/' . Uuid::uuid7()->toString() . '.' . $extension;
        $absolute = $this->absolutePath($storedPath);
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0750, true)) {
            throw new RuntimeException('服务器存储目录不可用，请联系管理员。');
        }
        if (!move_uploaded_file((string) $file['tmp_name'], $absolute) && !rename((string) $file['tmp_name'], $absolute)) {
            throw new RuntimeException('文件保存失败，请重试。');
        }

        // 替换旧的待确认导入文件
        $previous = $_SESSION['roster_import'] ?? null;
        if (is_array($previous) && ($previous['path'] ?? '') !== $storedPath) {
            $this->discardUpload((string) $previous['path']);
        }
        return $storedPath;
    }

    private function absolutePath(string $storedPath): string
    {
        return rtrim((string) App::config('storage_path'), '/\\') . '/' . $storedPath;
    }

    private function discardUpload(string $storedPath): void
    {
        $absolute = $this->absolutePath($storedPath);
        if (is_file($absolute)) {
            unlink($absolute);
        }
    }
}

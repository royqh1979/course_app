<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CourseRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Services\AuthService;
use App\Support\Session;
use App\Support\Validate;
use PDOException;

final class RosterController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly RosterRepository $roster,
        private readonly CourseRepository $courses,
        private readonly LogRepository $logs,
    ) {
        parent::__construct($auth);
    }

    /** 手工逐条添加名单（Excel 批量导入属第二阶段） */
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
}

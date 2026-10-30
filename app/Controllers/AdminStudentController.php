<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\LogRepository;
use App\Repositories\StudentRepository;
use App\Services\AuthService;
use App\Services\PasswordService;
use App\Support\Pagination;
use App\Support\Session;
use App\Support\View;

/**
 * 教师端学生账号管理：筛选、查看匹配课程、归档/恢复、重置密码、安全删除。
 */
final class AdminStudentController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly StudentRepository $students,
        private readonly LogRepository $logs,
    ) {
        parent::__construct($auth);
    }

    public function index(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);

        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        if (!in_array($status, ['', 'active', 'archived'], true)) {
            $status = '';
        }
        $unmatchedOnly = ($_GET['unmatched'] ?? '') === '1';

        $pagination = Pagination::fromRequest('page', $this->students->countWithFilters($keyword, $status, $unmatchedOnly));
        View::render('admin/students/index', [
            'title' => '学生账号管理',
            'students' => $this->students->listWithFilters($keyword, $status, $unmatchedOnly, $pagination),
            'keyword' => $keyword,
            'status' => $status,
            'unmatchedOnly' => $unmatchedOnly,
            'pagination' => $pagination,
        ]);
    }

    /** 归档：不能登录，历史数据保留 */
    public function archive(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $student = $this->findStudent();
        $this->students->setStatus((int) $student['id'], 'archived');
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'student.archive', 'student', (string) $student['id'], "{$student['student_no']} {$student['name']}", $this->ip());

        Session::flash('success', "已归档学生 {$student['name']}（{$student['student_no']}），该账号不能再登录，历史数据保留。");
        redirect('?r=admin/students');
    }

    /** 恢复已归档账号 */
    public function restore(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $student = $this->findStudent();
        $this->students->setStatus((int) $student['id'], 'active');
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'student.restore', 'student', (string) $student['id'], "{$student['student_no']} {$student['name']}", $this->ip());

        Session::flash('success', "已恢复学生 {$student['name']}（{$student['student_no']}）。");
        redirect('?r=admin/students');
    }

    /** 重置学生密码：生成一次性随机密码，仅展示一次；同时使该学生其他会话失效 */
    public function resetPassword(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $student = $this->findStudent();
        // 随机临时密码：12 位十六进制 + 1 个符号，满足密码强度策略（字母+数字+符号）
        $symbols = '!@#$%^&*-_=+';
        $newPassword = bin2hex(random_bytes(6)) . $symbols[random_int(0, strlen($symbols) - 1)];
        $this->students->changePassword((int) $student['id'], PasswordService::hash($newPassword));

        $this->logs->addAuthLog('student', (int) $student['id'], $student['student_no'], 'password_reset', 'success', $this->ip(), $this->userAgent());
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'student.reset_password', 'student', (string) $student['id'], "{$student['student_no']} {$student['name']}", $this->ip());

        Session::flash('success', "已重置 {$student['name']}（{$student['student_no']}）的密码，新密码：{$newPassword}（仅显示这一次，请转告学生并提醒尽快修改）。");
        redirect('?r=admin/students');
    }

    /** 物理删除：仅允许未匹配任何名单且没有作业提交记录的账号 */
    public function delete(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $student = $this->findStudent();
        if ($this->students->isMatchedToAnyRoster($student['student_no'], $student['name'])) {
            Session::flash('error', "该账号仍匹配课程名单，只能归档，不能删除。");
            redirect('?r=admin/students');
        }
        if ($this->students->countSubmissions((int) $student['id']) > 0) {
            Session::flash('error', '该账号存在作业提交记录，只能归档，不能删除。');
            redirect('?r=admin/students');
        }

        $this->students->delete((int) $student['id']);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'student.delete', 'student', (string) $student['id'], "{$student['student_no']} {$student['name']}", $this->ip());

        Session::flash('success', "已删除学生账号 {$student['name']}（{$student['student_no']}）。");
        redirect('?r=admin/students');
    }

    private function findStudent(): array
    {
        $student = $this->students->findById((int) ($_POST['id'] ?? 0));
        if ($student === null) {
            $this->notFound('学生账号不存在。');
        }
        return $student;
    }
}

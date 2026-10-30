<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\LogRepository;
use App\Repositories\StudentRepository;
use App\Services\AuthService;
use App\Services\PasswordService;
use App\Support\Session;
use App\Support\Validate;
use App\Support\View;
use PDOException;

final class AuthController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly StudentRepository $students,
        private readonly LogRepository $logs,
    ) {
        parent::__construct($auth);
    }

    public function showLogin(): void
    {
        if ($this->auth->currentUser() !== null) {
            redirect('?r=home');
        }
        View::render('auth/login', ['title' => '登录']);
    }

    public function login(): void
    {
        $this->requireCsrf();
        $userType = (string) ($_POST['user_type'] ?? '');
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!in_array($userType, [AuthService::TYPE_ADMIN, AuthService::TYPE_STUDENT], true)
            || $identifier === '' || $password === '') {
            Session::flash('error', '请完整填写登录信息。');
            redirect('?r=login');
        }

        if (!$this->auth->attempt($userType, $identifier, $password, $this->ip(), $this->userAgent())) {
            Session::flash('error', '登录失败：账号或密码错误，或尝试次数过多，请稍后再试。');
            redirect('?r=login');
        }

        redirect($userType === AuthService::TYPE_ADMIN ? '?r=admin/courses' : '?r=student/home');
    }

    public function logout(): void
    {
        $this->requireCsrf();
        $this->auth->logout();
        redirect('?r=login');
    }

    public function showRegister(): void
    {
        if ($this->auth->currentUser() !== null) {
            redirect('?r=home');
        }
        View::render('auth/register', ['title' => '学生注册']);
    }

    /**
     * 学生注册：即使未匹配任何名单也允许创建账号；
     * 注册后不能自行修改学号和姓名，课程权限由实时查询决定。
     */
    public function register(): void
    {
        $this->requireCsrf();

        $studentNo = Validate::cleanStudentNo($_POST['student_no'] ?? '');
        $name = Validate::cleanName($_POST['name'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!Validate::isValidStudentNo($studentNo) || !Validate::isValidName($name)) {
            Session::flash('error', '学号或姓名格式不正确（学号不含空格，最长 32 字符）。');
            redirect('?r=register');
        }
        $policyError = PasswordService::validatePolicy($password);
        if ($policyError !== null) {
            Session::flash('error', $policyError);
            redirect('?r=register');
        }
        if ($password !== $confirm) {
            Session::flash('error', '两次输入的密码不一致。');
            redirect('?r=register');
        }
        if ($this->students->findByStudentNo($studentNo) !== null) {
            $this->logs->addAuthLog(AuthService::TYPE_STUDENT, null, $studentNo, 'register', 'fail', $this->ip(), $this->userAgent());
            Session::flash('error', "学号 {$studentNo} 已注册，请直接登录或联系教师重置密码。");
            redirect('?r=register');
        }

        try {
            $id = $this->students->create($studentNo, $name, PasswordService::hash($password));
        } catch (PDOException) {
            $this->logs->addAuthLog(AuthService::TYPE_STUDENT, null, $studentNo, 'register', 'fail', $this->ip(), $this->userAgent());
            Session::flash('error', '注册失败，请稍后重试。');
            redirect('?r=register');
        }
        $this->logs->addAuthLog(AuthService::TYPE_STUDENT, $id, $studentNo, 'register', 'success', $this->ip(), $this->userAgent());

        Session::flash('success', '注册成功，请使用学号登录。课程权限将根据名单自动匹配。');
        redirect('?r=login');
    }

    public function showChangePassword(): void
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            redirect('?r=login');
        }
        View::render('auth/change_password', ['title' => '修改密码']);
    }

    public function changePassword(): void
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            redirect('?r=login');
        }
        $this->requireCsrf();

        $old = (string) ($_POST['old_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $policyError = PasswordService::validatePolicy($new);
        if ($policyError !== null) {
            Session::flash('error', '新密码不符合要求：' . $policyError);
            redirect('?r=password');
        }
        if ($new !== $confirm) {
            Session::flash('error', '两次输入的新密码不一致。');
            redirect('?r=password');
        }
        if (!$this->auth->changePassword($user['type'], (int) $user['id'], $old, $new, $this->ip(), $this->userAgent())) {
            Session::flash('error', '原密码不正确。');
            redirect('?r=password');
        }

        Session::flash('success', '密码已修改，其他会话已全部退出。');
        redirect('?r=password');
    }
}

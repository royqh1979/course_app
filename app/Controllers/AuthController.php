<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Session;
use App\Support\View;

final class AuthController extends BaseController
{
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

        if (mb_strlen($new) < 8) {
            Session::flash('error', '新密码长度至少 8 位。');
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

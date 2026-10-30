<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\LogRepository;
use App\Services\AuthService;
use App\Support\Pagination;
use App\Support\View;

/**
 * 教师端日志查看：认证日志（登录/退出/密码操作）与审计日志（关键管理操作）。
 * 日志不记录密码、密码哈希或完整 Session，页面直接展示原始字段。
 */
final class LogController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly LogRepository $logs,
    ) {
        parent::__construct($auth);
    }

    public function auth(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);

        $userType = (string) ($_GET['user_type'] ?? '');
        if (!in_array($userType, ['', 'admin', 'student'], true)) {
            $userType = '';
        }
        $action = (string) ($_GET['action'] ?? '');
        if (!in_array($action, ['', 'login', 'logout', 'register', 'password_change', 'password_reset'], true)) {
            $action = '';
        }

        $pagination = Pagination::fromRequest('page', $this->logs->countAuthLogs($userType, $action));
        View::render('admin/logs/auth', [
            'title' => '登录与密码日志',
            'logs' => $this->logs->listAuthLogs($userType, $action, $pagination),
            'userType' => $userType,
            'action' => $action,
            'pagination' => $pagination,
        ]);
    }

    public function audit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);

        $action = (string) ($_GET['action'] ?? '');
        $pagination = Pagination::fromRequest('page', $this->logs->countAuditLogs($action));
        View::render('admin/logs/audit', [
            'title' => '管理操作日志',
            'logs' => $this->logs->listAuditLogs($action, $pagination),
            'action' => $action,
            'actions' => $this->logs->distinctAuditActions(),
            'pagination' => $pagination,
        ]);
    }
}

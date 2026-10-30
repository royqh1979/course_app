<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\Csrf;
use App\Support\View;

abstract class BaseController
{
    public function __construct(protected readonly AuthService $auth)
    {
    }

    /** 要求已登录且角色匹配，否则跳回登录页 */
    protected function requireUser(string $type): array
    {
        $user = $this->auth->currentUser();
        if ($user === null || $user['type'] !== $type) {
            redirect('?r=login');
        }
        return $user;
    }

    protected function requireCsrf(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->forbidden('CSRF 校验失败，请返回上一页重试。');
        }
    }

    protected function forbidden(string $message = '没有访问权限。'): void
    {
        http_response_code(403);
        View::render('error', ['title' => '403', 'code' => 403, 'message' => $message]);
        exit;
    }

    protected function notFound(string $message = '内容不存在。'): void
    {
        http_response_code(404);
        View::render('error', ['title' => '404', 'code' => 404, 'message' => $message]);
        exit;
    }

    protected function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /** 是否 XHR 上传请求（upload.js 带 X-Requested-With 头） */
    protected function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** XHR 上传的 JSON 响应：ok + success/error 消息 + 可选 redirect */
    protected function jsonResponse(bool $ok, string $message, ?string $redirect = null): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $data = ['ok' => $ok];
        $data[$ok ? 'success' : 'error'] = $message;
        if ($redirect !== null) {
            $data['redirect'] = $redirect;
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}

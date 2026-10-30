<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\QrCodeService;
use App\Support\App;
use App\Support\View;

/**
 * 注册入口二维码：教师投屏或打印，学生用手机/微信扫码打开注册页。
 */
final class QrCodeController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly QrCodeService $qrCodes = new QrCodeService(),
    ) {
        parent::__construct($auth);
    }

    public function show(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);

        $registerUrl = $this->baseUrl() . '/?r=register';
        View::render('admin/qrcode', [
            'title' => '学生注册二维码',
            'registerUrl' => $registerUrl,
            'qrSvg' => $this->qrCodes->svg($registerUrl),
        ]);
    }

    /** 站点对外地址：优先配置 app.base_url，否则按当前请求推导 */
    private function baseUrl(): string
    {
        $configured = trim((string) App::config('app.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . $host;
    }
}

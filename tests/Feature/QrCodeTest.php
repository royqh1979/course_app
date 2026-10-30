<?php

declare(strict_types=1);

namespace App\Tests\Feature;

/**
 * 注册二维码与全站响应式/资源本地化：
 * 二维码页面权限、APP_BASE_URL 生效、响应式导航、页面零外部资源引用。
 */
final class QrCodeTest extends FeatureTestCase
{
    /** 注入对外地址，验证二维码内容跟随配置 */
    protected static function serverEnv(): array
    {
        return parent::serverEnv() + ['APP_BASE_URL' => 'http://test.example:8080'];
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    public function testQrCodePageRequiresAdmin(): void
    {
        $guest = new HttpClient(self::$baseUrl);
        $res = $guest->get('/?r=admin/qrcode');
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');

        $this->createStudent();
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $res = $student->get('/?r=admin/qrcode');
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testQrCodePageShowsSvgAndRegisterUrl(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $res = $client->get('/?r=admin/qrcode');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<svg', $res->body, '页面应内嵌二维码 SVG');
        $this->assertStringContainsString('http://test.example:8080/?r=register', $res->body, '二维码目标应使用配置的 APP_BASE_URL');
    }

    public function testResponsiveNavbarAndViewport(): void
    {
        $client = new HttpClient(self::$baseUrl);

        $login = $client->get('/?r=login');
        $this->assertStringContainsString('width=device-width', $login->body, '登录页必须含 viewport（移动端适配基础）');
        $this->assertStringContainsString('assets/vendor/bootstrap/bootstrap.min.css', $login->body, '样式必须引用本地 Bootstrap');

        $this->loginAdmin($client);
        $admin = $client->get('/?r=admin/courses');
        $this->assertStringContainsString('navbar-toggler', $admin->body, '导航栏在窄屏必须可折叠（汉堡菜单）');
        $this->assertStringContainsString('assets/vendor/bootstrap/bootstrap.bundle.min.js', $admin->body);

        $this->createStudent();
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $home = $student->get('/?r=student/home');
        $this->assertStringContainsString('navbar-toggler', $home->body);
    }

    public function testPagesContainNoExternalResourceUrls(): void
    {
        $client = new HttpClient(self::$baseUrl);

        foreach (['/?r=login', '/?r=register'] as $path) {
            $body = $client->get($path)->body;
            $this->assertStringNotContainsString('http://', $body, "{$path} 不得引用外部资源");
            $this->assertStringNotContainsString('https://', $body, "{$path} 不得引用外部资源");
        }

        $this->loginAdmin($client);
        foreach (['/?r=admin/courses', '/?r=admin/students'] as $path) {
            $body = $client->get($path)->body;
            $this->assertStringNotContainsString('http://', $body, "{$path} 不得引用外部资源");
            $this->assertStringNotContainsString('https://', $body, "{$path} 不得引用外部资源");
        }

        $this->createStudent();
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $body = $student->get('/?r=student/home')->body;
        $this->assertStringNotContainsString('http://', $body);
        $this->assertStringNotContainsString('https://', $body);
    }
}

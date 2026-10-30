<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\StudentRepository;
use App\Support\Database;

/**
 * 登录/退出流程（验收标准 1、5、13 的 HTTP 层验证）。
 */
final class AuthFlowTest extends FeatureTestCase
{
    public function testLoginPageRenders(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $res = $client->get('/?r=login');

        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('登录', $res->body);
        $this->assertNotNull($res->csrfToken());
    }

    public function testAdminLoginRedirectsToCourseManagement(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);

        $res = $this->login($client, 'admin', 'teacher', 'admin-pass-123');

        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=admin/courses', $res->header('location') ?? '');

        $home = $client->get('/?r=admin/courses');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('课程管理', $home->body);
    }

    public function testSessionIdRegeneratedOnLogin(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);

        $client->get('/?r=login');
        $before = $client->cookies['COURSESESSID'] ?? null;
        $this->assertNotNull($before);

        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
        $after = $client->cookies['COURSESESSID'] ?? null;

        $this->assertNotNull($after);
        $this->assertNotSame($before, $after, '登录成功后必须重新生成 Session ID');
    }

    public function testWrongPasswordRejected(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);

        $res = $this->login($client, 'admin', 'teacher', 'wrong-password');

        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testArchivedStudentCannotLogin(): void
    {
        $id = $this->createStudent();
        (new StudentRepository())->setStatus($id, 'archived');

        $client = new HttpClient(self::$baseUrl);
        $res = $this->login($client, 'student', '2024001', 'student-pass-123');

        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');

        $row = Database::pdo()->query("SELECT result FROM auth_logs WHERE action='login' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('archived', $row['result']);
    }

    public function testRateLimitedAfterTooManyFailures(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);

        for ($i = 0; $i < 5; $i++) {
            $this->login($client, 'admin', 'teacher', 'wrong-' . $i);
        }

        // 第 6 次即使密码正确也被限流
        $res = $this->login($client, 'admin', 'teacher', 'admin-pass-123');
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');

        $row = Database::pdo()->query("SELECT result FROM auth_logs ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('rate_limited', $row['result']);
    }

    public function testLogoutDestroysSession(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');

        $page = $client->get('/?r=admin/courses');
        $res = $client->post('/?r=logout', ['_csrf' => $page->csrfToken() ?? '']);
        $this->assertSame(302, $res->status);

        $after = $client->get('/?r=admin/courses');
        $this->assertStringContainsString('r=login', $after->header('location') ?? '', '退出后应跳回登录页');
    }
}

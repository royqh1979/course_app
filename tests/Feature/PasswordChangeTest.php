<?php

declare(strict_types=1);

namespace App\Tests\Feature;

/**
 * 修改密码：需验原密码；改后其他会话失效，当前会话保留。
 */
final class PasswordChangeTest extends FeatureTestCase
{
    public function testChangePasswordInvalidatesOtherSessions(): void
    {
        $this->createStudent();

        // 同一学生的两个独立会话
        $sessionA = new HttpClient(self::$baseUrl);
        $sessionB = new HttpClient(self::$baseUrl);
        $this->login($sessionA, 'student', '2024001', 'student-pass-123');
        $this->login($sessionB, 'student', '2024001', 'student-pass-123');
        $this->assertSame(200, $sessionB->get('/?r=student/home')->status);

        // 会话 A 修改密码
        $page = $sessionA->get('/?r=password');
        $res = $sessionA->post('/?r=password', [
            '_csrf' => $page->csrfToken() ?? '',
            'old_password' => 'student-pass-123',
            'new_password' => 'new-pass-45678',
            'confirm_password' => 'new-pass-45678',
        ]);
        $this->assertSame(302, $res->status);

        // 当前会话 A 保持有效，会话 B 被强制退出
        $this->assertSame(200, $sessionA->get('/?r=student/home')->status);
        $b = $sessionB->get('/?r=student/home');
        $this->assertStringContainsString('r=login', $b->header('location') ?? '', '改密后其他会话必须失效');

        // 新密码可登录
        $sessionC = new HttpClient(self::$baseUrl);
        $res = $this->login($sessionC, 'student', '2024001', 'new-pass-45678');
        $this->assertStringContainsString('r=student/home', $res->header('location') ?? '');
    }

    public function testChangePasswordWithWrongOldPasswordRejected(): void
    {
        $this->createStudent();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $page = $client->get('/?r=password');
        $client->post('/?r=password', [
            '_csrf' => $page->csrfToken() ?? '',
            'old_password' => 'wrong-old',
            'new_password' => 'new-pass-45678',
            'confirm_password' => 'new-pass-45678',
        ]);

        // 旧密码仍有效，说明密码未被修改
        $check = new HttpClient(self::$baseUrl);
        $res = $this->login($check, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('r=student/home', $res->header('location') ?? '');
    }

    public function testChangePasswordRejectsWeakNewPassword(): void
    {
        $this->createStudent();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $page = $client->get('/?r=password');
        $this->assertStringContainsString('大写、小写、数字、符号中的至少三类', $page->body, '改密页须有密码要求提示');
        $res = $client->post('/?r=password', [
            '_csrf' => $page->csrfToken() ?? '',
            'old_password' => 'student-pass-123',
            'new_password' => 'abcd1234',
            'confirm_password' => 'abcd1234',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=password', $res->header('location') ?? '');

        // 弱新密码被拒，原密码不变
        $check = new HttpClient(self::$baseUrl);
        $login = $this->login($check, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('r=student/home', $login->header('location') ?? '');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AdminRepository;
use App\Repositories\LogRepository;
use App\Repositories\StudentRepository;
use App\Services\AuthService;
use App\Services\PasswordService;
use App\Services\RateLimiter;

final class AuthServiceTest extends DatabaseTestCase
{
    private AuthService $auth;
    private StudentRepository $students;

    protected function setUp(): void
    {
        parent::setUp();
        $logs = new LogRepository();
        $this->students = new StudentRepository();
        $this->auth = new AuthService(new AdminRepository(), $this->students, $logs, new RateLimiter($logs, 5, 600));

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    public function testAttemptSuccessWritesSession(): void
    {
        $id = $this->students->create('2024001', '张三', PasswordService::hash('password-123'));

        $ok = $this->auth->attempt('student', '2024001', 'password-123', '10.0.0.1', 'TestAgent');

        $this->assertTrue($ok);
        $this->assertSame($id, $_SESSION['user']['id']);
        $this->assertSame('student', $_SESSION['user']['type']);
        $this->assertSame('张三', $_SESSION['user']['display_name']);
    }

    public function testAttemptRejectsWrongPassword(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));

        $this->assertFalse($this->auth->attempt('student', '2024001', 'wrong', '10.0.0.1', ''));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testArchivedStudentCannotLogin(): void
    {
        $id = $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $this->students->setStatus($id, 'archived');

        $this->assertFalse($this->auth->attempt('student', '2024001', 'password-123', '10.0.0.1', ''));

        $row = $this->pdo->query("SELECT result FROM auth_logs WHERE action = 'login' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('archived', $row['result']);
    }

    public function testRateLimitedAfterTooManyFailures(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('student', '2024001', 'wrong', '10.0.0.1', '');
        }

        $this->assertFalse(
            $this->auth->attempt('student', '2024001', 'password-123', '10.0.0.1', ''),
            '达到失败上限后即使密码正确也应被拒绝'
        );
        $row = $this->pdo->query("SELECT result FROM auth_logs ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('rate_limited', $row['result']);
    }

    public function testChangePasswordInvalidatesOtherSessions(): void
    {
        $id = $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $this->auth->attempt('student', '2024001', 'password-123', '10.0.0.1', '');

        $ok = $this->auth->changePassword('student', $id, 'password-123', 'new-password-123', '10.0.0.1', '');
        $this->assertTrue($ok);
        // 当前会话保持有效
        $this->assertNotNull($this->auth->currentUser());

        // 模拟旧会话（改密前的 session_version）
        $_SESSION['user']['session_version'] -= 1;
        $this->assertNull($this->auth->currentUser(), '旧会话应在改密后失效');
    }

    public function testChangePasswordRequiresCorrectOldPassword(): void
    {
        $id = $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $this->auth->attempt('student', '2024001', 'password-123', '10.0.0.1', '');

        $this->assertFalse($this->auth->changePassword('student', $id, 'wrong', 'new-password-123', '10.0.0.1', ''));
    }

    public function testAuthLogsNeverContainPassword(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $this->auth->attempt('student', '2024001', 'password-123', '10.0.0.1', '');
        $this->auth->attempt('student', '2024001', 'wrong-pass', '10.0.0.1', '');

        $rows = $this->pdo->query('SELECT * FROM auth_logs')->fetchAll();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $serialized = json_encode($row, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('password-123', $serialized);
            $this->assertStringNotContainsString('wrong-pass', $serialized);
            $this->assertStringNotContainsString('$2y$', $serialized, '日志不得包含密码哈希');
        }
    }
}

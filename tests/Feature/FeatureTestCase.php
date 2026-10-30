<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\AdminRepository;
use App\Repositories\StudentRepository;
use App\Services\PasswordService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * HTTP 功能测试基类：启动 PHP 内置服务器（连接测试库），
 * 每个用例前清空业务表。
 */
abstract class FeatureTestCase extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 8123;

    /** @var resource|null */
    private static $serverProcess = null;

    protected static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = 'http://' . self::HOST . ':' . self::PORT;

        $docroot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public';
        $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'course_app_test_server.log';
        $env = array_merge(getenv(), static::serverEnv());

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', self::HOST . ':' . self::PORT, '-t', $docroot],
            $descriptors,
            $pipes,
            null,
            $env
        );

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen(self::HOST, self::PORT);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('测试服务器启动失败，日志：' . $logFile);
    }

    /** 测试服务器环境变量；子类可覆盖以注入额外配置（如 APP_BASE_URL） */
    protected static function serverEnv(): array
    {
        return [
            'APP_ENV' => 'test',
            'DB_NAME' => 'class_app_test',
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

    protected function setUp(): void
    {
        $pdo = Database::pdo();
        foreach (['auth_logs', 'audit_logs', 'submissions', 'assignment_attachments', 'assignments', 'handouts', 'files', 'course_roster', 'courses', 'students', 'admins'] as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
    }

    protected function createAdmin(string $username = 'teacher', string $password = 'admin-pass-123'): int
    {
        return (new AdminRepository())->create($username, PasswordService::hash($password));
    }

    protected function createStudent(string $studentNo = '2024001', string $name = '张三', string $password = 'student-pass-123'): int
    {
        return (new StudentRepository())->create($studentNo, $name, PasswordService::hash($password));
    }

    /** 走完整登录流程（先取登录页拿 CSRF，再 POST），返回登录响应 */
    protected function login(HttpClient $client, string $userType, string $identifier, string $password): HttpResponse
    {
        $page = $client->get('/?r=login');
        return $client->post('/?r=login', [
            '_csrf' => $page->csrfToken() ?? '',
            'user_type' => $userType,
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;

/**
 * 学生注册（第二阶段）：开放注册、格式与唯一性校验、注册后即时获得课程权限。
 */
final class RegistrationTest extends FeatureTestCase
{
    /** 走完整注册流程，返回注册响应 */
    private function register(HttpClient $client, string $studentNo, string $name, string $password, string $confirm = ''): HttpResponse
    {
        $page = $client->get('/?r=register');
        return $client->post('/?r=register', [
            '_csrf' => $page->csrfToken() ?? '',
            'student_no' => $studentNo,
            'name' => $name,
            'password' => $password,
            'confirm_password' => $confirm === '' ? $password : $confirm,
        ]);
    }

    public function testRegisterPageAccessible(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $res = $client->get('/?r=register');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('学生注册', $res->body);
    }

    public function testStudentCanRegisterAndLogin(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $res = $this->register($client, '2024001', '张三', 'student-pass-123');
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');

        $this->assertNotNull((new StudentRepository())->findByStudentNo('2024001'));

        $login = $this->login($client, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('r=student/home', $login->header('location') ?? '');
        $this->assertSame(200, $client->get('/?r=student/home')->status);
    }

    public function testRegisteredStudentGetsCourseAccessImmediately(): void
    {
        // 名单先存在，学生后注册：注册后无需任何匹配操作即可访问课程
        $courses = new CourseRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');

        $client = new HttpClient(self::$baseUrl);
        $this->register($client, '2024001', '张三', 'student-pass-123');
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $this->assertSame(200, $client->get('/?r=student/course&id=' . $courseId)->status);
    }

    public function testDuplicateStudentNoRejected(): void
    {
        $this->createStudent();

        $client = new HttpClient(self::$baseUrl);
        $res = $this->register($client, '2024001', '李四', 'student-pass-123');
        $this->assertStringContainsString('r=register', $res->header('location') ?? '');

        $page = $client->get('/?r=register');
        $this->assertStringContainsString('已注册', $page->body);
    }

    public function testShortPasswordAndMismatchRejected(): void
    {
        $client = new HttpClient(self::$baseUrl);

        $this->register($client, '2024001', '张三', 'short');
        $this->assertNull((new StudentRepository())->findByStudentNo('2024001'), '密码不足 8 位不得注册成功');

        $this->register($client, '2024001', '张三', 'student-pass-123', 'different-pass');
        $this->assertNull((new StudentRepository())->findByStudentNo('2024001'), '两次密码不一致不得注册成功');
    }

    public function testRegisterRequiresCsrf(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $res = $client->post('/?r=register', [
            'student_no' => '2024001',
            'name' => '张三',
            'password' => 'student-pass-123',
            'confirm_password' => 'student-pass-123',
        ]);
        $this->assertSame(403, $res->status);
        $this->assertNull((new StudentRepository())->findByStudentNo('2024001'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;

/**
 * 服务端权限复检（验收标准 2、3、4）。
 */
final class PermissionTest extends FeatureTestCase
{
    public function testStudentSeesOnlyMatchedCourses(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $matchedId = $courses->create('数据结构', null, '2025-2026-1', null);
        $courses->create('操作系统', null, '2025-2026-1', null);
        $roster->add($matchedId, '2024001', '张三', '计科2401');
        $this->createStudent();

        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $home = $client->get('/?r=student/home');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('数据结构', $home->body);
        $this->assertStringNotContainsString('操作系统', $home->body);
    }

    public function testUnmatchedStudentSeesEmptyNotice(): void
    {
        $this->createStudent();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $home = $client->get('/?r=student/home');
        $this->assertStringContainsString('暂未匹配到课程名单', $home->body);
    }

    public function testStudentCannotAccessOtherCourseByDirectUrl(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $ownId = $courses->create('数据结构', null, '', null);
        $otherId = $courses->create('操作系统', null, '', null);
        $roster->add($ownId, '2024001', '张三', '');
        $this->createStudent();

        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $this->assertSame(200, $client->get('/?r=student/course&id=' . $ownId)->status);
        $this->assertSame(403, $client->get('/?r=student/course&id=' . $otherId)->status, '直接输入他人课程 URL 必须被服务端拒绝');
    }

    public function testRosterGrantAndRevokeTakeEffectImmediatelyOverHttp(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $this->createStudent();

        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');
        $this->assertSame(403, $client->get('/?r=student/course&id=' . $courseId)->status);

        // 加入名单：无需任何匹配操作，下次请求即生效
        $rosterId = $roster->add($courseId, '2024001', '张三', '');
        $this->assertSame(200, $client->get('/?r=student/course&id=' . $courseId)->status);

        // 移出名单：权限立即失效
        $roster->remove($rosterId);
        $this->assertSame(403, $client->get('/?r=student/course&id=' . $courseId)->status);
    }

    public function testGuestRedirectedToLogin(): void
    {
        $client = new HttpClient(self::$baseUrl);

        $res = $client->get('/?r=admin/courses');
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');

        $res = $client->get('/?r=student/home');
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testStudentCannotOpenAdminPages(): void
    {
        $this->createStudent();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', '2024001', 'student-pass-123');

        $res = $client->get('/?r=admin/courses');
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testAdminCourseCrudOverHttp(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');

        // 新建课程
        $form = $client->get('/?r=admin/courses/create');
        $res = $client->post('/?r=admin/courses/store', [
            '_csrf' => $form->csrfToken() ?? '',
            'name' => '数据结构',
            'code' => 'CS101',
            'term' => '2025-2026-1',
            'description' => '课程简介',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=admin/courses/show', $res->header('location') ?? '');

        preg_match('/id=(\d+)/', $res->header('location') ?? '', $m);
        $courseId = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $courseId);

        // 添加名单
        $page = $client->get('/?r=admin/courses/show&id=' . $courseId);
        $res = $client->post('/?r=admin/roster/add', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'student_no' => '2024001',
            'name' => '张三',
            'class_name' => '计科2401',
        ]);
        $this->assertSame(302, $res->status);

        $page = $client->get('/?r=admin/courses/show&id=' . $courseId);
        $this->assertStringContainsString('2024001', $page->body);
        $this->assertStringContainsString('张三', $page->body);

        // 归档课程
        $res = $client->post('/?r=admin/courses/archive', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $courseId,
        ]);
        $this->assertSame(302, $res->status);
        $course = (new CourseRepository())->findById($courseId);
        $this->assertSame('archived', $course['status']);
    }
}

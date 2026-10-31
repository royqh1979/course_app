<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;

/**
 * 分页 HTTP 流程：页码导航、每页条数（手机 20 / 电脑 50 / 显式 per_page）、
 * 筛选参数保留、课程详情三表独立页码参数。
 */
final class PaginationFlowTest extends FeatureTestCase
{
    private const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    private function makeCourses(int $count): void
    {
        $courses = new CourseRepository();
        for ($i = 1; $i <= $count; $i++) {
            $courses->create('课程' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), null, '', null);
        }
    }

    public function testCourseListMobileDefaultsToTwentyPerPage(): void
    {
        $this->makeCourses(25);
        $client = new HttpClient(self::$baseUrl);
        $client->userAgent = self::MOBILE_UA;
        $this->loginAdmin($client);

        $page1 = $client->get('/?r=admin/courses');
        $this->assertStringContainsString('课程25', $page1->body);
        $this->assertStringNotContainsString('课程01', $page1->body, '手机端默认每页 20 条，最早课程应在第二页');
        $this->assertStringContainsString('共 25 条', $page1->body);

        $page2 = $client->get('/?r=admin/courses&page=2');
        $this->assertStringContainsString('课程01', $page2->body);
        $this->assertStringNotContainsString('课程25', $page2->body);
    }

    public function testCourseListDesktopDefaultsToFiftyPerPage(): void
    {
        $this->makeCourses(25);
        $client = new HttpClient(self::$baseUrl); // 不设 UA，视为电脑
        $this->loginAdmin($client);

        $page1 = $client->get('/?r=admin/courses');
        $this->assertStringContainsString('课程25', $page1->body);
        $this->assertStringContainsString('课程01', $page1->body, '电脑端默认每页 50 条，25 门课程一页显示完');
    }

    public function testExplicitPerPageOverridesDeviceDefault(): void
    {
        $this->makeCourses(25);
        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $page1 = $client->get('/?r=admin/courses&per_page=10');
        $this->assertStringContainsString('课程25', $page1->body);
        $this->assertStringNotContainsString('课程15', $page1->body, 'per_page=10 时首页只有最新 10 门');
        $this->assertStringContainsString('per_page=10', $page1->body, '翻页链接应保留 per_page');

        $page3 = $client->get('/?r=admin/courses&per_page=10&page=3');
        $this->assertStringContainsString('课程01', $page3->body);
    }

    public function testStudentListPaginationKeepsFilters(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->createStudent('2024' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '学生' . $i);
        }
        $this->createStudent('2023999', '老生');

        $client = new HttpClient(self::$baseUrl);
        $client->userAgent = self::MOBILE_UA;
        $this->loginAdmin($client);

        $page1 = $client->get('/?r=admin/students&keyword=2024');
        $this->assertStringContainsString('共 30 条', $page1->body);
        $this->assertStringContainsString('keyword=2024', $page1->body, '翻页链接应保留筛选关键字');
        $this->assertStringNotContainsString('2023001', $page1->body);

        $page2 = $client->get('/?r=admin/students&keyword=2024&page=2');
        $this->assertSame(200, $page2->status);
        $this->assertStringContainsString('2024030', $page2->body, '按学号升序，第二页为 2024021–2024030');
        $this->assertStringNotContainsString('2024001', $page2->body, '筛选条件在第二页仍生效');
        $this->assertStringNotContainsString('2023999', $page2->body, '筛选条件在第二页仍生效');
    }

    public function testCourseShowTablesPaginateIndependently(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $assignments = new AssignmentRepository();
        $courseId = $courses->create('数据结构', null, '', null);

        for ($i = 1; $i <= 25; $i++) {
            $roster->add($courseId, '2024' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '学生' . $i, '计科2401');
            $assignments->create($courseId, [
                'title' => '作业' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'requirements' => null,
                'start_at' => null,
                'deadline_at' => null,
                'allowed_extensions' => 'zip',
                'max_file_size' => 1024,
                'allow_late' => true,
            ]);
        }

        $client = new HttpClient(self::$baseUrl);
        $client->userAgent = self::MOBILE_UA;
        $this->loginAdmin($client);

        // 作业页第一页：按标题排序后最前的 20 个（作业01–作业20）
        $page1 = $client->get('/?r=admin/courses/assignments&id=' . $courseId);
        $this->assertSame(200, $page1->status);
        $this->assertStringContainsString('作业01', $page1->body);
        $this->assertStringNotContainsString('作业21', $page1->body);

        // 作业页第二页（page=2）：按标题排序为 作业21–作业25
        $page = $client->get('/?r=admin/courses/assignments&id=' . $courseId . '&page=2');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('作业25', $page->body);
        $this->assertStringNotContainsString('作业01', $page->body);

        // 名单页第一页：最早学号在前
        $page = $client->get('/?r=admin/courses/roster&id=' . $courseId);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('2024001', $page->body);
        $this->assertStringNotContainsString('2024021', $page->body);

        // 名单页第二页：学号 2024021 出现
        $page = $client->get('/?r=admin/courses/roster&id=' . $courseId . '&page=2');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('2024021', $page->body);
        $this->assertStringNotContainsString('2024001', $page->body);
    }

    public function testOutOfRangePageClampsToLast(): void
    {
        $this->makeCourses(5);
        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $res = $client->get('/?r=admin/courses&page=99');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('课程01', $res->body, '越界页码回落到最后一页');
    }
}

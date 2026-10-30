<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SubmissionRepository;
use App\Services\PasswordService;
use App\Support\Pagination;

/**
 * Repository 分页与 count：切片正确、与总数一致、筛选条件同步生效。
 */
final class PaginationTest extends DatabaseTestCase
{
    public function testCoursePagination(): void
    {
        $courses = new CourseRepository();
        for ($i = 1; $i <= 25; $i++) {
            $courses->create("课程{$i}", null, '', null);
        }
        $this->assertSame(25, $courses->countAll());

        $page1 = $courses->listAll(new Pagination(1, 20, 25));
        $page2 = $courses->listAll(new Pagination(2, 20, 25));
        $this->assertCount(20, $page1);
        $this->assertCount(5, $page2);
        $this->assertSame('课程25', $page1[0]['name'], '按 id 倒序，最新在前');
        $this->assertSame('课程1', $page2[4]['name']);
    }

    public function testStudentFilterCountMatchesSlice(): void
    {
        $students = new StudentRepository();
        for ($i = 1; $i <= 30; $i++) {
            $students->create('2024' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '学生' . $i, PasswordService::hash('password-123'));
        }
        $students->create('2023999', '老生', PasswordService::hash('password-123'));

        $this->assertSame(31, $students->countWithFilters());
        $this->assertSame(30, $students->countWithFilters('2024'));

        $page1 = $students->listWithFilters('2024', '', false, new Pagination(1, 20, 30));
        $page2 = $students->listWithFilters('2024', '', false, new Pagination(2, 20, 30));
        $this->assertCount(20, $page1);
        $this->assertCount(10, $page2);
        foreach (array_merge($page1, $page2) as $row) {
            $this->assertStringContainsString('2024', $row['student_no']);
        }
    }

    public function testRosterPagination(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        for ($i = 1; $i <= 25; $i++) {
            $roster->add($courseId, '2024' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '学生' . $i, '计科2401');
        }

        $this->assertSame(25, $roster->countByCourse($courseId));
        $page1 = $roster->listByCourse($courseId, new Pagination(1, 20, 25));
        $page2 = $roster->listByCourse($courseId, new Pagination(2, 20, 25));
        $this->assertCount(20, $page1);
        $this->assertCount(5, $page2);
        $this->assertSame('2024001', $page1[0]['student_no'], '名单按学号排序');
    }

    public function testAssignmentAndSubmissionPagination(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $submissions = new SubmissionRepository();
        $students = new StudentRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        for ($i = 1; $i <= 3; $i++) {
            $assignments->create($courseId, [
                'title' => "作业{$i}",
                'requirements' => null,
                'start_at' => null,
                'deadline_at' => null,
                'allowed_extensions' => 'zip',
                'max_file_size' => 1024,
                'allow_late' => true,
            ]);
        }
        $this->assertSame(3, $assignments->countByCourse($courseId));
        $this->assertCount(2, $assignments->listByCourse($courseId, false, new Pagination(1, 2, 3)));

        // 25 名学生提交同一作业
        $assignmentIds = array_column($assignments->listByCourse($courseId), 'id');
        $assignmentId = (int) $assignmentIds[0];
        for ($i = 1; $i <= 25; $i++) {
            $sid = $students->create('2024' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '学生' . $i, PasswordService::hash('p' . $i . '-pass-123'));
            $fileId = $files->create([
                'original_name' => 'a.zip',
                'stored_path' => 'courses/1/x/' . bin2hex(random_bytes(8)) . '.zip',
                'extension' => 'zip',
                'mime_type' => 'application/zip',
                'size' => 1,
                'sha256' => str_repeat('a', 64),
            ]);
            $submissions->insert($assignmentId, $sid, $fileId);
        }

        $this->assertSame(25, $submissions->countByAssignment($assignmentId));
        $page1 = $submissions->listByAssignment($assignmentId, new Pagination(1, 20, 25));
        $page2 = $submissions->listByAssignment($assignmentId, new Pagination(2, 20, 25));
        $this->assertCount(20, $page1);
        $this->assertCount(5, $page2);
        $this->assertSame('2024001', $page1[0]['student_no']);
        $this->assertSame('2024021', $page2[0]['student_no']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Services\PasswordService;

/**
 * 学生账号管理的数据层：筛选、未匹配查询（NOT EXISTS）、匹配课程、删除约束依据。
 */
final class StudentManagementTest extends DatabaseTestCase
{
    private StudentRepository $students;
    private CourseRepository $courses;
    private RosterRepository $roster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->students = new StudentRepository();
        $this->courses = new CourseRepository();
        $this->roster = new RosterRepository();
    }

    public function testListWithFiltersByKeywordAndStatus(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $id2 = $this->students->create('2024002', '李四', PasswordService::hash('password-123'));
        $this->students->setStatus($id2, 'archived');

        $this->assertCount(2, $this->students->listWithFilters());
        $this->assertCount(1, $this->students->listWithFilters('张三'));
        $this->assertCount(1, $this->students->listWithFilters('2024002'));
        $this->assertCount(1, $this->students->listWithFilters('', 'archived'));
        $this->assertSame('李四', $this->students->listWithFilters('', 'archived')[0]['name']);
    }

    public function testUnmatchedFilterUsesNotExistsOnStudentNoAndName(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $this->students->create('2024002', '李四', PasswordService::hash('password-123'));
        // 与名单中学号相同但姓名不同的账号，不算匹配
        $this->students->create('2024003', '张三丰', PasswordService::hash('password-123'));

        $courseId = $this->courses->create('数据结构', null, '', null);
        $this->roster->add($courseId, '2024001', '张三', '计科2401');
        $this->roster->add($courseId, '2024003', '张小三', '计科2401');

        $unmatched = $this->students->listWithFilters('', '', true);
        $names = array_column($unmatched, 'name');
        sort($names);
        $this->assertSame(['张三丰', '李四'], $names, '仅学号一致但姓名不一致的账号仍属未匹配');
    }

    public function testMatchedCoursesAndMatchedCoursesColumn(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $id1 = $this->courses->create('数据结构', null, '', null);
        $id2 = $this->courses->create('操作系统', null, '', null);
        $this->roster->add($id1, '2024001', '张三', '');
        $this->roster->add($id2, '2024001', '张三', '');

        $matched = $this->students->matchedCourses('2024001', '张三');
        $this->assertCount(2, $matched);

        $list = $this->students->listWithFilters('2024001');
        $this->assertSame('数据结构、操作系统', $list[0]['matched_courses']);
    }

    public function testIsMatchedToAnyRoster(): void
    {
        $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $courseId = $this->courses->create('数据结构', null, '', null);

        $this->assertFalse($this->students->isMatchedToAnyRoster('2024001', '张三'));
        $this->roster->add($courseId, '2024001', '张三', '');
        $this->assertTrue($this->students->isMatchedToAnyRoster('2024001', '张三'));
        $this->assertFalse($this->students->isMatchedToAnyRoster('2024001', '张三丰'));
    }

    public function testCountSubmissionsAndDelete(): void
    {
        $id = $this->students->create('2024001', '张三', PasswordService::hash('password-123'));
        $this->assertSame(0, $this->students->countSubmissions($id));

        $this->pdo->prepare('INSERT INTO submissions (assignment_id, student_id, file_id) VALUES (1, :id, 1)')
            ->execute(['id' => $id]);
        $this->assertSame(1, $this->students->countSubmissions($id));

        $this->students->delete($id);
        $this->assertNull($this->students->findById($id));
    }

    public function testRegistrationKeepsLeadingZeroStudentNo(): void
    {
        $id = $this->students->create('00123', '张三', PasswordService::hash('password-123'));
        $this->assertSame('00123', $this->students->findById($id)['student_no']);
    }
}

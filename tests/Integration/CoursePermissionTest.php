<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;

/**
 * 实时课程权限查询（验收标准 2、3）：
 * 学号与姓名必须同时与名单一致；名单变动即时生效，无需额外匹配操作。
 */
final class CoursePermissionTest extends DatabaseTestCase
{
    private CourseRepository $courses;
    private RosterRepository $roster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->courses = new CourseRepository();
        $this->roster = new RosterRepository();
    }

    public function testStudentNoAndNameMustBothMatch(): void
    {
        $courseId = $this->courses->create('数据结构', 'CS101', '2025-2026-1', null);
        $this->roster->add($courseId, '2024001', '张三', '计科2401');

        $this->assertCount(1, $this->courses->listActiveByStudent('2024001', '张三'), '学号姓名都一致应可见');
        $this->assertSame([], $this->courses->listActiveByStudent('2024001', '李四'), '仅学号一致不可见');
        $this->assertSame([], $this->courses->listActiveByStudent('2024002', '张三'), '仅姓名一致不可见');
        $this->assertSame([], $this->courses->listActiveByStudent('2024002', '李四'), '都不一致不可见');
    }

    public function testStudentCanAccessChecksBothFields(): void
    {
        $courseId = $this->courses->create('数据结构', null, '', null);
        $this->roster->add($courseId, '2024001', '张三', '');

        $this->assertTrue($this->courses->studentCanAccess($courseId, '2024001', '张三'));
        $this->assertFalse($this->courses->studentCanAccess($courseId, '2024001', '李四'));
        $this->assertFalse($this->courses->studentCanAccess(999999, '2024001', '张三'));
    }

    public function testRosterChangeTakesEffectImmediately(): void
    {
        $courseId = $this->courses->create('数据结构', null, '', null);
        $rosterId = $this->roster->add($courseId, '2024001', '张三', '');

        $this->assertCount(1, $this->courses->listActiveByStudent('2024001', '张三'));

        $this->roster->remove($rosterId);
        $this->assertSame([], $this->courses->listActiveByStudent('2024001', '张三'), '名单移除后应立即失去权限');
    }

    public function testArchivedCourseInvisibleToStudent(): void
    {
        $courseId = $this->courses->create('数据结构', null, '', null);
        $this->roster->add($courseId, '2024001', '张三', '');
        $this->assertCount(1, $this->courses->listActiveByStudent('2024001', '张三'));

        $this->courses->archive($courseId);
        $this->assertSame([], $this->courses->listActiveByStudent('2024001', '张三'), '归档课程学生不可见');
        $this->assertFalse($this->courses->studentCanAccess($courseId, '2024001', '张三'));
    }

    public function testStudentCanMatchMultipleCourses(): void
    {
        $courseA = $this->courses->create('数据结构', null, '', null);
        $courseB = $this->courses->create('操作系统', null, '', null);
        $this->roster->add($courseA, '2024001', '张三', '');
        $this->roster->add($courseB, '2024001', '张三', '');

        $this->assertCount(2, $this->courses->listActiveByStudent('2024001', '张三'));
    }
}

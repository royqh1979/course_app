<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;

/**
 * 名单 Excel 批量导入（验收标准 3 的导入侧）：增量导入、事务回滚。
 */
final class RosterImportTest extends DatabaseTestCase
{
    public function testImportBatchInsertsNewAndUpdatesExisting(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $roster->add($courseId, '2024001', '张三', '计科2401');
        $roster->add($courseId, '2024002', '李四', '计科2402');

        $summary = $roster->importBatch($courseId, [
            ['student_no' => '2024001', 'name' => '张三', 'class_name' => '计科2403'],
            ['student_no' => '2024003', 'name' => '王五', 'class_name' => '计科2401'],
        ]);

        $this->assertSame(1, $summary['inserted']);
        $this->assertSame(1, $summary['updated']);

        $list = $roster->listByCourse($courseId);
        $this->assertCount(3, $list, '未出现在文件中的学生不得被移除');

        $byNo = array_column($list, null, 'student_no');
        $this->assertSame('计科2403', $byNo['2024001']['class_name'], '已有学号应更新班级');
        $this->assertSame('王五', $byNo['2024003']['name'], '新学号应新增');
        $this->assertSame('李四', $byNo['2024002']['name']);
    }

    public function testImportBatchKeepsLeadingZeros(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);

        $roster->importBatch($courseId, [
            ['student_no' => '00123', 'name' => '张三', 'class_name' => '计科2401'],
        ]);

        $list = $roster->listByCourse($courseId);
        $this->assertSame('00123', $list[0]['student_no']);
    }

    public function testImportBatchRollsBackEntirelyOnFailure(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);

        try {
            $roster->importBatch($courseId, [
                ['student_no' => '2024001', 'name' => '张三', 'class_name' => '计科2401'],
                // 第二行学号超过 32 字符，必然触发 SQL 错误
                ['student_no' => str_repeat('9', 40), 'name' => '李四', 'class_name' => '计科2402'],
            ]);
            $this->fail('导入应因非法数据抛出异常');
        } catch (\Throwable) {
            // 预期异常
        }

        $this->assertSame([], $roster->listByCourse($courseId), '失败时必须整体回滚，不留下部分导入的数据');
    }

    public function testExistingStudentNos(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $otherId = $courses->create('操作系统', null, '', null);
        $roster->add($courseId, '2024001', '张三', '');
        $roster->add($courseId, '2024002', '李四', '');
        $roster->add($otherId, '2024999', '王五', '');

        $nos = $roster->existingStudentNos($courseId);
        sort($nos);
        $this->assertSame(['2024001', '2024002'], $nos, '只统计本课程的学号');
    }

    public function testImportedRosterGrantsPermissionImmediately(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);

        $this->assertFalse($courses->studentCanAccess($courseId, '2024001', '张三'));

        $roster->importBatch($courseId, [
            ['student_no' => '2024001', 'name' => '张三', 'class_name' => '计科2401'],
        ]);

        $this->assertTrue($courses->studentCanAccess($courseId, '2024001', '张三'), '导入后无需任何匹配操作即获得权限');
        $this->assertFalse($courses->studentCanAccess($courseId, '2024001', '张三丰'), '姓名不一致不能获得权限');
    }
}

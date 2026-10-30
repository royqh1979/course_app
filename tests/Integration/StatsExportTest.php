<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SubmissionRepository;
use App\Services\PasswordService;
use App\Support\Pagination;

/**
 * 第四阶段数据层：名单基准统计、ZIP 数据源、日志分页。
 */
final class StatsExportTest extends DatabaseTestCase
{
    private int $courseId;
    private int $assignmentId;

    private function setupCourseAndAssignment(?string $deadline = null): void
    {
        $this->courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $this->assignmentId = (new AssignmentRepository())->create($this->courseId, [
            'title' => '第一次作业',
            'requirements' => null,
            'start_at' => null,
            'deadline_at' => $deadline,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024,
            'allow_late' => true,
        ]);
    }

    private function makeFile(FileRepository $files): int
    {
        return $files->create([
            'original_name' => '作业.zip',
            'stored_path' => 'courses/1/x/' . bin2hex(random_bytes(8)) . '.zip',
            'extension' => 'zip',
            'mime_type' => 'application/zip',
            'size' => 100,
            'sha256' => str_repeat('a', 64),
        ]);
    }

    public function testStatsBasedOnCurrentRosterIncludingUnregistered(): void
    {
        $this->setupCourseAndAssignment();
        $roster = new RosterRepository();
        $students = new StudentRepository();
        $submissions = new SubmissionRepository();
        $files = new FileRepository();

        // 名单 3 人：1 人已注册已提交、1 人已注册未提交、1 人未注册
        $roster->add($this->courseId, '2024001', '张三', '计科2401');
        $roster->add($this->courseId, '2024002', '李四', '计科2401');
        $roster->add($this->courseId, '2024003', '王五', '计科2402');
        $s1 = $students->create('2024001', '张三', PasswordService::hash('password-123'));
        $students->create('2024002', '李四', PasswordService::hash('password-123'));
        $submissions->insert($this->assignmentId, $s1, $this->makeFile($files));

        // 另有历史提交的学生已被移出名单：不计入统计
        $removed = $students->create('2024999', '老生', PasswordService::hash('password-123'));
        $submissions->insert($this->assignmentId, $removed, $this->makeFile($files));

        $summary = $submissions->statsSummary($this->assignmentId);
        $this->assertSame(['total' => 3, 'submitted' => 1, 'late' => 0], $summary, '统计以当前名单为基准');

        $rows = $submissions->statsByAssignment($this->assignmentId);
        $this->assertCount(3, $rows, '被移出名单学生的历史提交不计入');
        $byNo = array_column($rows, null, 'student_no');
        $this->assertNotNull($byNo['2024001']['submission_id']);
        $this->assertNull($byNo['2024002']['submission_id']);
        $this->assertNull($byNo['2024003']['submission_id'], '未注册学生显示为未提交（验收 10）');
        $this->assertSame('计科2402', $byNo['2024003']['class_name']);
    }

    public function testStatsLateComputedInRealTime(): void
    {
        $this->setupCourseAndAssignment(date('Y-m-d H:i:s', time() - 60));
        $students = new StudentRepository();
        $submissions = new SubmissionRepository();
        $files = new FileRepository();
        (new RosterRepository())->add($this->courseId, '2024001', '张三', '计科2401');
        $s1 = $students->create('2024001', '张三', PasswordService::hash('password-123'));
        $submissions->insert($this->assignmentId, $s1, $this->makeFile($files));

        $summary = $submissions->statsSummary($this->assignmentId);
        $this->assertSame(1, $summary['late'], '截止后提交实时计算为迟交');
    }

    public function testListAllForExportIncludesRemovedRosterStudents(): void
    {
        $this->setupCourseAndAssignment();
        $roster = new RosterRepository();
        $students = new StudentRepository();
        $submissions = new SubmissionRepository();
        $files = new FileRepository();

        $roster->add($this->courseId, '2024001', '张三', '计科2401');
        $s1 = $students->create('2024001', '张三', PasswordService::hash('password-123'));
        $submissions->insert($this->assignmentId, $s1, $this->makeFile($files));
        // 已移出名单但有提交的学生
        $s2 = $students->create('2024999', '老生', PasswordService::hash('password-123'));
        $submissions->insert($this->assignmentId, $s2, $this->makeFile($files));

        $rows = $submissions->listAllForExport($this->assignmentId);
        $this->assertCount(2, $rows, 'ZIP 打包含全部有效提交，包括已移出名单学生');
        $byNo = array_column($rows, null, 'student_no');
        $this->assertSame('计科2401', $byNo['2024001']['class_name']);
        $this->assertNull($byNo['2024999']['class_name'], '未匹配名单的学生班级为空');
    }

    public function testLogPaginationAndFilters(): void
    {
        $logs = new LogRepository();
        $logs->addAuthLog('student', 1, '2024001', 'login', 'success', '127.0.0.1', 'ua');
        $logs->addAuthLog('admin', 1, 'teacher', 'login', 'fail', '127.0.0.1', 'ua');
        $logs->addAuthLog('student', 2, '2024002', 'logout', 'success', '127.0.0.1', 'ua');
        $logs->addAuditLog('admin', 1, 'course.create', 'course', '1', '课程：数据结构', '127.0.0.1');
        $logs->addAuditLog('admin', 1, 'roster.import', 'course', '1', '新增 30 人', '127.0.0.1');

        $this->assertSame(3, $logs->countAuthLogs());
        $this->assertSame(2, $logs->countAuthLogs('student'));
        $this->assertSame(1, $logs->countAuthLogs('', 'logout'));
        $this->assertCount(1, $logs->listAuthLogs('student', 'logout'));

        $this->assertSame(2, $logs->countAuditLogs());
        $this->assertSame(['course.create', 'roster.import'], $logs->distinctAuditActions());
        $page = $logs->listAuditLogs('', new Pagination(1, 1, 2));
        $this->assertCount(1, $page);
        $this->assertSame('roster.import', $page[0]['action'], '按 id 倒序，最新在前');
    }
}

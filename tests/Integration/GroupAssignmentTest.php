<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\GroupRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SubmissionRepository;
use App\Services\PasswordService;
use PDOException;

/**
 * 小组作业数据层：组不变式、审批流、上下限语义、按组聚合的统计与导出。
 */
final class GroupAssignmentTest extends DatabaseTestCase
{
    private GroupRepository $groups;
    private StudentRepository $students;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new GroupRepository();
        $this->students = new StudentRepository();
    }

    private function makeStudent(string $no, string $name): int
    {
        return $this->students->create($no, $name, PasswordService::hash('password-123'));
    }

    public function testCreateGroupMakesCreatorLeaderAndActiveMember(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $leader = $this->makeStudent('2024001', '张三');

        $groupId = $this->groups->create($courseId, '第一组', $leader);
        $group = $this->groups->findById($groupId);

        $this->assertSame($leader, (int) $group['leader_student_id']);
        $this->assertSame(1, $this->groups->countActiveMembers($groupId));
        $membership = $this->groups->membershipInCourse($courseId, $leader);
        $this->assertSame('active', $membership['status']);
    }

    public function testGroupNameUniquePerCourse(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $leader = $this->makeStudent('2024001', '张三');
        $this->groups->create($courseId, '第一组', $leader);

        $this->expectException(PDOException::class);
        $this->groups->create($courseId, '第一组', $leader);
    }

    public function testApplyApproveFlow(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $leader = $this->makeStudent('2024001', '张三');
        $member = $this->makeStudent('2024002', '李四');
        $groupId = $this->groups->create($courseId, '第一组', $leader);

        // 申请：pending
        $memberId = $this->groups->addMember($groupId, $member, 'pending');
        $this->assertNull($this->groups->activeGroupOfStudent($courseId, $member), '待审批不算入组');

        // 同意：active
        $this->groups->setMemberStatus($memberId, 'active');
        $this->assertNotNull($this->groups->activeGroupOfStudent($courseId, $member));
        $this->assertSame(2, $this->groups->countActiveMembers($groupId));
    }

    public function testTransferLeader(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $leader = $this->makeStudent('2024001', '张三');
        $member = $this->makeStudent('2024002', '李四');
        $groupId = $this->groups->create($courseId, '第一组', $leader);
        $this->groups->addMember($groupId, $member, 'active');

        $this->groups->transferLeader($groupId, $member);
        $this->assertSame($member, (int) $this->groups->findById($groupId)['leader_student_id']);
    }

    public function testGroupStatsShareSubmissionAcrossMembers(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $assignments = new AssignmentRepository();
        $submissions = new SubmissionRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $roster->add($courseId, '2024001', '张三', '计科2401');
        $roster->add($courseId, '2024002', '李四', '计科2401');
        $roster->add($courseId, '2024003', '王五', '计科2402');
        $leader = $this->makeStudent('2024001', '张三');
        $member = $this->makeStudent('2024002', '李四');
        $this->makeStudent('2024003', '王五'); // 未入组

        $assignmentId = $assignments->create($courseId, [
            'title' => '小组作业一',
            'requirements' => null,
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024,
            'allow_late' => true,
            'is_group' => true,
        ]);

        $groupId = $this->groups->create($courseId, '第一组', $leader);
        $this->groups->addMember($groupId, $member, 'active');

        // 组长提交 → 全组计为已提交，未入组学生计未提交
        $fileId = $files->create([
            'original_name' => '组作业.zip',
            'stored_path' => 'courses/1/x/' . bin2hex(random_bytes(8)) . '.zip',
            'extension' => 'zip',
            'mime_type' => 'application/zip',
            'size' => 100,
            'sha256' => str_repeat('a', 64),
        ]);
        $submissions->insert($assignmentId, $leader, $fileId, $groupId);

        $summary = $submissions->statsSummaryGroup($assignmentId);
        $this->assertSame(['total' => 3, 'submitted' => 2, 'late' => 0], $summary);

        $rows = $submissions->statsByAssignmentGroup($assignmentId);
        $byNo = array_column($rows, null, 'student_no');
        $this->assertNotNull($byNo['2024001']['submission_id']);
        $this->assertNotNull($byNo['2024002']['submission_id'], '组员共享组提交');
        $this->assertSame('第一组', $byNo['2024002']['group_name']);
        $this->assertNull($byNo['2024003']['submission_id'], '未入组学生记未提交');
        $this->assertNull($byNo['2024003']['group_name']);

        // 未批改：分数为空
        $this->assertNull($byNo['2024001']['score']);
        $this->assertNull($byNo['2024001']['graded_at']);

        // 批改后全组同分
        $subId = (int) $submissions->findByAssignmentAndGroup($assignmentId, $groupId)['id'];
        $submissions->grade($subId, 88, '不错');
        $rows = $submissions->statsByAssignmentGroup($assignmentId);
        $byNo = array_column($rows, null, 'student_no');
        $this->assertSame(88, (int) $byNo['2024001']['score']);
        $this->assertSame(88, (int) $byNo['2024002']['score'], '分数共享给全组');
    }

    public function testGroupExportRowsUseGroupNameAsClassSegment(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $submissions = new SubmissionRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $leader = $this->makeStudent('2024001', '张三');
        $assignmentId = $assignments->create($courseId, [
            'title' => '小组作业一', 'requirements' => null, 'start_at' => null, 'deadline_at' => null,
            'allowed_extensions' => 'zip', 'max_file_size' => 1024, 'allow_late' => true, 'is_group' => true,
        ]);
        $groupId = $this->groups->create($courseId, '火箭队', $leader);
        $submissions->insert($assignmentId, $leader, $files->create([
            'original_name' => '组作业.zip',
            'stored_path' => 'courses/1/x/' . bin2hex(random_bytes(8)) . '.zip',
            'extension' => 'zip', 'mime_type' => 'application/zip', 'size' => 1, 'sha256' => str_repeat('a', 64),
        ]), $groupId);

        $rows = $submissions->listGroupsForExport($assignmentId);
        $this->assertCount(1, $rows);
        $this->assertSame('火箭队', $rows[0]['class_name'], 'ZIP 包内文件名班级段用组名');
        $this->assertSame('2024001', $rows[0]['student_no']);
    }

    public function testDisbandProtection(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $submissions = new SubmissionRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $leader = $this->makeStudent('2024001', '张三');
        $assignmentId = $assignments->create($courseId, [
            'title' => '小组作业一', 'requirements' => null, 'start_at' => null, 'deadline_at' => null,
            'allowed_extensions' => 'zip', 'max_file_size' => 1024, 'allow_late' => true, 'is_group' => true,
        ]);
        $groupId = $this->groups->create($courseId, '第一组', $leader);
        $this->assertFalse($this->groups->hasSubmissions($groupId));

        $submissions->insert($assignmentId, $leader, $files->create([
            'original_name' => 'a.zip', 'stored_path' => 'courses/1/x/' . bin2hex(random_bytes(8)) . '.zip',
            'extension' => 'zip', 'mime_type' => 'application/zip', 'size' => 1, 'sha256' => str_repeat('a', 64),
        ]), $groupId);
        $this->assertTrue($this->groups->hasSubmissions($groupId), '已有提交的组不能解散');
    }
}

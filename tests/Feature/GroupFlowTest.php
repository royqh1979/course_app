<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\GroupRepository;
use App\Repositories\RosterRepository;
use App\Repositories\SubmissionRepository;
use App\Support\App;
use ZipArchive;

/**
 * 小组作业 HTTP 流程：建组/申请/审批/上下限/组长提交/组员提示/教师查看分组/统计共享。
 */
final class GroupFlowTest extends FeatureTestCase
{
    /** @var array<int, string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeDir(rtrim((string) App::config('storage_path'), '/\\') . '/courses');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*', GLOB_MARK) ?: [] as $path) {
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeZip(string $content = '组作业'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grp_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('work.txt', $content);
        $zip->close();
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** 建课程（含上下限）+ 名单 + 三个学生账号 + 小组作业（已发布），返回 [courseId, assignmentId] */
    private function prepareCourse(?int $min = null, ?int $max = null): array
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null, $min, $max);
        $roster = new RosterRepository();
        $roster->add($courseId, '2024001', '张三', '计科2401');
        $roster->add($courseId, '2024002', '李四', '计科2401');
        $roster->add($courseId, '2024003', '王五', '计科2401');
        $this->createStudent('2024001', '张三');
        $this->createStudent('2024002', '李四');
        $this->createStudent('2024003', '王五');

        $assignments = new AssignmentRepository();
        $assignmentId = $assignments->create($courseId, [
            'title' => '小组作业一',
            'requirements' => '以小组为单位完成',
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024 * 1024,
            'allow_late' => true,
            'is_group' => true,
        ]);
        $assignments->setStatus($assignmentId, 'published');
        return [$courseId, $assignmentId];
    }

    private function studentClient(string $no): HttpClient
    {
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'student', $no, 'student-pass-123');
        return $client;
    }

    private function postForm(HttpClient $client, string $pageUrl, string $postUrl, array $data): HttpResponse
    {
        $page = $client->get($pageUrl);
        return $client->post($postUrl, ['_csrf' => $page->csrfToken() ?? ''] + $data);
    }

    public function testCreateApplyApproveFlow(): void
    {
        [$courseId] = $this->prepareCourse();
        $leader = $this->studentClient('2024001');
        $member = $this->studentClient('2024002');

        // 张三建组
        $this->postForm($leader, '/?r=student/course&id=' . $courseId, '/?r=student/group/create', [
            'course_id' => (string) $courseId,
            'name' => '火箭队',
        ]);
        $groups = (new GroupRepository())->listByCourse($courseId);
        $this->assertCount(1, $groups);
        $this->assertSame('火箭队', $groups[0]['name']);
        $groupId = (int) $groups[0]['id'];

        // 李四申请 → 张三页面出现待审批，同意后李四入组
        $this->postForm($member, '/?r=student/course&id=' . $courseId, '/?r=student/group/apply', [
            'group_id' => (string) $groupId,
        ]);
        $memberPage = $member->get('/?r=student/course&id=' . $courseId);
        $this->assertStringContainsString('待组长审批', $memberPage->body);

        $leaderGroupPage = $leader->get('/?r=student/group&id=' . $groupId);
        $this->assertStringContainsString('待审批申请（1）', $leaderGroupPage->body);

        $pendingId = (new GroupRepository())->members($groupId, 'pending')[0]['id'];
        $this->postForm($leader, '/?r=student/group&id=' . $groupId, '/?r=student/group/approve', [
            'member_id' => (string) $pendingId,
        ]);
        $memberPage = $member->get('/?r=student/course&id=' . $courseId);
        $this->assertStringContainsString('我的小组', $memberPage->body);
        $this->assertStringContainsString('火箭队', $memberPage->body);

        // 李四不是组长：不能审批他人
        $res = $this->postForm($member, '/?r=student/group&id=' . $groupId, '/?r=student/group/disband', [
            'group_id' => (string) $groupId,
        ]);
        $this->assertSame(403, $res->status);
    }

    public function testMaxMembersCheckedOnApplyAndApprove(): void
    {
        [$courseId] = $this->prepareCourse(null, 1); // 上限 1 人（只有组长）
        $leader = $this->studentClient('2024001');
        $member = $this->studentClient('2024002');

        $this->postForm($leader, '/?r=student/course&id=' . $courseId, '/?r=student/group/create', [
            'course_id' => (string) $courseId,
            'name' => '火箭队',
        ]);
        $groupId = (int) (new GroupRepository())->listByCourse($courseId)[0]['id'];

        $this->postForm($member, '/?r=student/course&id=' . $courseId, '/?r=student/group/apply', [
            'group_id' => (string) $groupId,
        ]);
        $this->assertNull(
            (new GroupRepository())->membershipInCourse($courseId, 2),
            '人数达上限时申请应被拒绝'
        );
    }

    public function testLeaderSubmitsAndMemberSeesNoticeAndSharedResult(): void
    {
        [$courseId, $assignmentId] = $this->prepareCourse();
        $leader = $this->studentClient('2024001');
        $member = $this->studentClient('2024002');

        $this->postForm($leader, '/?r=student/course&id=' . $courseId, '/?r=student/group/create', [
            'course_id' => (string) $courseId,
            'name' => '火箭队',
        ]);
        $groupId = (int) (new GroupRepository())->listByCourse($courseId)[0]['id'];
        $this->postForm($member, '/?r=student/course&id=' . $courseId, '/?r=student/group/apply', [
            'group_id' => (string) $groupId,
        ]);
        $pendingId = (new GroupRepository())->members($groupId, 'pending')[0]['id'];
        $this->postForm($leader, '/?r=student/group&id=' . $groupId, '/?r=student/group/approve', [
            'member_id' => (string) $pendingId,
        ]);

        // 组员提交被拒（页面提示 + 服务端拒绝）
        $memberPage = $member->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('只有组长能提交小组作业', $memberPage->body);
        $member->upload('/?r=student/submissions/submit', 'file', $this->makeZip(), '李四.zip', [
            '_csrf' => $memberPage->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $this->assertNull((new SubmissionRepository())->findByAssignmentAndGroup($assignmentId, $groupId));

        // 组长提交成功
        $leaderPage = $leader->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringNotContainsString('只有组长能提交小组作业', $leaderPage->body);
        $leader->upload('/?r=student/submissions/submit', 'file', $this->makeZip(), '火箭队作业.zip', [
            '_csrf' => $leaderPage->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $sub = (new SubmissionRepository())->findByAssignmentAndGroup($assignmentId, $groupId);
        $this->assertNotNull($sub);
        $this->assertSame('火箭队作业.zip', $sub['original_name']);

        // 组员看到组提交与状态
        $memberPage = $member->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('火箭队作业.zip', $memberPage->body);
        $this->assertStringContainsString('按时', $memberPage->body);
    }

    public function testMinMembersCheckedOnSubmit(): void
    {
        [$courseId, $assignmentId] = $this->prepareCourse(2, null); // 下限 2 人
        $leader = $this->studentClient('2024001');
        $this->postForm($leader, '/?r=student/course&id=' . $courseId, '/?r=student/group/create', [
            'course_id' => (string) $courseId,
            'name' => '火箭队',
        ]);
        $groupId = (int) (new GroupRepository())->listByCourse($courseId)[0]['id'];

        $page = $leader->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('小组成员不足 2 人', $page->body);
        $leader->upload('/?r=student/submissions/submit', 'file', $this->makeZip(), '作业.zip', [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $this->assertNull((new SubmissionRepository())->findByAssignmentAndGroup($assignmentId, $groupId), '人数不足下限不得提交');
    }

    public function testTeacherSeesGroupOverviewAndStatsShareScore(): void
    {
        [$courseId, $assignmentId] = $this->prepareCourse();
        $groups = new GroupRepository();

        // 直接建组（两名成员）并由组长提交、教师批改
        $leader = 1;
        $groupId = $groups->create($courseId, '火箭队', $leader);
        $groups->addMember($groupId, 2, 'active');
        $submissions = new SubmissionRepository();
        $files = new \App\Repositories\FileRepository();
        $storage = rtrim((string) App::config('storage_path'), '/\\');
        $storedPath = 'courses/' . $courseId . '/assignments/' . $assignmentId . '/submissions/' . bin2hex(random_bytes(4)) . '.zip';
        if (!is_dir(dirname($storage . '/' . $storedPath))) {
            mkdir(dirname($storage . '/' . $storedPath), 0750, true);
        }
        copy($this->makeZip(), $storage . '/' . $storedPath);
        $subId = $submissions->insert($assignmentId, $leader, $files->create([
            'original_name' => '火箭队作业.zip',
            'stored_path' => $storedPath,
            'extension' => 'zip', 'mime_type' => 'application/zip', 'size' => 100, 'sha256' => str_repeat('a', 64),
        ]), $groupId);
        $submissions->grade($subId, 90, '配合默契');

        $this->createAdmin();
        $admin = new HttpClient(self::$baseUrl);
        $this->login($admin, 'admin', 'teacher', 'admin-pass-123');

        // 课程页分组情况
        $page = $admin->get('/?r=admin/courses/show&id=' . $courseId);
        $this->assertStringContainsString('火箭队', $page->body);
        $this->assertStringContainsString('2024002 李四', $page->body);

        // 统计页：两名成员都计已提交且同分
        $stats = $admin->get('/?r=admin/assignments/stats&id=' . $assignmentId);
        $this->assertStringContainsString('火箭队', $stats->body);
        $this->assertEquals(2, substr_count($stats->body, 'bg-success">已提交'), '组提交让全组计为已提交');
        $this->assertEquals(2, substr_count($stats->body, '90 分'), '分数共享给全组');

        // 导出 Excel：两行同分
        $export = $admin->get('/?r=admin/assignments/export&id=' . $assignmentId);
        $path = tempnam(sys_get_temp_dir(), 'gexp_') . '.xlsx';
        file_put_contents($path, $export->body);
        $this->tmpFiles[] = $path;
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        $this->assertSame(4, $sheet->getHighestDataRow(), '名单 3 人 + 表头');
        $this->assertSame('90', (string) $sheet->getCell([9, 2])->getValue(), '组长共享分数');
        $this->assertSame('90', (string) $sheet->getCell([9, 3])->getValue(), '组员共享分数');
        $this->assertSame('', (string) $sheet->getCell([9, 4])->getValue(), '未入组学生未提交，分数为空白');
    }

    public function testGroupOperationsBlockedWhenCourseArchived(): void
    {
        [$courseId] = $this->prepareCourse();
        $leader = $this->studentClient('2024001');
        $this->postForm($leader, '/?r=student/course&id=' . $courseId, '/?r=student/group/create', [
            'course_id' => (string) $courseId,
            'name' => '火箭队',
        ]);
        $groupId = (int) (new GroupRepository())->listByCourse($courseId)[0]['id'];

        (new CourseRepository())->archive($courseId);

        // 归档后：改名/解散/申请等小组变更全部拒绝
        $page = $leader->get('/?r=student/home');
        $res = $leader->post('/?r=student/group/disband', [
            '_csrf' => $page->csrfToken() ?? '',
            'group_id' => (string) $groupId,
        ]);
        $this->assertSame(302, $res->status);
        $this->assertNotNull((new GroupRepository())->findById($groupId), '归档课程的小组不得解散');
    }
}

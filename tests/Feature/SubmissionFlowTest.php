<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;
use App\Repositories\SubmissionRepository;
use App\Support\App;
use ZipArchive;

/**
 * 作业与提交 HTTP 流程（验收标准 7、9）：
 * 作业发布可见、学生提交/重交、补交与迟交实时计算、改期即时生效、权限与归档限制。
 */
final class SubmissionFlowTest extends FeatureTestCase
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

    private function makeZip(string $content = '作业内容'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sub_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('work.txt', $content);
        $zip->close();
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    private function loginStudent(HttpClient $client): void
    {
        $this->login($client, 'student', '2024001', 'student-pass-123');
    }

    private function setupCourse(): int
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');
        $this->createStudent();
        return $courseId;
    }

    private function makeAssignment(int $courseId, array $overrides = []): int
    {
        return (new AssignmentRepository())->create($courseId, $overrides + [
            'title' => '第一次作业',
            'requirements' => '完成习题',
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => 'zip,docx',
            'max_file_size' => 1024 * 1024,
            'allow_late' => true,
        ]);
    }

    private function studentSubmit(HttpClient $student, int $assignmentId, string $file, string $name): HttpResponse
    {
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        return $student->upload('/?r=student/submissions/submit', 'file', $file, $name, [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
    }

    public function testAssignmentPublishAndVisibleToStudent(): void
    {
        $courseId = $this->setupCourse();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 通过 HTTP 新建作业
        $page = $admin->get('/?r=admin/assignments/create&course_id=' . $courseId);
        $res = $admin->post('/?r=admin/assignments/store', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '第一次作业',
            'requirements' => '完成课后习题 1-5',
            'deadline_at' => '',
            'allowed_extensions' => ['zip'],
            'max_file_size_mb' => '20',
            'allow_late' => '1',
        ]);
        $this->assertSame(302, $res->status);
        $assignmentId = (int) (new AssignmentRepository())->listByCourse($courseId)[0]['id'];

        // 草稿：学生课程页不可见，详情页 404（验收 6）
        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);
        $this->assertStringNotContainsString('第一次作业', $student->get('/?r=student/course&id=' . $courseId)->body);
        $this->assertSame(404, $student->get('/?r=student/assignment&id=' . $assignmentId)->status);

        // 发布后可见
        $page = $admin->get('/?r=admin/courses/show&id=' . $courseId);
        $admin->post('/?r=admin/assignments/publish', ['_csrf' => $page->csrfToken() ?? '', 'id' => (string) $assignmentId]);
        $this->assertStringContainsString('第一次作业', $student->get('/?r=student/course&id=' . $courseId)->body);
        $detail = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertSame(200, $detail->status);
        $this->assertStringContainsString('完成课后习题 1-5', $detail->body);
        $this->assertStringContainsString('尚未提交', $detail->body);
    }

    public function testSubmitAndResubmitKeepsOnlyLatest(): void
    {
        $courseId = $this->setupCourse();
        $assignmentId = $this->makeAssignment($courseId);
        (new AssignmentRepository())->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);

        // 首次提交
        $res = $this->studentSubmit($student, $assignmentId, $this->makeZip('v1'), '作业v1.zip');
        $this->assertSame(302, $res->status);

        $submissions = new SubmissionRepository();
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertNotNull($sub);
        $this->assertSame('作业v1.zip', $sub['original_name']);
        $this->assertSame(0, (int) $sub['is_late']);

        // 磁盘为 UUID 命名（验收 8）
        $storageRoot = rtrim((string) App::config('storage_path'), '/\\');
        $this->assertMatchesRegularExpression(
            '#^courses/' . $courseId . '/assignments/' . $assignmentId . '/submissions/[0-9a-f-]{36}\.zip$#',
            $sub['stored_path']
        );
        $oldAbsolute = $storageRoot . '/' . $sub['stored_path'];
        $this->assertFileExists($oldAbsolute);

        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('作业v1.zip', $page->body);
        $this->assertStringContainsString('按时', $page->body);

        // 重新提交：替换旧文件（验收 9）
        $this->studentSubmit($student, $assignmentId, $this->makeZip('v2'), '作业v2.zip');
        $sub2 = $submissions->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertSame((int) $sub['id'], (int) $sub2['id'], '每人每作业仅一条记录');
        $this->assertSame('作业v2.zip', $sub2['original_name']);
        $this->assertSame($sub['first_submitted_at'], $sub2['first_submitted_at'], '首次提交时间保留');
        $this->assertFileDoesNotExist($oldAbsolute, '旧文件应被清理');

        // 学生可下载自己的提交
        $download = $student->get('/?r=submission/download&id=' . $sub2['id']);
        $this->assertSame(200, $download->status);
        $this->assertStringContainsString(rawurlencode('作业v2.zip'), $download->header('content-disposition') ?? '');
    }

    public function testSubmissionPermissionOnOthers(): void
    {
        $courseId = $this->setupCourse();
        $assignmentId = $this->makeAssignment($courseId);
        (new AssignmentRepository())->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);
        $this->studentSubmit($student, $assignmentId, $this->makeZip(), '作业.zip');
        $sub = (new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1);

        // 另一学生下载他人提交 → 403（验收 4 文件级）
        (new RosterRepository())->add($courseId, '2024002', '李四', '计科2401');
        $this->createStudent('2024002', '李四');
        $other = new HttpClient(self::$baseUrl);
        $this->login($other, 'student', '2024002', 'student-pass-123');
        $this->assertSame(403, $other->get('/?r=submission/download&id=' . $sub['id'])->status);

        // 教师可下载
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $this->assertSame(200, $admin->get('/?r=submission/download&id=' . $sub['id'])->status);
    }

    public function testSubmitRejectsBadExtensionAndOversize(): void
    {
        $courseId = $this->setupCourse();
        $assignmentId = $this->makeAssignment($courseId, ['max_file_size' => 100]);
        (new AssignmentRepository())->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);

        // 后缀不在白名单（验收 7）
        $this->studentSubmit($student, $assignmentId, $this->makeZip(), '作业.pdf');
        $this->assertNull((new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1), '非法后缀不得产生提交记录');

        // 超大（限制 100 字节）
        $this->studentSubmit($student, $assignmentId, $this->makeZip(str_repeat('x', 500)), '作业.zip');
        $this->assertNull((new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1), '超大文件不得产生提交记录');
    }

    public function testLateSubmissionWithNoticeAndComputedFlag(): void
    {
        $courseId = $this->setupCourse();
        // 截止已过，允许补交（默认）
        $assignmentId = $this->makeAssignment($courseId, [
            'deadline_at' => date('Y-m-d H:i:s', time() - 3600),
            'allow_late' => true,
        ]);
        (new AssignmentRepository())->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);

        // 页面提示"将记为补交"
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('补交', $page->body);

        // 截止后仍可提交
        $res = $this->studentSubmit($student, $assignmentId, $this->makeZip(), '作业.zip');
        $this->assertSame(302, $res->status);
        $sub = (new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertNotNull($sub);
        $this->assertSame(1, (int) $sub['is_late'], '补交应计算为迟交');

        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('迟交', $page->body);

        // 教师改期（延长截止时间）→ 迟交标记自动消失
        (new AssignmentRepository())->update($assignmentId, [
            'title' => '第一次作业',
            'requirements' => '完成习题',
            'start_at' => null,
            'deadline_at' => date('Y-m-d H:i:s', time() + 86400),
            'allowed_extensions' => 'zip,docx',
            'max_file_size' => 1024 * 1024,
            'allow_late' => true,
        ]);
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('按时', $page->body, '改期后迟交标记应实时消失');
    }

    public function testSubmitRejectedWhenLateDisabled(): void
    {
        $courseId = $this->setupCourse();
        $assignmentId = $this->makeAssignment($courseId, [
            'deadline_at' => date('Y-m-d H:i:s', time() - 3600),
            'allow_late' => false,
        ]);
        (new AssignmentRepository())->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);

        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('不允许补交', $page->body);

        // 直接 POST 也被服务端拒绝
        $res = $this->studentSubmit($student, $assignmentId, $this->makeZip(), '作业.zip');
        $this->assertSame(302, $res->status);
        $this->assertNull((new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1), '不允许补交的作业截止后不得产生提交');
    }

    public function testSubmitBlockedWhenCourseArchivedOrAssignmentClosed(): void
    {
        $courseId = $this->setupCourse();
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $assignmentId = $this->makeAssignment($courseId);
        $assignments->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);

        // 关闭作业
        $assignments->setStatus($assignmentId, 'closed');
        $this->studentSubmit($student, $assignmentId, $this->makeZip(), '作业.zip');
        $this->assertNull((new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1), '已关闭作业不能提交');

        // 重新发布后可提交
        $assignments->setStatus($assignmentId, 'published');
        $this->studentSubmit($student, $assignmentId, $this->makeZip(), '作业.zip');
        $this->assertNotNull((new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1));

        // 归档课程后不能再提交（学生端课程已不可见，直接 POST 也被拒）
        $courses->archive($courseId);
        $res = $this->studentSubmit($student, $assignmentId, $this->makeZip('v2'), '作业v2.zip');
        $this->assertSame(403, $res->status, '归档课程不能继续提交（验收 14 提交侧）');
    }

    public function testAttachmentDownload(): void
    {
        $courseId = $this->setupCourse();
        $assignmentId = $this->makeAssignment($courseId);

        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $page = $admin->get('/?r=admin/assignments/edit&id=' . $assignmentId);
        $admin->upload('/?r=admin/assignments/attachments/add', 'file', $this->makeZip(), '题目.zip', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $assignmentId,
        ]);

        $attachments = (new \App\Repositories\AttachmentRepository())->listByAssignment($assignmentId);
        $this->assertCount(1, $attachments);

        // 草稿作业的附件学生不可下载
        $student = new HttpClient(self::$baseUrl);
        $this->loginStudent($student);
        $this->assertSame(403, $student->get('/?r=attachment/download&id=' . $attachments[0]['id'])->status);

        // 发布后可下载
        (new AssignmentRepository())->setStatus($assignmentId, 'published');
        $download = $student->get('/?r=attachment/download&id=' . $attachments[0]['id']);
        $this->assertSame(200, $download->status);
        $this->assertStringContainsString(rawurlencode('题目.zip'), $download->header('content-disposition') ?? '');
    }
}

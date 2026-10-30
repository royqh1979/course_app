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
 * 教师批改作业 HTTP 流程：
 * 批改展示、批改后学生不能再重交（锁定）、教师可修改批改结果、输入校验。
 */
final class GradingFlowTest extends FeatureTestCase
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
        $path = tempnam(sys_get_temp_dir(), 'grade_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('work.txt', $content);
        $zip->close();
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** 建课程+名单+学生+已发布作业+学生已提交，返回 [courseId, assignmentId, submissionId] */
    private function setupSubmittedAssignment(): array
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');
        $this->createStudent();

        $assignments = new AssignmentRepository();
        $assignmentId = $assignments->create($courseId, [
            'title' => '第一次作业',
            'requirements' => '完成习题',
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024 * 1024,
            'allow_late' => true,
        ]);
        $assignments->setStatus($assignmentId, 'published');

        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $student->upload('/?r=student/submissions/submit', 'file', $this->makeZip(), '作业.zip', [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $sub = (new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1);
        return [$courseId, $assignmentId, (int) $sub['id']];
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    private function grade(HttpClient $admin, int $assignmentId, int $submissionId, array $data): HttpResponse
    {
        $page = $admin->get('/?r=admin/assignments/submissions&id=' . $assignmentId);
        return $admin->post('/?r=admin/assignments/grade', ['_csrf' => $page->csrfToken() ?? '', 'id' => (string) $submissionId] + $data);
    }

    public function testSubmissionsPageRequiresAdmin(): void
    {
        [, $assignmentId] = $this->setupSubmittedAssignment();

        $guest = new HttpClient(self::$baseUrl);
        $res = $guest->get('/?r=admin/assignments/submissions&id=' . $assignmentId);
        $this->assertSame(302, $res->status);

        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $res = $student->get('/?r=admin/assignments/submissions&id=' . $assignmentId);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testGradeLocksStudentSubmissionAndShowsResult(): void
    {
        [, $assignmentId, $submissionId] = $this->setupSubmittedAssignment();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 批改页可见提交
        $page = $admin->get('/?r=admin/assignments/submissions&id=' . $assignmentId);
        $this->assertStringContainsString('2024001', $page->body);
        $this->assertStringContainsString('作业.zip', $page->body);

        // 批改
        $res = $this->grade($admin, $assignmentId, $submissionId, ['score' => '90', 'comment' => '完成得不错']);
        $this->assertSame(302, $res->status);

        $submissions = new SubmissionRepository();
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertSame(90, (int) $sub['score']);
        $this->assertSame('完成得不错', $sub['comment']);
        $this->assertNotNull($sub['graded_at']);

        // 学生端可见分数与评语
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('90 分', $page->body);
        $this->assertStringContainsString('完成得不错', $page->body);
        $this->assertStringContainsString('已批改', $page->body);

        // 批改后学生不能再重交（服务端拒绝，提交记录与文件不变）
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringNotContainsString('name="file"', $page->body, '已批改后不再显示提交表单');
        $student->upload('/?r=student/submissions/submit', 'file', $this->makeZip('v2'), '作业v2.zip', [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertSame('作业.zip', $sub['original_name'], '已批改的提交不能被替换');
    }

    public function testTeacherCanUpdateGrade(): void
    {
        [, $assignmentId, $submissionId] = $this->setupSubmittedAssignment();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        $this->grade($admin, $assignmentId, $submissionId, ['score' => '80', 'comment' => '初评']);
        $res = $this->grade($admin, $assignmentId, $submissionId, ['score' => '95', 'comment' => '复核后加分']);
        $this->assertSame(302, $res->status);

        $sub = (new SubmissionRepository())->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertSame(95, (int) $sub['score'], '教师可以修改批改结果');
        $this->assertSame('复核后加分', $sub['comment']);

        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $this->assertStringContainsString('95 分', $page->body);
    }

    public function testGradeValidation(): void
    {
        [, $assignmentId, $submissionId] = $this->setupSubmittedAssignment();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $submissions = new SubmissionRepository();

        // 分数越界
        $this->grade($admin, $assignmentId, $submissionId, ['score' => '101', 'comment' => '']);
        $this->assertNull($submissions->findByAssignmentAndStudent($assignmentId, 1)['graded_at']);

        // 分数与评语都为空
        $this->grade($admin, $assignmentId, $submissionId, ['score' => '', 'comment' => '']);
        $this->assertNull($submissions->findByAssignmentAndStudent($assignmentId, 1)['graded_at']);

        // 仅评语也可以批改
        $this->grade($admin, $assignmentId, $submissionId, ['score' => '', 'comment' => '已阅']);
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, 1);
        $this->assertNotNull($sub['graded_at']);
        $this->assertNull($sub['score']);
    }
}

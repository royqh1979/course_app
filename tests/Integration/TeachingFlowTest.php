<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SubmissionRepository;
use App\Services\PasswordService;

/**
 * 第三阶段数据层：讲义/作业/附件/提交 CRUD，迟交实时计算。
 */
final class TeachingFlowTest extends DatabaseTestCase
{
    private function makeFile(FileRepository $files, string $name = 'a.zip'): int
    {
        return $files->create([
            'original_name' => $name,
            'stored_path' => 'courses/1/x/' . bin2hex(random_bytes(8)) . '.zip',
            'extension' => 'zip',
            'mime_type' => 'application/zip',
            'size' => 100,
            'sha256' => str_repeat('a', 64),
        ]);
    }

    private function makeAssignmentData(array $overrides = []): array
    {
        return $overrides + [
            'title' => '第一次作业',
            'requirements' => '完成课后习题',
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => 'doc,docx,zip',
            'max_file_size' => 20 * 1024 * 1024,
            'allow_late' => true,
        ];
    }

    public function testHandoutLifecycle(): void
    {
        $courses = new CourseRepository();
        $handouts = new HandoutRepository();
        $files = new FileRepository();
        $courseId = $courses->create('数据结构', null, '', null);

        $id = $handouts->create($courseId, '第一章讲义', '预习用', $this->makeFile($files));
        $handout = $handouts->findById($id);
        $this->assertSame('draft', $handout['status'], '新上传讲义默认草稿');
        $this->assertNull($handout['published_at']);

        // 学生端不可见草稿
        $this->assertSame([], $handouts->listByCourse($courseId, true));
        $this->assertCount(1, $handouts->listByCourse($courseId));

        // 发布
        $handouts->setStatus($id, 'published');
        $handout = $handouts->findById($id);
        $this->assertSame('published', $handout['status']);
        $this->assertNotNull($handout['published_at']);
        $this->assertCount(1, $handouts->listByCourse($courseId, true));

        // 下线
        $handouts->setStatus($id, 'offline');
        $this->assertSame([], $handouts->listByCourse($courseId, true));

        // 修改与替换文件
        $handouts->update($id, '第一章讲义（修订版）', null);
        $newFileId = $this->makeFile($files, 'v2.pdf');
        $handouts->replaceFile($id, $newFileId);
        $handout = $handouts->findById($id);
        $this->assertSame('第一章讲义（修订版）', $handout['title']);
        $this->assertSame('v2.pdf', $handout['original_name']);
    }

    public function testAssignmentCrudAndStudentVisibility(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $courseId = $courses->create('数据结构', null, '', null);

        $id = $assignments->create($courseId, $this->makeAssignmentData(['deadline_at' => '2099-01-01 00:00:00']));
        $this->assertSame('draft', $assignments->findById($id)['status']);
        $this->assertSame([], $assignments->listByCourse($courseId, true), '草稿对学生不可见');

        $assignments->setStatus($id, 'published');
        $this->assertCount(1, $assignments->listByCourse($courseId, true));

        // 任何时间都可修改（含已过的截止时间）
        $assignments->update($id, $this->makeAssignmentData([
            'title' => '第一次作业（改）',
            'deadline_at' => '2020-01-01 00:00:00',
            'allow_late' => false,
        ]));
        $a = $assignments->findById($id);
        $this->assertSame('第一次作业（改）', $a['title']);
        $this->assertSame('2020-01-01 00:00:00', $a['deadline_at']);
        $this->assertSame(0, (int) $a['allow_late']);

        $assignments->setStatus($id, 'closed');
        $this->assertCount(1, $assignments->listByCourse($courseId, true), '已关闭作业学生仍可回顾');
    }

    public function testAttachments(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $attachments = new AttachmentRepository();
        $files = new FileRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $assignmentId = $assignments->create($courseId, $this->makeAssignmentData());

        $attId = $attachments->add($assignmentId, $this->makeFile($files, '题目.pdf'));
        $this->assertCount(1, $attachments->listByAssignment($assignmentId));
        $att = $attachments->findById($attId);
        $this->assertSame('题目.pdf', $att['original_name']);

        $attachments->remove($attId);
        $this->assertSame([], $attachments->listByAssignment($assignmentId));
    }

    public function testSubmissionInsertReplaceAndLateComputed(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $submissions = new SubmissionRepository();
        $students = new StudentRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $studentId = $students->create('2024001', '张三', PasswordService::hash('password-123'));

        // 截止在未来：提交为按时
        $assignmentId = $assignments->create($courseId, $this->makeAssignmentData([
            'deadline_at' => date('Y-m-d H:i:s', time() + 3600),
        ]));
        $submissions->insert($assignmentId, $studentId, $this->makeFile($files));
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, $studentId);
        $this->assertSame(0, (int) $sub['is_late'], '截止前提交不算迟交');
        $firstAt = $sub['first_submitted_at'];

        // 重新提交：保留首次时间，替换文件
        $newFileId = $this->makeFile($files, 'v2.zip');
        $submissions->replace((int) $sub['id'], $newFileId);
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, $studentId);
        $this->assertSame($firstAt, $sub['first_submitted_at']);
        $this->assertSame('v2.zip', $sub['original_name']);

        // 改期为过去：同一提交实时计算变为迟交（无需重算）
        $assignments->update($assignmentId, $this->makeAssignmentData([
            'deadline_at' => date('Y-m-d H:i:s', time() - 3600),
        ]));
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, $studentId);
        $this->assertSame(1, (int) $sub['is_late'], '改期后迟交按新截止时间自动重判');

        // 清空截止时间：恒为按时
        $assignments->update($assignmentId, $this->makeAssignmentData(['deadline_at' => null]));
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, $studentId);
        $this->assertSame(0, (int) $sub['is_late']);
    }

    public function testLateSubmissionAfterDeadlineComputed(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $submissions = new SubmissionRepository();
        $students = new StudentRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $studentId = $students->create('2024001', '张三', PasswordService::hash('password-123'));
        // 截止已过
        $assignmentId = $assignments->create($courseId, $this->makeAssignmentData([
            'deadline_at' => date('Y-m-d H:i:s', time() - 60),
        ]));
        $submissions->insert($assignmentId, $studentId, $this->makeFile($files));
        $sub = $submissions->findByAssignmentAndStudent($assignmentId, $studentId);
        $this->assertSame(1, (int) $sub['is_late'], '截止后提交（补交）应计算为迟交');
    }
}

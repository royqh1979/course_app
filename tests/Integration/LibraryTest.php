<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryAssignmentRepository;
use App\Repositories\LibraryHandoutRepository;

/**
 * 备课区数据层：CRUD、逻辑快照语义（共享 file_id）、引用计数删除。
 */
final class LibraryTest extends DatabaseTestCase
{
    private function makeFile(FileRepository $files, string $name = 'a.pdf'): int
    {
        return $files->create([
            'original_name' => $name,
            'stored_path' => 'library/handouts/' . bin2hex(random_bytes(8)) . '.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'sha256' => str_repeat('a', 64),
        ]);
    }

    public function testLibraryHandoutCrud(): void
    {
        $library = new LibraryHandoutRepository();
        $files = new FileRepository();

        $id = $library->create('第一章课件', '说明', $this->makeFile($files));
        $this->assertSame(1, $library->countAll());

        $library->update($id, '第一章课件（修订）', null);
        $this->assertSame('第一章课件（修订）', $library->findById($id)['title']);

        $newFileId = $this->makeFile($files, 'v2.pdf');
        $library->replaceFile($id, $newFileId);
        $this->assertSame('v2.pdf', $library->findById($id)['original_name']);

        $library->delete($id);
        $this->assertNull($library->findById($id));
    }

    public function testImportSharesFileRowAndStaysIndependent(): void
    {
        $courses = new CourseRepository();
        $handouts = new HandoutRepository();
        $library = new LibraryHandoutRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $fileId = $this->makeFile($files);
        $templateId = $library->create('第一章课件', '模板说明', $fileId);

        // 导入 = 复制业务行、共享 file_id
        $template = $library->findById($templateId);
        $courseHandoutId = $handouts->create($courseId, $template['title'], (string) $template['description'], (int) $template['file_id']);

        $courseHandout = $handouts->findById($courseHandoutId);
        $this->assertSame($fileId, (int) $courseHandout['file_id'], '课程副本与模板共享同一 files 行');
        $this->assertNotSame($templateId, $courseHandoutId, '业务行是独立的两条');

        // 模板修改文件：课程副本不受影响
        $newFileId = $this->makeFile($files, '新版.pdf');
        $library->replaceFile($templateId, $newFileId);
        $this->assertSame($fileId, (int) $handouts->findById($courseHandoutId)['file_id']);

        // 模板删除：files 行仍被课程引用，必须保留
        $library->delete($templateId);
        $this->assertNull($files->deleteIfUnused($fileId), '被课程引用的文件不得删除');
        $this->assertSame(1, $files->countReferences($fileId));

        // 课程侧也解除引用后：归零才可删
        $this->pdo->exec("DELETE FROM handouts WHERE id = {$courseHandoutId}");
        $this->assertSame(0, $files->countReferences($fileId));
        $this->assertNotNull($files->deleteIfUnused($fileId), '引用归零才允许删除');
        $this->assertNull($files->findById($fileId));
    }

    public function testAssignmentTemplateWithAttachmentsImport(): void
    {
        $courses = new CourseRepository();
        $assignments = new AssignmentRepository();
        $attachments = new AttachmentRepository();
        $library = new LibraryAssignmentRepository();
        $files = new FileRepository();

        $courseId = $courses->create('数据结构', null, '', null);
        $templateId = $library->create([
            'title' => '作业模板一',
            'requirements' => '完成习题',
            'allowed_extensions' => 'zip,docx',
            'max_file_size' => 1048576,
            'allow_late' => true,
        ]);
        $attFileId = $this->makeFile($files, '题目.zip');
        $library->addAttachment($templateId, $attFileId);

        // 导入：作业行 + 附件行复制，共享 file_id
        $template = $library->findById($templateId);
        $assignmentId = $assignments->create($courseId, [
            'title' => $template['title'],
            'requirements' => $template['requirements'],
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => $template['allowed_extensions'],
            'max_file_size' => (int) $template['max_file_size'],
            'allow_late' => (bool) $template['allow_late'],
        ]);
        foreach ($library->listAttachments($templateId) as $att) {
            $attachments->add($assignmentId, (int) $att['file_id']);
        }

        $courseAttachments = $attachments->listByAssignment($assignmentId);
        $this->assertCount(1, $courseAttachments);
        $this->assertSame($attFileId, (int) $courseAttachments[0]['file_id']);
        $this->assertSame(2, $files->countReferences($attFileId), '模板与课程各引用一次');

        // 删除模板附件：课程附件仍在，文件保留
        $templateAtt = $library->listAttachments($templateId)[0];
        $library->removeAttachment((int) $templateAtt['id']);
        $this->assertSame(1, $files->countReferences($attFileId));
        $this->assertNull($files->deleteIfUnused($attFileId));
        $this->assertCount(1, $attachments->listByAssignment($assignmentId), '课程副本不受模板删除影响');
    }
}

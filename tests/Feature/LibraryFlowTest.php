<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryHandoutRepository;
use App\Repositories\RosterRepository;
use App\Support\App;

/**
 * 备课区 HTTP 流程：模板上传、导入课程（逻辑快照）、模板删除后课程副本可用、
 * 存回备课区、归档课程禁止导入、权限控制。
 */
final class LibraryFlowTest extends FeatureTestCase
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
        $storage = rtrim((string) App::config('storage_path'), '/\\');
        $this->removeDir($storage . '/courses');
        $this->removeDir($storage . '/library');
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

    private function makePdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lib_');
        file_put_contents($path, "%PDF-1.4\n%template\n%%EOF");
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    /** 上传课件模板，返回模板 ID */
    private function uploadTemplate(HttpClient $admin, string $title = '第一章课件'): int
    {
        $page = $admin->get('/?r=admin/library');
        $admin->upload('/?r=admin/library/handouts/store', 'file', $this->makePdf(), '第一章.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'title' => $title,
            'description' => '模板说明',
        ]);
        return (int) (new LibraryHandoutRepository())->listAll()[0]['id'];
    }

    public function testLibraryRequiresAdmin(): void
    {
        $guest = new HttpClient(self::$baseUrl);
        $this->assertSame(302, $guest->get('/?r=admin/library')->status);

        $this->createStudent();
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $res = $student->get('/?r=admin/library');
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
        $this->assertSame(403, $student->get('/?r=library/handout/download&id=1')->status);
    }

    public function testImportHandoutFlowAndTemplateIndependence(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');
        $this->createStudent();

        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $templateId = $this->uploadTemplate($admin);

        // 导入选择页
        $select = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $this->assertStringContainsString('第一章课件', $select->body);

        // 导入为草稿
        $res = $admin->post('/?r=admin/library/import', [
            '_csrf' => $select->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $templateId,
        ]);
        $this->assertSame(302, $res->status);

        $handouts = new HandoutRepository();
        $courseHandout = $handouts->listByCourse($courseId)[0];
        $this->assertSame('draft', $courseHandout['status'], '导入后为草稿');

        // 删除模板：课程副本不受影响
        $page = $admin->get('/?r=admin/library');
        $admin->post('/?r=admin/library/handouts/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $templateId,
        ]);
        $this->assertSame([], (new LibraryHandoutRepository())->listAll());

        // 发布课程副本，学生可见可下载（文件未被模板删除波及）
        $page = $admin->get('/?r=admin/courses/show&id=' . $courseId);
        $admin->post('/?r=admin/handouts/publish', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $courseHandout['id'],
        ]);

        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('第一章课件', $student->get('/?r=student/course&id=' . $courseId)->body);
        $download = $student->get('/?r=handout/download&id=' . $courseHandout['id']);
        $this->assertSame(200, $download->status, '模板删除后课程副本仍可下载');
        $this->assertStringContainsString('%PDF-1.4', $download->body);
    }

    public function testTemplateReplaceDoesNotAffectImportedCopy(): void
    {
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $templateId = $this->uploadTemplate($admin);

        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $page = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $admin->post('/?r=admin/library/import', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $templateId,
        ]);
        $handouts = new HandoutRepository();
        $imported = $handouts->listByCourse($courseId)[0];
        $importedFileId = (int) $imported['file_id'];

        // 模板替换文件
        $edit = $admin->get('/?r=admin/library/handouts/edit&id=' . $templateId);
        $admin->upload('/?r=admin/library/handouts/replace', 'file', $this->makePdf(), '第二章新版.pdf', [
            '_csrf' => $edit->csrfToken() ?? '',
            'id' => (string) $templateId,
        ]);

        // 课程副本仍指向原文件（共享语义下旧文件被课程引用，不得删除）
        $after = $handouts->findById((int) $imported['id']);
        $this->assertSame($importedFileId, (int) $after['file_id'], '模板替换不影响已导入副本');
        $this->assertSame('第一章.pdf', $after['original_name']);
        // 模板侧已是新文件
        $template = (new LibraryHandoutRepository())->findById($templateId);
        $this->assertSame('第二章新版.pdf', $template['original_name']);
        // 课程副本可正常下载
        $this->assertSame(200, $admin->get('/?r=handout/download&id=' . $imported['id'])->status);
    }

    public function testSaveBackToLibrary(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 课程里上传一份讲义
        $page = $admin->get('/?r=admin/courses/show&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '课程内讲义.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '课程内沉淀的好讲义',
        ]);
        $handout = (new HandoutRepository())->listByCourse($courseId)[0];

        // 存回备课区
        $page = $admin->get('/?r=admin/courses/show&id=' . $courseId);
        $res = $admin->post('/?r=admin/library/save-back', [
            '_csrf' => $page->csrfToken() ?? '',
            'type' => 'handout',
            'id' => (string) $handout['id'],
        ]);
        $this->assertSame(302, $res->status);

        $library = (new LibraryHandoutRepository())->listAll();
        $this->assertCount(1, $library);
        $this->assertSame('课程内沉淀的好讲义', $library[0]['title']);

        // 存回后删除课程侧引用……此处验证共享：两处都指向同一文件，模板下载正常
        $this->assertSame(200, $admin->get('/?r=library/handout/download&id=' . $library[0]['id'])->status);
    }

    public function testImportBlockedForArchivedCourse(): void
    {
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $templateId = $this->uploadTemplate($admin);

        $courses = new CourseRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $courses->archive($courseId);

        $page = $admin->get('/?r=admin/library');
        $admin->post('/?r=admin/library/import', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $templateId,
        ]);
        $this->assertSame([], (new HandoutRepository())->listByCourse($courseId), '归档课程禁止导入');
    }
}

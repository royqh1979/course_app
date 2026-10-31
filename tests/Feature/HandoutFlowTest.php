<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryHandoutRepository;
use App\Repositories\RosterRepository;
use App\Support\App;

/**
 * 讲义 HTTP 流程（验收标准 6、7、8）：
 * 上传→草稿不可见→发布→学生可见可下载；非法后缀拒绝；UUID 命名与原始文件名下载。
 */
final class HandoutFlowTest extends FeatureTestCase
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

    private function makePdf(string $name = '第一章讲义.pdf'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'handout_');
        file_put_contents($path, "%PDF-1.4\n%test\n%%EOF");
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    /** 建课程+名单+学生，返回课程 ID */
    private function setupCourseWithStudent(): int
    {
        $courses = new CourseRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');
        $this->createStudent();
        return $courseId;
    }

    public function testHandoutPublishFlowAndStudentDownload(): void
    {
        $courseId = $this->setupCourseWithStudent();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 上传讲义（草稿）
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $res = $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '第一章讲义.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '第一章 链表',
            'description' => '预习',
        ]);
        $this->assertSame(302, $res->status);

        $handouts = (new HandoutRepository())->listByCourse($courseId);
        $this->assertCount(1, $handouts);
        $handoutId = (int) $handouts[0]['id'];
        $this->assertSame('draft', $handouts[0]['status']);

        // 验收 8：磁盘文件为 UUID 命名，数据库保留原始文件名
        $this->assertMatchesRegularExpression(
            '#^courses/' . $courseId . '/handouts/[0-9a-f-]{36}\.pdf$#',
            $handouts[0]['stored_path'] ?? (new HandoutRepository())->findById($handoutId)['stored_path']
        );

        // 验收 6：草稿对学生不可见
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $coursePage = $student->get('/?r=student/course/handouts&id=' . $courseId);
        $this->assertStringNotContainsString('第一章 链表', $coursePage->body);
        $this->assertSame(403, $student->get('/?r=handout/download&id=' . $handoutId)->status, '草稿讲义学生下载应被拒绝');

        // 发布
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->post('/?r=admin/handouts/publish', ['_csrf' => $page->csrfToken() ?? '', 'id' => (string) $handoutId]);

        $coursePage = $student->get('/?r=student/course/handouts&id=' . $courseId);
        $this->assertStringContainsString('第一章 链表', $coursePage->body);

        // 学生下载：成功且下载文件名为原始文件名
        $download = $student->get('/?r=handout/download&id=' . $handoutId);
        $this->assertSame(200, $download->status);
        $this->assertStringContainsString('%PDF-1.4', $download->body);
        $this->assertStringContainsString(rawurlencode('第一章讲义.pdf'), $download->header('content-disposition') ?? '');

        // 下线后学生不可见不可下载
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->post('/?r=admin/handouts/offline', ['_csrf' => $page->csrfToken() ?? '', 'id' => (string) $handoutId]);
        $this->assertStringNotContainsString('第一章 链表', $student->get('/?r=student/course/handouts&id=' . $courseId)->body);
        $this->assertSame(403, $student->get('/?r=handout/download&id=' . $handoutId)->status);
    }

    public function testHandoutRejectsBadExtension(): void
    {
        $courseId = $this->setupCourseWithStudent();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        $txt = tempnam(sys_get_temp_dir(), 'bad_');
        file_put_contents($txt, 'not a handout');
        $this->tmpFiles[] = $txt;

        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $txt, '讲义.txt', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '坏文件',
        ]);
        $this->assertSame([], (new HandoutRepository())->listByCourse($courseId), '非法后缀不得入库（验收 7）');

        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $this->assertStringContainsString('不允许的文件类型', $page->body);
    }

    public function testHandoutDownloadPermission(): void
    {
        $courseId = $this->setupCourseWithStudent();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '讲义.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '讲义',
        ]);
        $handoutId = (int) (new HandoutRepository())->listByCourse($courseId)[0]['id'];
        $admin->post('/?r=admin/handouts/publish', ['_csrf' => $page->csrfToken() ?? '', 'id' => (string) $handoutId]);

        // 游客下载被重定向
        $guest = new HttpClient(self::$baseUrl);
        $this->assertSame(302, $guest->get('/?r=handout/download&id=' . $handoutId)->status);

        // 无课程权限的学生 403
        $this->createStudent('2024999', '局外人');
        $outsider = new HttpClient(self::$baseUrl);
        $this->login($outsider, 'student', '2024999', 'student-pass-123');
        $this->assertSame(403, $outsider->get('/?r=handout/download&id=' . $handoutId)->status);

        // 教师可下载
        $this->assertSame(200, $admin->get('/?r=handout/download&id=' . $handoutId)->status);
    }

    public function testHandoutReplaceKeepsNewFileOnly(): void
    {
        $courseId = $this->setupCourseWithStudent();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '旧版.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '讲义',
        ]);
        $handouts = new HandoutRepository();
        $handout = $handouts->findById((int) $handouts->listByCourse($courseId)[0]['id']);
        $oldPath = rtrim((string) App::config('storage_path'), '/\\') . '/' . $handout['stored_path'];
        $this->assertFileExists($oldPath);

        // 替换文件
        $editPage = $admin->get('/?r=admin/handouts/edit&id=' . $handout['id']);
        $admin->upload('/?r=admin/handouts/replace', 'file', $this->makePdf(), '新版.pdf', [
            '_csrf' => $editPage->csrfToken() ?? '',
            'id' => (string) $handout['id'],
        ]);

        $updated = $handouts->findById((int) $handout['id']);
        $this->assertSame('新版.pdf', $updated['original_name']);
        $this->assertFileDoesNotExist($oldPath, '旧文件应在替换成功后清理');
        $this->assertFileExists(rtrim((string) App::config('storage_path'), '/\\') . '/' . $updated['stored_path']);
    }

    public function testHandoutDeleteOverHttp(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 上传讲义并发布
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '删除测试.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '待删除讲义',
        ]);
        $handouts = new HandoutRepository();
        $handout = $handouts->listByCourse($courseId)[0];
        $fileId = (int) $handout['file_id'];
        $storedPath = (new FileRepository())->findById($fileId)['stored_path'];
        $storageRoot = rtrim((string) App::config('storage_path'), '/\\');
        $admin->post('/?r=admin/handouts/publish', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $handout['id'],
        ]);

        // 已发布讲义仍可删除；先存回备课区，验证共享文件在删除讲义后保留
        $admin->post('/?r=admin/library/save-back', [
            '_csrf' => $page->csrfToken() ?? '',
            'type' => 'handout',
            'id' => (string) $handout['id'],
        ]);
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $res = $admin->post('/?r=admin/handouts/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $handout['id'],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame([], $handouts->listByCourse($courseId), '讲义已删除');
        $this->assertNotNull((new FileRepository())->findById($fileId), '备课区仍引用该文件，files 行保留');
        $this->assertFileExists($storageRoot . '/' . $storedPath, '共享文件不得删除');

        // 删除备课区条目后引用归零，文件才被回收
        $libraryId = (int) (new LibraryHandoutRepository())->listAll()[0]['id'];
        $page = $admin->get('/?r=admin/library/handouts');
        $admin->post('/?r=admin/library/handouts/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $libraryId,
        ]);
        $this->assertNull((new FileRepository())->findById($fileId), '引用归零后 files 行删除');
        $this->assertFileDoesNotExist($storageRoot . '/' . $storedPath, '引用归零后磁盘文件删除');
    }

    public function testHandoutActionsReturnJsonOverXhr(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '异步操作.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '异步操作',
        ]);
        $handout = (new HandoutRepository())->listByCourse($courseId)[0];

        // 发布（XHR）→ JSON 成功，状态已变
        $admin->xhr = true;
        $res = $admin->upload('/?r=admin/handouts/publish', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $handout['id'],
        ]);
        $admin->xhr = false;
        $json = json_decode($res->body, true);
        $this->assertSame(200, $res->status);
        $this->assertIsArray($json);
        $this->assertTrue($json['ok'], 'XHR 发布应返回 JSON 成功');
        $this->assertSame('published', (new HandoutRepository())->findById((int) $handout['id'])['status']);

        // 无 XHR 头时保持原生跳转行为
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $res = $admin->post('/?r=admin/handouts/offline', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $handout['id'],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame('offline', (new HandoutRepository())->findById((int) $handout['id'])['status']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SubmissionRepository;
use App\Support\App;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * 第四阶段 HTTP 流程（验收 10/11/12）：统计页、Excel 导出、ZIP 打包、XHR 上传、日志查看。
 */
final class StatsExportFlowTest extends FeatureTestCase
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
        $this->removeDir($storage . '/exports');
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

    private function makeZipFile(string $content = '作业内容'): string
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

    /**
     * 建课程 + 名单（张三已注册、王五未注册）+ 已发布作业 + 张三已提交。
     * 返回 [courseId, assignmentId]
     */
    private function setupAssignmentWithSubmission(): array
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $roster = new RosterRepository();
        $roster->add($courseId, '2024001', '张三', '计科2401');
        $roster->add($courseId, '2024003', '王五', '计科2402');
        $this->createStudent('2024001', '张三');

        $assignments = new AssignmentRepository();
        $assignmentId = $assignments->create($courseId, [
            'title' => '第一次作业',
            'requirements' => null,
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
        $student->upload('/?r=student/submissions/submit', 'file', $this->makeZipFile(), '张三作业.zip', [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        return [$courseId, $assignmentId];
    }

    public function testStatsPageShowsRosterBasedNumbers(): void
    {
        [, $assignmentId] = $this->setupAssignmentWithSubmission();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        $page = $admin->get('/?r=admin/assignments/stats&id=' . $assignmentId);
        $this->assertSame(200, $page->status);
        // 名单 2 人：1 已交 1 未交；未注册的王五出现在未提交名单（验收 10）
        $this->assertStringContainsString('王五', $page->body);
        $this->assertStringContainsString('张三作业.zip', $page->body);
        $this->assertStringContainsString('未提交', $page->body);
    }

    public function testExcelExportMatchesStats(): void
    {
        [, $assignmentId] = $this->setupAssignmentWithSubmission();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        $res = $admin->get('/?r=admin/assignments/export&id=' . $assignmentId);
        $this->assertSame(200, $res->status);
        $this->assertStringStartsWith("PK\x03\x04", $res->body);

        $path = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        file_put_contents($path, $res->body);
        $this->tmpFiles[] = $path;
        $sheet = IOFactory::load($path)->getActiveSheet();

        // 表头 + 2 名名单学生（含未注册）：与统计页人数一致（验收 11）
        $this->assertSame(3, $sheet->getHighestDataRow());
        $this->assertSame('张三', $sheet->getCell([2, 2])->getValue());
        $this->assertSame('王五', $sheet->getCell([2, 3])->getValue());
        // 学号按文本存储
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell([4, 2])->getDataType());
        $this->assertSame('已提交', $sheet->getCell([5, 2])->getValue());
        $this->assertSame('未提交', $sheet->getCell([5, 3])->getValue());
    }

    public function testZipContainsAllSubmissionsWithFormattedNames(): void
    {
        [$courseId, $assignmentId] = $this->setupAssignmentWithSubmission();

        // 移出名单但有历史提交的学生：也进 ZIP（验收 12 全部有效提交）
        $this->createStudent('2024999', '老生');
        $submissions = new SubmissionRepository();
        $files = new FileRepository();
        $storage = rtrim((string) App::config('storage_path'), '/\\');
        $storedPath = 'courses/' . $courseId . '/assignments/' . $assignmentId . '/submissions/' . bin2hex(random_bytes(4)) . '.zip';
        if (!is_dir(dirname($storage . '/' . $storedPath))) {
            mkdir(dirname($storage . '/' . $storedPath), 0750, true);
        }
        copy($this->makeZipFile('老生'), $storage . '/' . $storedPath);
        $submissions->insert($assignmentId, 2, $files->create([
            'original_name' => '老生作业.zip',
            'stored_path' => $storedPath,
            'extension' => 'zip',
            'mime_type' => 'application/zip',
            'size' => 1,
            'sha256' => str_repeat('a', 64),
        ]));

        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $res = $admin->get('/?r=admin/assignments/zip&id=' . $assignmentId);
        $this->assertSame(200, $res->status);
        $this->assertStringStartsWith("PK\x03\x04", $res->body);

        $path = tempnam(sys_get_temp_dir(), 'zip_') . '.zip';
        file_put_contents($path, $res->body);
        $this->tmpFiles[] = $path;
        $zip = new ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertCount(2, $names);
        $this->assertContains('计科2401_2024001_张三_张三作业.zip', $names, '包内文件名：班级_学号_姓名_原文件名');
        $this->assertContains('2024999_老生_老生作业.zip', $names, '无班级时省略班级段');
    }

    public function testZipEntryNameCollisionGetsSuffix(): void
    {
        $storage = rtrim((string) App::config('storage_path'), '/\\');
        $service = new \App\Services\ExportService(new \App\Services\FileStorage($storage));

        // 两名学生提交同名文件 → 重名加序号
        $ref = new \ReflectionMethod($service, 'uniqueEntryName');
        $ref->setAccessible(true);
        $usedNames = [];
        $n1 = $ref->invokeArgs($service, ['计科2401_2024001_张三_作业.zip', &$usedNames]);
        $n2 = $ref->invokeArgs($service, ['计科2401_2024001_张三_作业.zip', &$usedNames]);
        $this->assertSame('计科2401_2024001_张三_作业.zip', $n1);
        $this->assertSame('计科2401_2024001_张三_作业_(2).zip', $n2);

        $sanitize = new \ReflectionMethod($service, 'buildEntryName');
        $sanitize->setAccessible(true);
        $name = $sanitize->invoke($service, [
            'class_name' => 'a/b',
            'student_no' => '001',
            'student_name' => '张:三',
            'original_name' => '作?业.zip',
        ]);
        $this->assertSame('a_b_001_张_三_作_业.zip', $name, '非法字符替换为下划线');
    }

    public function testXhrUploadReturnsJsonWithFilenameMessage(): void
    {
        [, $assignmentId] = $this->setupAssignmentWithSubmission();

        $student = new HttpClient(self::$baseUrl);
        $student->xhr = true;
        $this->login($student, 'student', '2024001', 'student-pass-123');

        // XHR 重交：JSON 成功响应，含文件名显式提示
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $res = $student->upload('/?r=student/submissions/submit', 'file', $this->makeZipFile('v2'), '新版作业.zip', [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $json = json_decode($res->body, true);
        $this->assertTrue($json['ok'] ?? false, 'XHR 上传应返回 JSON 成功');
        $this->assertStringContainsString('新版作业.zip', $json['success'], '成功提示必须含文件名');
        $this->assertStringContainsString('r=student/assignment', $json['redirect'] ?? '');

        // XHR 错误：非法后缀返回 JSON 错误
        $txt = tempnam(sys_get_temp_dir(), 'bad_');
        file_put_contents($txt, 'not allowed');
        $this->tmpFiles[] = $txt;
        $page = $student->get('/?r=student/assignment&id=' . $assignmentId);
        $res = $student->upload('/?r=student/submissions/submit', 'file', $txt, '作业.exe', [
            '_csrf' => $page->csrfToken() ?? '',
            'assignment_id' => (string) $assignmentId,
        ]);
        $json = json_decode($res->body, true);
        $this->assertFalse($json['ok'] ?? true, '校验失败应返回 JSON 错误');
        $this->assertStringContainsString('不允许的文件类型', $json['error'] ?? '');
    }

    public function testLogsPagesRequireAdminAndShowEntries(): void
    {
        $guest = new HttpClient(self::$baseUrl);
        $this->assertSame(302, $guest->get('/?r=admin/logs/auth')->status);

        $this->setupAssignmentWithSubmission();
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        $auth = $admin->get('/?r=admin/logs/auth');
        $this->assertSame(200, $auth->status);
        $this->assertStringContainsString('2024001', $auth->body, '认证日志含学生登录记录');

        $filtered = $admin->get('/?r=admin/logs/auth&user_type=student&action=login');
        $this->assertStringContainsString('2024001', $filtered->body);

        $audit = $admin->get('/?r=admin/logs/audit');
        $this->assertSame(200, $audit->status);

        // 页面不含任何哈希样式内容
        $this->assertStringNotContainsString('$2y$', $auth->body);
    }

    public function testStatsExportZipRequireAdmin(): void
    {
        [, $assignmentId] = $this->setupAssignmentWithSubmission();
        $guest = new HttpClient(self::$baseUrl);
        $this->assertSame(302, $guest->get('/?r=admin/assignments/stats&id=' . $assignmentId)->status);
        $this->assertSame(302, $guest->get('/?r=admin/assignments/export&id=' . $assignmentId)->status);
        $this->assertSame(302, $guest->get('/?r=admin/assignments/zip&id=' . $assignmentId)->status);

        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $res = $student->get('/?r=admin/assignments/stats&id=' . $assignmentId);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }
}

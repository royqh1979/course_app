<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;
use App\Support\App;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 名单 Excel 导入完整 HTTP 流程（第二阶段）：模板下载、上传预校验、确认导入、权限即时生效。
 */
final class RosterImportTest extends FeatureTestCase
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
        // 清理导入临时目录中可能残留的文件
        $importsDir = rtrim((string) App::config('storage_path'), '/\\') . '/imports';
        foreach (glob($importsDir . '/*') ?: [] as $file) {
            unlink($file);
        }
    }

    /** 生成名单 xlsx 临时文件，返回路径 */
    private function makeRosterXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', '姓名');
        $sheet->setCellValue('B1', '班级');
        $sheet->setCellValue('C1', '学号');
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValueExplicit('A' . $r, $row[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $r, $row[1], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $r, $row[2], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'roster_http_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    public function testTemplateDownload(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $res = $client->get('/?r=admin/roster/template');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('spreadsheetml.sheet', $res->header('content-type') ?? '');
        $this->assertStringStartsWith("PK\x03\x04", $res->body, '模板必须是合法的 xlsx（ZIP 头）');
    }

    public function testTemplateRequiresAdmin(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $res = $client->get('/?r=admin/roster/template');
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testFullImportFlowWithPreviewAndConfirm(): void
    {
        $courses = new CourseRepository();
        $roster = new RosterRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $roster->add($courseId, '2024001', '张三', '计科2401');

        $file = $this->makeRosterXlsx([
            ['张三', '计科2403', '2024001'],   // 更新已有记录
            ['李四', '计科2401', '00123'],     // 新增，前导零
            ['', '计科2401', '2024003'],       // 错误：姓名为空
            ['王五', '计科2402', '2024002'],
            ['王五五', '计科2402', '2024002'], // 文件内重复学号
        ]);

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        // 上传并预校验：展示统计，不写库
        $page = $client->get('/?r=admin/courses/show&id=' . $courseId);
        $preview = $client->upload('/?r=admin/roster/import', 'roster_file', $file, '名单.xlsx', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
        ]);
        $this->assertSame(200, $preview->status);
        $this->assertStringContainsString('名单导入预校验', $preview->body);
        $this->assertStringContainsString('第 4 行', $preview->body, '错误必须定位到 Excel 行号');
        $this->assertStringContainsString('第 6 行', $preview->body, '重复行必须定位到 Excel 行号');
        $this->assertCount(1, $roster->listByCourse($courseId), '预校验阶段不得写入数据库');

        // 确认导入
        $confirm = $client->post('/?r=admin/roster/import/confirm', [
            '_csrf' => $preview->csrfToken() ?? '',
            'course_id' => (string) $courseId,
        ]);
        $this->assertSame(302, $confirm->status);

        $list = $roster->listByCourse($courseId);
        $byNo = array_column($list, null, 'student_no');
        $this->assertCount(3, $list, '错误行与重复行被跳过，只导入 3 条有效记录');
        $this->assertSame('计科2403', $byNo['2024001']['class_name'], '已有学号应被更新');
        $this->assertSame('00123', $byNo['00123']['student_no'], '前导零必须保留');
        $this->assertSame('王五', $byNo['2024002']['name'], '重复学号以先出现的一行为准');

        // 导入后学生权限即时生效
        $this->assertTrue($courses->studentCanAccess($courseId, '00123', '李四'));
    }

    public function testImportRejectsInvalidFileExtension(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);

        $file = tempnam(sys_get_temp_dir(), 'roster_bad_') . '.txt';
        file_put_contents($file, "not an excel file");
        $this->tmpFiles[] = $file;

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $page = $client->get('/?r=admin/courses/show&id=' . $courseId);
        $res = $client->upload('/?r=admin/roster/import', 'roster_file', $file, '名单.txt', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
        ]);
        $this->assertSame(302, $res->status, '非法后缀应被拒绝并跳回课程页');

        $follow = $client->get('/?r=admin/courses/show&id=' . $courseId);
        $this->assertStringContainsString('仅支持', $follow->body);
        $this->assertSame([], (new RosterRepository())->listByCourse($courseId));
    }

    public function testImportRequiresAdminAndCsrf(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $file = $this->makeRosterXlsx([['张三', '计科2401', '2024001']]);

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $res = $client->upload('/?r=admin/roster/import', 'roster_file', $file, '名单.xlsx', [
            'course_id' => (string) $courseId,
        ]);
        $this->assertSame(403, $res->status, '缺少 CSRF 的导入必须被拒绝');
        $this->assertSame([], (new RosterRepository())->listByCourse($courseId));
    }
}

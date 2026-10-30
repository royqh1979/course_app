<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\RosterImportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * 名单导入解析与预校验（纯逻辑 + 临时 Excel 文件，不依赖数据库）。
 */
final class RosterImportServiceTest extends TestCase
{
    private RosterImportService $service;

    /** @var array<int, string> 待清理的临时文件 */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->service = new RosterImportService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** 用 PhpSpreadsheet 生成一个真实 xlsx 文件，返回路径 */
    private function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', '姓名');
        $sheet->setCellValue('B1', '班级');
        $sheet->setCellValue('C1', '学号');
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            // 按文本写入，模拟模板格式
            $sheet->setCellValueExplicit('A' . $r, $row[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $r, $row[1], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $r, $row[2], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'roster_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tmpFiles[] = $path;
        return $path;
    }

    public function testParseReadsRowsWithExcelRowNumbers(): void
    {
        $path = $this->makeXlsx([
            ['张三', '计科2401', '2024001'],
            ['李四', '计科2402', '2024002'],
        ]);

        $rows = $this->service->parse($path);

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['row']);
        $this->assertSame(3, $rows[1]['row']);
        $this->assertSame('张三', $rows[0]['name']);
        $this->assertSame('计科2401', $rows[0]['class_name']);
        $this->assertSame('2024001', $rows[0]['student_no']);
    }

    public function testParseKeepsLeadingZerosAndTrimsSpaces(): void
    {
        $path = $this->makeXlsx([
            ['  张三  ', ' 计科2401 ', ' 00123 '],
        ]);

        $rows = $this->service->parse($path);

        $this->assertSame('00123', $rows[0]['student_no'], '学号前导零必须保留');
        $this->assertSame('张三', $rows[0]['name'], '姓名首尾空格应被清除');
        $this->assertSame('计科2401', $rows[0]['class_name']);
    }

    public function testParseSkipsFullyBlankRows(): void
    {
        $path = $this->makeXlsx([
            ['张三', '计科2401', '2024001'],
            ['', '', ''],
            ['李四', '计科2402', '2024002'],
        ]);

        $rows = $this->service->parse($path);

        $this->assertCount(2, $rows);
        $this->assertSame(4, $rows[1]['row'], '空白行被跳过后仍保留真实 Excel 行号');
    }

    public function testValidateReportsErrorsWithRowNumbers(): void
    {
        $rows = [
            ['row' => 2, 'name' => '', 'class_name' => '计科2401', 'student_no' => '2024001'],
            ['row' => 3, 'name' => '李四', 'class_name' => '', 'student_no' => '2024002'],
            ['row' => 4, 'name' => '王五', 'class_name' => '计科2401', 'student_no' => '20 24'],
            ['row' => 5, 'name' => '赵六', 'class_name' => '计科2402', 'student_no' => '2024004'],
        ];

        $result = $this->service->validate($rows, []);

        $this->assertSame(4, $result['total']);
        $this->assertCount(1, $result['valid']);
        $this->assertCount(3, $result['errors']);
        $this->assertSame([2, 3, 4], array_column($result['errors'], 'row'));
        $this->assertStringContainsString('班级', $result['errors'][1]['message']);
    }

    public function testValidateDetectsDuplicateStudentNoWithinFile(): void
    {
        $rows = [
            ['row' => 2, 'name' => '张三', 'class_name' => '计科2401', 'student_no' => '2024001'],
            ['row' => 3, 'name' => '李四', 'class_name' => '计科2402', 'student_no' => '2024002'],
            ['row' => 4, 'name' => '张三三', 'class_name' => '计科2403', 'student_no' => '2024001'],
        ];

        $result = $this->service->validate($rows, []);

        $this->assertCount(2, $result['valid']);
        $this->assertCount(1, $result['duplicates']);
        $this->assertSame(4, $result['duplicates'][0]['row']);
        $this->assertSame(2, $result['duplicates'][0]['first_row']);
        $this->assertSame('2024001', $result['duplicates'][0]['student_no']);
    }

    public function testValidateClassifiesInsertAndUpdateAgainstExistingRoster(): void
    {
        $rows = [
            ['row' => 2, 'name' => '张三', 'class_name' => '计科2401', 'student_no' => '2024001'],
            ['row' => 3, 'name' => '李四', 'class_name' => '计科2402', 'student_no' => '2024002'],
        ];

        $result = $this->service->validate($rows, ['2024001']);

        $this->assertSame(1, $result['insert_count']);
        $this->assertSame(1, $result['update_count']);
        $this->assertSame('update', $result['valid'][0]['action']);
        $this->assertSame('insert', $result['valid'][1]['action']);
    }

    public function testTemplateHasHeadersAndTextFormatForStudentNo(): void
    {
        $spreadsheet = $this->service->createTemplate();
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('姓名', $sheet->getCell('A1')->getValue());
        $this->assertSame('班级', $sheet->getCell('B1')->getValue());
        $this->assertSame('学号', $sheet->getCell('C1')->getValue());
        $this->assertSame('@', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode(), '学号列必须为文本格式以保留前导零');
    }
}

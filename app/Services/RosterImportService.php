<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Validate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * 课程名单 Excel 导入：模板生成、文件解析与预校验（不写库）。
 *
 * 模板列固定为：A=姓名、B=班级、C=学号；第 1 行为表头，数据从第 2 行开始。
 * 学号按字符串处理，保留前导零；姓名、班级、学号均必填。
 */
final class RosterImportService
{
    public const HEADERS = ['姓名', '班级', '学号'];

    /** 生成标准名单模板（学号列设为文本格式，防止 Excel 吞掉前导零） */
    public function createTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('课程名单');
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->getStyle('C2:C1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(20);
        return $spreadsheet;
    }

    /**
     * 解析名单文件为行数组（跳过完全空白行），每行保留 Excel 行号用于错误定位。
     *
     * @return array<int, array{row: int, name: string, class_name: string, student_no: string}>
     */
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($i = 2; $i <= $highestRow; $i++) {
            // 读显示值：文本格式的学号单元格可保留前导零
            $name = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex(1) . $i)->getFormattedValue());
            $className = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex(2) . $i)->getFormattedValue());
            $studentNo = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex(3) . $i)->getFormattedValue());

            if ($name === '' && $className === '' && $studentNo === '') {
                continue;
            }
            $rows[] = [
                'row' => $i,
                'name' => $name,
                'class_name' => $className,
                'student_no' => $studentNo,
            ];
        }
        return $rows;
    }

    /**
     * 预校验：逐行检查必填与格式、文件内重复学号，并按当前名单区分新增/更新。
     * 不产生任何数据库写入；错误定位到 Excel 行号。
     *
     * @param array<int, array{row: int, name: string, class_name: string, student_no: string}> $rows
     * @param array<int, string> $existingStudentNos 该课程当前名单中的学号
     * @return array{
     *     total: int,
     *     valid: array<int, array>,
     *     errors: array<int, array{row: int, message: string}>,
     *     duplicates: array<int, array{row: int, student_no: string, first_row: int}>,
     *     insert_count: int,
     *     update_count: int
     * }
     */
    public function validate(array $rows, array $existingStudentNos): array
    {
        $existing = array_flip($existingStudentNos);
        $seen = [];
        $valid = [];
        $errors = [];
        $duplicates = [];
        $insertCount = 0;
        $updateCount = 0;

        foreach ($rows as $row) {
            $problems = [];
            if (!Validate::isValidStudentNo($row['student_no'])) {
                $problems[] = '学号为空、含空格或超长（≤32 字符）';
            }
            if (!Validate::isValidName($row['name'])) {
                $problems[] = '姓名为空或超长（≤50 字符）';
            }
            if ($row['class_name'] === '' || mb_strlen($row['class_name']) > 50) {
                $problems[] = '班级为空或超长（≤50 字符）';
            }
            if ($problems !== []) {
                $errors[] = ['row' => $row['row'], 'message' => implode('；', $problems)];
                continue;
            }
            if (isset($seen[$row['student_no']])) {
                $duplicates[] = [
                    'row' => $row['row'],
                    'student_no' => $row['student_no'],
                    'first_row' => $seen[$row['student_no']],
                ];
                continue;
            }
            $seen[$row['student_no']] = $row['row'];

            $isUpdate = isset($existing[$row['student_no']]);
            $isUpdate ? $updateCount++ : $insertCount++;
            $valid[] = $row + ['action' => $isUpdate ? 'update' : 'insert'];
        }

        return [
            'total' => count($rows),
            'valid' => $valid,
            'errors' => $errors,
            'duplicates' => $duplicates,
            'insert_count' => $insertCount,
            'update_count' => $updateCount,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * 提交情况导出：Excel（名单基准）与 ZIP 打包（全部有效提交）。
 * 纯生成逻辑，将来后台异步打包可直接复用。
 */
final class ExportService
{
    public function __construct(private readonly FileStorage $storage)
    {
    }

    /**
     * 生成提交情况 Excel 并输出到 php://output（随后终止请求）。
     * 学号等用户内容全部显式按文本写入，防公式注入、保留前导零。
     *
     * @param array<int, array> $rows statsByAssignment 明细
     */
    public function outputStatsXlsx(array $rows, string $assignmentTitle): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('提交情况');
        $headers = ['序号', '姓名', '班级', '学号', '提交状态', '是否迟交', '最近提交时间', '文件名', '分数'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $submitted = $row['submission_id'] !== null;
            // 全部显式文本写入，防 = + - @ 公式注入
            $sheet->setCellValueExplicit([1, $r], $i + 1, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit([2, $r], (string) $row['roster_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['class_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['student_no'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $r], $submitted ? '已提交' : '未提交', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([6, $r], $submitted ? ((bool) $row['is_late'] ? '迟交' : '按时') : '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([7, $r], (string) ($row['last_submitted_at'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([8, $r], (string) ($row['original_name'] ?? ''), DataType::TYPE_STRING);
            // 未批改分数显示为空白（小组作业时全组共享同一分数）
            $sheet->setCellValueExplicit(
                [9, $r],
                $submitted && $row['graded_at'] !== null && $row['score'] !== null ? (string) (int) $row['score'] : '',
                DataType::TYPE_STRING
            );
        }
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->sendDownloadHeaders(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $assignmentTitle . '_提交情况.xlsx'
        );
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    /**
     * 打包全部有效提交为 ZIP 并输出（随后终止请求）。
     * 包内文件名：班级_学号_姓名_原文件名；非法字符替换为下划线；重名加序号。
     *
     * @param array<int, array> $submissions listAllForExport 结果
     * @throws RuntimeException 总大小超限或打包失败
     */
    public function outputSubmissionsZip(array $submissions, string $assignmentTitle, int $maxTotalSize): void
    {
        $totalSize = array_sum(array_map(fn ($s) => (int) $s['size'], $submissions));
        if ($totalSize > $maxTotalSize) {
            throw new RuntimeException(
                '打包总大小超过限制（' . round($totalSize / 1024 / 1024) . ' MB > '
                . (int) round($maxTotalSize / 1024 / 1024) . ' MB），请分批下载。'
            );
        }

        $exportsDir = $this->storage->absolutePath('exports');
        if (!is_dir($exportsDir) && !mkdir($exportsDir, 0750, true)) {
            throw new RuntimeException('导出目录不可用。');
        }
        $zipPath = $exportsDir . '/' . bin2hex(random_bytes(8)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建 ZIP 文件。');
        }
        $usedNames = [];
        foreach ($submissions as $sub) {
            $entryName = $this->uniqueEntryName($this->buildEntryName($sub), $usedNames);
            $absolute = $this->storage->absolutePath((string) $sub['stored_path']);
            if (is_file($absolute)) {
                $zip->addFile($absolute, $entryName);
            }
        }
        $zip->close();

        $this->sendDownloadHeaders('application/zip', $assignmentTitle . '_全部提交.zip');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }

    /** 包内文件名：班级_学号_姓名_原文件名（清理非法字符） */
    private function buildEntryName(array $sub): string
    {
        $parts = [
            (string) ($sub['class_name'] ?? ''),
            (string) $sub['student_no'],
            (string) $sub['student_name'],
            (string) $sub['original_name'],
        ];
        $name = implode('_', array_filter($parts, fn ($p) => $p !== ''));
        // 清理路径与文件名非法字符（Windows/macOS/Linux 通吃）
        $name = preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/', '_', $name);
        return $name === '' || $name === null ? '未命名' : $name;
    }

    /** 重名加序号：xxx.zip → xxx_(2).zip */
    private function uniqueEntryName(string $name, array &$usedNames): string
    {
        if (!isset($usedNames[$name])) {
            $usedNames[$name] = 1;
            return $name;
        }
        $usedNames[$name]++;
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));
        $candidate = $base . '_(' . $usedNames[$name] . ')' . ($ext === '' ? '' : '.' . $ext);
        while (isset($usedNames[$candidate])) {
            $usedNames[$name]++;
            $candidate = $base . '_(' . $usedNames[$name] . ')' . ($ext === '' ? '' : '.' . $ext);
        }
        $usedNames[$candidate] = 1;
        return $candidate;
    }

    private function sendDownloadHeaders(string $mime, string $downloadName): void
    {
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $downloadName);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('X-Content-Type-Options: nosniff');
    }
}

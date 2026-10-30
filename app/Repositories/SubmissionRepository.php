<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * 学生当前有效提交：每名学生对每项作业仅一条记录。
 * 迟交不存标志，统一按 (deadline_at 非空 且 last_submitted_at > deadline_at) 实时计算。
 */
final class SubmissionRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    /** 迟交实时计算表达式（联 assignments 使用） */
    public const LATE_EXPR = '(a.deadline_at IS NOT NULL AND s.last_submitted_at > a.deadline_at)';

    /** 按 ID 查询（含 files 元数据，供下载权限检查） */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.*, f.original_name, f.stored_path, f.mime_type, f.size
             FROM submissions s INNER JOIN files f ON f.id = s.file_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 含 files 元数据与实时计算的 is_late */
    public function findByAssignmentAndStudent(int $assignmentId, int $studentId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.*, f.original_name, f.stored_path, f.mime_type, f.size, a.deadline_at,
                    ' . self::LATE_EXPR . ' AS is_late
             FROM submissions s
             INNER JOIN files f ON f.id = s.file_id
             INNER JOIN assignments a ON a.id = s.assignment_id
             WHERE s.assignment_id = :assignment_id AND s.student_id = :student_id
             LIMIT 1'
        );
        $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** 首次提交：插入新记录 */
    public function insert(int $assignmentId, int $studentId, int $fileId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO submissions (assignment_id, student_id, file_id) VALUES (:assignment_id, :student_id, :file_id)'
        );
        $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId, 'file_id' => $fileId]);
        return (int) $this->db()->lastInsertId();
    }

    /** 重新提交：替换文件、刷新最近提交时间，保留首次提交时间 */
    public function replace(int $id, int $fileId): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE submissions SET file_id = :file_id, last_submitted_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['file_id' => $fileId, 'id' => $id]);
    }
}

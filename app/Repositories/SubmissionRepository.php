<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
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

    /** 重新提交：替换文件、刷新最近提交时间，保留首次提交时间。已批改的提交不得调用（由控制器校验） */
    public function replace(int $id, int $fileId): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE submissions SET file_id = :file_id, last_submitted_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['file_id' => $fileId, 'id' => $id]);
    }

    /**
     * 教师批改：写入分数/评语并刷新批改时间。
     * 批改结果教师可随时修改（ graded_at 更新为最近一次批改时间）；
     * 锁定只针对学生——已批改的提交学生不能再重交（由控制器校验）。
     */
    public function grade(int $id, ?int $score, ?string $comment): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE submissions SET score = :score, comment = :comment, graded_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'score' => $score,
            'comment' => $comment === '' ? null : $comment,
            'id' => $id,
        ]);
    }

    public function countByAssignment(int $assignmentId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM submissions WHERE assignment_id = :assignment_id');
        $stmt->execute(['assignment_id' => $assignmentId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 教师端：某作业的提交列表（含学生信息、文件元数据、实时迟交计算）。
     *
     * @return array<int, array>
     */
    public function listByAssignment(int $assignmentId, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT s.*, st.student_no, st.name AS student_name, f.original_name, f.size, a.deadline_at,
                    ' . self::LATE_EXPR . ' AS is_late
             FROM submissions s
             INNER JOIN students st ON st.id = s.student_id
             INNER JOIN files f ON f.id = s.file_id
             INNER JOIN assignments a ON a.id = s.assignment_id
             WHERE s.assignment_id = :assignment_id
             ORDER BY st.student_no';
        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->bindValue('assignment_id', $assignmentId);
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

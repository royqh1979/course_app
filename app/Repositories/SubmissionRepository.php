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

    /** 首次提交：插入新记录（小组作业带 group_id） */
    public function insert(int $assignmentId, int $studentId, int $fileId, ?int $groupId = null): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO submissions (assignment_id, student_id, group_id, file_id)
             VALUES (:assignment_id, :student_id, :group_id, :file_id)'
        );
        $stmt->execute([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'group_id' => $groupId,
            'file_id' => $fileId,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    /** 小组作业的组提交（含 files 元数据与实时计算的 is_late） */
    public function findByAssignmentAndGroup(int $assignmentId, int $groupId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.*, f.original_name, f.stored_path, f.mime_type, f.size, a.deadline_at,
                    ' . self::LATE_EXPR . ' AS is_late
             FROM submissions s
             INNER JOIN files f ON f.id = s.file_id
             INNER JOIN assignments a ON a.id = s.assignment_id
             WHERE s.assignment_id = :assignment_id AND s.group_id = :group_id
             LIMIT 1'
        );
        $stmt->execute(['assignment_id' => $assignmentId, 'group_id' => $groupId]);
        $row = $stmt->fetch();
        return $row ?: null;
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
     * 提交情况统计明细：以当前课程名单为基准（含未注册学生）。
     * 名单移除学生的历史提交保留但不出现在统计中。
     *
     * @return array<int, array>
     */
    public function statsByAssignment(int $assignmentId, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT r.student_no, r.name AS roster_name, r.class_name,
                       st.id AS student_id,
                       s.id AS submission_id, s.first_submitted_at, s.last_submitted_at,
                       s.graded_at, s.score,
                       f.original_name, f.size,
                       ' . self::LATE_EXPR . ' AS is_late
                FROM course_roster r
                INNER JOIN assignments a ON a.id = :assignment_id
                LEFT JOIN students st ON st.student_no = r.student_no AND st.name = r.name
                LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = st.id
                LEFT JOIN files f ON f.id = s.file_id
                WHERE r.course_id = a.course_id
                ORDER BY r.student_no';
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

    /** 统计汇总：名单总人数 / 已提交 / 迟交（迟交实时计算） */
    public function statsSummary(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) AS roster_total,
                    SUM(s.id IS NOT NULL) AS submitted,
                    SUM(' . self::LATE_EXPR . ') AS late
             FROM course_roster r
             INNER JOIN assignments a ON a.id = :assignment_id
             LEFT JOIN students st ON st.student_no = r.student_no AND st.name = r.name
             LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = st.id
             WHERE r.course_id = a.course_id'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        $row = $stmt->fetch();
        return [
            'total' => (int) $row['roster_total'],
            'submitted' => (int) $row['submitted'],
            'late' => (int) $row['late'],
        ];
    }

    /**
     * ZIP 打包数据源：当前作业的全部有效提交（含已从名单移除学生的提交）。
     * 班级取名单实时匹配，未匹配为空字符串。
     *
     * @return array<int, array>
     */
    public function listAllForExport(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.id, f.stored_path, f.original_name, f.size,
                    st.student_no, st.name AS student_name,
                    (SELECT r.class_name FROM course_roster r
                     WHERE r.course_id = a.course_id AND r.student_no = st.student_no AND r.name = st.name
                     LIMIT 1) AS class_name
             FROM submissions s
             INNER JOIN assignments a ON a.id = s.assignment_id
             INNER JOIN students st ON st.id = s.student_id
             INNER JOIN files f ON f.id = s.file_id
             WHERE s.assignment_id = :assignment_id
             ORDER BY st.student_no'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        return $stmt->fetchAll();
    }

    // ---- 小组作业：按组聚合（成绩共享给全组） ----

    /**
     * 小组作业统计明细（名单基准、每名学生一行）：提交状态/迟交/分数取所在组的提交。
     *
     * @return array<int, array>
     */
    public function statsByAssignmentGroup(int $assignmentId, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT r.student_no, r.name AS roster_name, r.class_name, st.id AS student_id,
                       g.id AS group_id, g.name AS group_name,
                       s.id AS submission_id, s.first_submitted_at, s.last_submitted_at,
                       s.graded_at, s.score,
                       f.original_name, f.size,
                       ' . self::LATE_EXPR . ' AS is_late
                FROM course_roster r
                INNER JOIN assignments a ON a.id = :assignment_id
                LEFT JOIN students st ON st.student_no = r.student_no AND st.name = r.name
                LEFT JOIN course_group_members m ON m.student_id = st.id AND m.status = \'active\'
                LEFT JOIN course_groups g ON g.id = m.group_id AND g.course_id = a.course_id
                LEFT JOIN submissions s ON s.assignment_id = a.id AND s.group_id = g.id
                LEFT JOIN files f ON f.id = s.file_id
                WHERE r.course_id = a.course_id
                ORDER BY r.student_no';
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

    /** 小组作业统计汇总：组提交存在则全组计为已提交 */
    public function statsSummaryGroup(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) AS roster_total,
                    SUM(s.id IS NOT NULL) AS submitted,
                    SUM(' . self::LATE_EXPR . ') AS late
             FROM course_roster r
             INNER JOIN assignments a ON a.id = :assignment_id
             LEFT JOIN students st ON st.student_no = r.student_no AND st.name = r.name
             LEFT JOIN course_group_members m ON m.student_id = st.id AND m.status = \'active\'
             LEFT JOIN course_groups g ON g.id = m.group_id AND g.course_id = a.course_id
             LEFT JOIN submissions s ON s.assignment_id = a.id AND s.group_id = g.id
             WHERE r.course_id = a.course_id'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        $row = $stmt->fetch();
        return [
            'total' => (int) $row['roster_total'],
            'submitted' => (int) $row['submitted'],
            'late' => (int) $row['late'],
        ];
    }

    /** 批改页（小组作业）：按组聚合的提交列表，含成员名单 */
    public function listGroupByAssignment(int $assignmentId, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT s.*, g.name AS group_name, st.student_no, st.name AS student_name,
                       f.original_name, f.size, a.deadline_at, ' . self::LATE_EXPR . ' AS is_late,
                       (SELECT GROUP_CONCAT(CONCAT(mst.student_no, \' \', mst.name) ORDER BY mst.student_no SEPARATOR \'、\')
                        FROM course_group_members cm INNER JOIN students mst ON mst.id = cm.student_id
                        WHERE cm.group_id = g.id AND cm.status = \'active\') AS members
                FROM submissions s
                INNER JOIN course_groups g ON g.id = s.group_id
                INNER JOIN students st ON st.id = s.student_id
                INNER JOIN files f ON f.id = s.file_id
                INNER JOIN assignments a ON a.id = s.assignment_id
                WHERE s.assignment_id = :assignment_id
                ORDER BY g.id';
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

    public function countGroupByAssignment(int $assignmentId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM submissions WHERE assignment_id = :id AND group_id IS NOT NULL');
        $stmt->execute(['id' => $assignmentId]);
        return (int) $stmt->fetchColumn();
    }

    /** ZIP 打包数据源（小组作业）：每组一份，class_name 段用组名 */
    public function listGroupsForExport(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.id, f.stored_path, f.original_name, f.size,
                    g.name AS class_name, st.student_no, st.name AS student_name
             FROM submissions s
             INNER JOIN course_groups g ON g.id = s.group_id
             INNER JOIN students st ON st.id = s.student_id
             INNER JOIN files f ON f.id = s.file_id
             WHERE s.assignment_id = :assignment_id
             ORDER BY g.id'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        return $stmt->fetchAll();
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

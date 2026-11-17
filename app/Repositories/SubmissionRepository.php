<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

/**
 * 学生当前有效提交：每名学生对每项作业仅一条记录；一次提交可含多个文件（submission_files）。
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

    /** 提交的文件数 */
    private const FILE_COUNT_SQL = '(SELECT COUNT(*) FROM submission_files sfc WHERE sfc.submission_id = s.id)';
    /** 提交的文件名列表（按上传顺序） */
    private const FILE_NAMES_SQL =
        '(SELECT GROUP_CONCAT(f2.original_name ORDER BY sf2.id SEPARATOR \'、\')
          FROM submission_files sf2 INNER JOIN files f2 ON f2.id = sf2.file_id
          WHERE sf2.submission_id = s.id)';
    /** 提交的文件总大小 */
    private const FILE_SIZE_SQL =
        '(SELECT COALESCE(SUM(f3.size), 0)
          FROM submission_files sf3 INNER JOIN files f3 ON f3.id = sf3.file_id
          WHERE sf3.submission_id = s.id)';

    /** GROUP_CONCAT 默认 1024 字节会截断长文件名列表，统计/展示查询前调大 */
    private function allowLongConcat(): void
    {
        $this->db()->exec('SET SESSION group_concat_max_len = 1048576');
    }

    /** 按 ID 查询提交记录（不含文件信息） */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM submissions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 按 submission_files.id 查单个提交文件（含 files 元数据，供下载权限检查） */
    public function findFileById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT sf.id, sf.submission_id, sf.file_id, f.original_name, f.stored_path, f.mime_type, f.size
             FROM submission_files sf INNER JOIN files f ON f.id = sf.file_id
             WHERE sf.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 查询提交的文件列表（含 files 元数据与 submission_files.id，供下载/展示） */
    public function listFiles(int $submissionId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT sf.id, sf.file_id, f.original_name, f.stored_path, f.mime_type, f.size
             FROM submission_files sf INNER JOIN files f ON f.id = sf.file_id
             WHERE sf.submission_id = :id
             ORDER BY sf.id'
        );
        $stmt->execute(['id' => $submissionId]);
        return $stmt->fetchAll();
    }

    /**
     * 批量取多个提交的文件列表，按 submission_id 分组（统计/批改页展示用，避免 N+1）。
     *
     * @param array<int, int> $submissionIds
     * @return array<int, array<int, array>>
     */
    public function listFilesForSubmissions(array $submissionIds): array
    {
        $submissionIds = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = $this->db()->prepare(
            "SELECT sf.submission_id, sf.id, sf.file_id, f.original_name, f.stored_path, f.size
             FROM submission_files sf INNER JOIN files f ON f.id = sf.file_id
             WHERE sf.submission_id IN ({$placeholders})
             ORDER BY sf.id"
        );
        $stmt->execute($submissionIds);
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['submission_id']][] = $row;
        }
        return $grouped;
    }

    /** 含实时计算的 is_late（联 assignments 取 deadline_at） */
    public function findByAssignmentAndStudent(int $assignmentId, int $studentId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.*, a.deadline_at, ' . self::LATE_EXPR . ' AS is_late
             FROM submissions s
             INNER JOIN assignments a ON a.id = s.assignment_id
             WHERE s.assignment_id = :assignment_id AND s.student_id = :student_id
             LIMIT 1'
        );
        $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** 小组作业的组提交（含实时计算的 is_late） */
    public function findByAssignmentAndGroup(int $assignmentId, int $groupId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.*, a.deadline_at, ' . self::LATE_EXPR . ' AS is_late
             FROM submissions s
             INNER JOIN assignments a ON a.id = s.assignment_id
             WHERE s.assignment_id = :assignment_id AND s.group_id = :group_id
             LIMIT 1'
        );
        $stmt->execute(['assignment_id' => $assignmentId, 'group_id' => $groupId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 学生在某课程下全部提交的 Map（assignment_id => 提交行，含 is_late），
     * 供作业列表标注已交/未交：个人作业按 student_id，小组作业按其所在小组（未入组传 null）。
     *
     * @return array<int, array>
     */
    public function mapByCourseForStudent(int $courseId, int $studentId, ?int $groupId): array
    {
        $sql = 'SELECT s.assignment_id, s.last_submitted_at, a.deadline_at, '
            . self::LATE_EXPR . ' AS is_late
             FROM submissions s
             INNER JOIN assignments a ON a.id = s.assignment_id
             WHERE a.course_id = :course_id AND (s.student_id = :student_id';
        $params = ['course_id' => $courseId, 'student_id' => $studentId];
        if ($groupId !== null) {
            $sql .= ' OR s.group_id = :group_id';
            $params['group_id'] = $groupId;
        }
        $sql .= ')';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['assignment_id']] = $row;
        }
        return $map;
    }

    /** 首次提交：插入新记录（小组作业带 group_id），文件随后经 addFile 挂入 */
    public function insert(int $assignmentId, int $studentId, ?int $groupId = null): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO submissions (assignment_id, student_id, group_id)
             VALUES (:assignment_id, :student_id, :group_id)'
        );
        $stmt->execute([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'group_id' => $groupId,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    /** 挂载一个提交文件 */
    public function addFile(int $submissionId, int $fileId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO submission_files (submission_id, file_id) VALUES (:sid, :fid)'
        );
        $stmt->execute(['sid' => $submissionId, 'fid' => $fileId]);
        return (int) $this->db()->lastInsertId();
    }

    /** 便捷：插入提交并挂一个文件（单文件提交场景，含测试夹具） */
    public function insertWithFile(int $assignmentId, int $studentId, int $fileId, ?int $groupId = null): int
    {
        $id = $this->insert($assignmentId, $studentId, $groupId);
        $this->addFile($id, $fileId);
        return $id;
    }

    /** 清空提交的文件，返回被解除引用的旧 file_id 列表（由调用方 deleteIfUnused） */
    public function clearFiles(int $submissionId): array
    {
        $stmt = $this->db()->prepare('SELECT file_id FROM submission_files WHERE submission_id = :id');
        $stmt->execute(['id' => $submissionId]);
        $fileIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->db()->prepare('DELETE FROM submission_files WHERE submission_id = :id')->execute(['id' => $submissionId]);
        return $fileIds;
    }

    /** 重新提交：刷新最近提交时间，保留首次提交时间。已批改的提交不得调用（由控制器校验） */
    public function markResubmitted(int $id): void
    {
        $stmt = $this->db()->prepare('UPDATE submissions SET last_submitted_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
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
        $this->allowLongConcat();
        $sql = 'SELECT r.student_no, r.name AS roster_name, r.class_name,
                       st.id AS student_id,
                       s.id AS submission_id, s.first_submitted_at, s.last_submitted_at,
                       s.graded_at, s.score,
                       ' . self::FILE_COUNT_SQL . ' AS file_count,
                       ' . self::FILE_NAMES_SQL . ' AS file_names,
                       ' . self::FILE_SIZE_SQL . ' AS file_size,
                       ' . self::LATE_EXPR . ' AS is_late
                FROM course_roster r
                INNER JOIN assignments a ON a.id = :assignment_id
                LEFT JOIN students st ON st.student_no = r.student_no AND st.name = r.name
                LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = st.id
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
     * ZIP 打包数据源：当前作业的全部有效提交（含已从名单移除学生的提交），每文件一行。
     * 班级取名单实时匹配，未匹配为空字符串。
     *
     * @return array<int, array>
     */
    public function listAllForExport(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.id, sf.id AS submission_file_id, f.stored_path, f.original_name, f.size,
                    st.student_no, st.name AS student_name,
                    (SELECT r.class_name FROM course_roster r
                     WHERE r.course_id = a.course_id AND r.student_no = st.student_no AND r.name = st.name
                     LIMIT 1) AS class_name
             FROM submissions s
             INNER JOIN assignments a ON a.id = s.assignment_id
             INNER JOIN students st ON st.id = s.student_id
             INNER JOIN submission_files sf ON sf.submission_id = s.id
             INNER JOIN files f ON f.id = sf.file_id
             WHERE s.assignment_id = :assignment_id
             ORDER BY st.student_no, sf.id'
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
        $this->allowLongConcat();
        $sql = 'SELECT r.student_no, r.name AS roster_name, r.class_name, st.id AS student_id,
                       g.id AS group_id, g.name AS group_name,
                       s.id AS submission_id, s.first_submitted_at, s.last_submitted_at,
                       s.graded_at, s.score,
                       ' . self::FILE_COUNT_SQL . ' AS file_count,
                       ' . self::FILE_NAMES_SQL . ' AS file_names,
                       ' . self::FILE_SIZE_SQL . ' AS file_size,
                       ' . self::LATE_EXPR . ' AS is_late
                FROM course_roster r
                INNER JOIN assignments a ON a.id = :assignment_id
                LEFT JOIN students st ON st.student_no = r.student_no AND st.name = r.name
                LEFT JOIN course_group_members m ON m.student_id = st.id AND m.status = \'active\'
                LEFT JOIN course_groups g ON g.id = m.group_id AND g.course_id = a.course_id
                LEFT JOIN submissions s ON s.assignment_id = a.id AND s.group_id = g.id
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
        $this->allowLongConcat();
        $sql = 'SELECT s.*, g.name AS group_name, st.student_no, st.name AS student_name,
                       a.deadline_at, ' . self::LATE_EXPR . ' AS is_late,
                       ' . self::FILE_COUNT_SQL . ' AS file_count,
                       ' . self::FILE_NAMES_SQL . ' AS file_names,
                       ' . self::FILE_SIZE_SQL . ' AS file_size,
                       (SELECT GROUP_CONCAT(CONCAT(mst.student_no, \' \', mst.name) ORDER BY mst.student_no SEPARATOR \'、\')
                        FROM course_group_members cm INNER JOIN students mst ON mst.id = cm.student_id
                        WHERE cm.group_id = g.id AND cm.status = \'active\') AS members
                FROM submissions s
                INNER JOIN course_groups g ON g.id = s.group_id
                INNER JOIN students st ON st.id = s.student_id
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

    /** ZIP 打包数据源（小组作业）：每组每文件一行，class_name 段用组名 */
    public function listGroupsForExport(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.id, sf.id AS submission_file_id, f.stored_path, f.original_name, f.size,
                    g.name AS class_name, st.student_no, st.name AS student_name
             FROM submissions s
             INNER JOIN course_groups g ON g.id = s.group_id
             INNER JOIN students st ON st.id = s.student_id
             INNER JOIN submission_files sf ON sf.submission_id = s.id
             INNER JOIN files f ON f.id = sf.file_id
             WHERE s.assignment_id = :assignment_id
             ORDER BY g.id, sf.id'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        return $stmt->fetchAll();
    }

    /**
     * 教师端：某作业的提交列表（含学生信息、文件汇总、实时迟交计算）。
     *
     * @return array<int, array>
     */
    public function listByAssignment(int $assignmentId, ?Pagination $pagination = null): array
    {
        $this->allowLongConcat();
        $sql = 'SELECT s.*, st.student_no, st.name AS student_name, a.deadline_at,
                       ' . self::FILE_COUNT_SQL . ' AS file_count,
                       ' . self::FILE_NAMES_SQL . ' AS file_names,
                       ' . self::FILE_SIZE_SQL . ' AS file_size,
                       ' . self::LATE_EXPR . ' AS is_late
                FROM submissions s
                INNER JOIN students st ON st.id = s.student_id
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

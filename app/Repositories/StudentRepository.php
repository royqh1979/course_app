<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

final class StudentRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function findByStudentNo(string $studentNo): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM students WHERE student_no = :no LIMIT 1');
        $stmt->execute(['no' => $studentNo]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $studentNo, string $name, string $passwordHash): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO students (student_no, name, password_hash) VALUES (:no, :name, :hash)'
        );
        $stmt->execute(['no' => $studentNo, 'name' => $name, 'hash' => $passwordHash]);
        return (int) $this->db()->lastInsertId();
    }

    /** 仅更新哈希（password_needs_rehash 场景），不使其他会话失效 */
    public function rehashPassword(int $id, string $hash): void
    {
        $stmt = $this->db()->prepare('UPDATE students SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }

    /** 修改密码：提升 session_version，使该用户其他会话全部失效 */
    public function changePassword(int $id, string $hash): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE students SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id'
        );
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->db()->prepare('UPDATE students SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /** 学生账号筛选条件（listWithFilters / countWithFilters 共用） */
    private function filterWhere(string $keyword, string $status, bool $unmatchedOnly, array &$params): string
    {
        $sql = ' WHERE 1 = 1';
        if ($keyword !== '') {
            $sql .= ' AND (s.student_no LIKE :kw_no OR s.name LIKE :kw_name)';
            $params['kw_no'] = '%' . $keyword . '%';
            $params['kw_name'] = '%' . $keyword . '%';
        }
        if ($status !== '') {
            $sql .= ' AND s.status = :status';
            $params['status'] = $status;
        }
        if ($unmatchedOnly) {
            // 未匹配：学号与姓名同时不出现在任何课程名单中
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM course_roster r2
                                      WHERE r2.student_no = s.student_no AND r2.name = s.name)';
        }
        return $sql;
    }

    /** 筛选结果总数（分页用） */
    public function countWithFilters(string $keyword = '', string $status = '', bool $unmatchedOnly = false): int
    {
        $params = [];
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM students s' . $this->filterWhere($keyword, $status, $unmatchedOnly, $params)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 学生账号列表（教师端）：按姓名/学号关键字、账号状态、是否未匹配名单筛选。
     * 每个学生附带实时匹配到的课程名称（GROUP_CONCAT 子查询）。
     *
     * @return array<int, array>
     */
    public function listWithFilters(string $keyword = '', string $status = '', bool $unmatchedOnly = false, ?Pagination $pagination = null): array
    {
        $params = [];
        $sql = 'SELECT s.*,
                       (SELECT GROUP_CONCAT(c.name ORDER BY c.id SEPARATOR \'、\')
                        FROM course_roster r
                        INNER JOIN courses c ON c.id = r.course_id
                        WHERE r.student_no = s.student_no AND r.name = s.name) AS matched_courses,
                       (SELECT COUNT(*) FROM submissions sub WHERE sub.student_id = s.id) AS submission_count
                FROM students s'
                . $this->filterWhere($keyword, $status, $unmatchedOnly, $params)
                . ' ORDER BY s.student_no, s.id';

        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** 实时查询该学生匹配到的课程（含已归档课程，供教师查看） */
    public function matchedCourses(string $studentNo, string $name): array
    {
        $stmt = $this->db()->prepare(
            'SELECT c.*
             FROM courses c
             INNER JOIN course_roster r ON r.course_id = c.id
             WHERE r.student_no = :student_no AND r.name = :name
             ORDER BY c.id'
        );
        $stmt->execute(['student_no' => $studentNo, 'name' => $name]);
        return $stmt->fetchAll();
    }

    /** 是否匹配任何课程名单（学号 + 姓名同时匹配） */
    public function isMatchedToAnyRoster(string $studentNo, string $name): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM course_roster WHERE student_no = :student_no AND name = :name LIMIT 1'
        );
        $stmt->execute(['student_no' => $studentNo, 'name' => $name]);
        return (bool) $stmt->fetchColumn();
    }

    public function countSubmissions(int $id): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM submissions WHERE student_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /** 物理删除：仅允许用于未匹配名单且无提交记录的账号（由调用方校验） */
    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM students WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

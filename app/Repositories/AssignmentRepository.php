<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

final class AssignmentRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    /**
     * @param array{title: string, requirements: ?string, start_at: ?string, deadline_at: ?string,
     *              allowed_extensions: string, max_file_size: int, allow_late: bool} $data
     */
    public function create(int $courseId, array $data): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO assignments (course_id, title, requirements, start_at, deadline_at, allowed_extensions, max_file_size, allow_late, is_group)
             VALUES (:course_id, :title, :requirements, :start_at, :deadline_at, :allowed_extensions, :max_file_size, :allow_late, :is_group)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $data['title'],
            'requirements' => $data['requirements'] === '' ? null : $data['requirements'],
            'start_at' => $data['start_at'],
            'deadline_at' => $data['deadline_at'],
            'allowed_extensions' => $data['allowed_extensions'],
            'max_file_size' => $data['max_file_size'],
            'is_group' => !empty($data['is_group']) ? 1 : 0,
            'allow_late' => $data['allow_late'] ? 1 : 0,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    /** 全字段可编辑：任何状态、任何时间都可修改（含已过的截止时间），改后实时生效 */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE assignments
             SET title = :title, requirements = :requirements, start_at = :start_at, deadline_at = :deadline_at,
                 allowed_extensions = :allowed_extensions, max_file_size = :max_file_size, allow_late = :allow_late, is_group = :is_group
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $data['title'],
            'requirements' => $data['requirements'] === '' ? null : $data['requirements'],
            'start_at' => $data['start_at'],
            'deadline_at' => $data['deadline_at'],
            'allowed_extensions' => $data['allowed_extensions'],
            'max_file_size' => $data['max_file_size'],
            'is_group' => !empty($data['is_group']) ? 1 : 0,
            'allow_late' => $data['allow_late'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /** draft / published / closed */
    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->db()->prepare('UPDATE assignments SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM assignments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function countByCourse(int $courseId, bool $visibleToStudent = false): int
    {
        $sql = 'SELECT COUNT(*) FROM assignments WHERE course_id = :course_id';
        if ($visibleToStudent) {
            $sql .= " AND status IN ('published', 'closed')";
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(['course_id' => $courseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 课程作业列表。
     * $visibleToStudent=true 供学生端：已发布与已关闭（学生可回顾），不含草稿。
     *
     * @return array<int, array>
     */
    public function listByCourse(int $courseId, bool $visibleToStudent = false, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT * FROM assignments WHERE course_id = :course_id';
        if ($visibleToStudent) {
            $sql .= " AND status IN ('published', 'closed')";
        }
        $sql .= ' ORDER BY id DESC';
        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->bindValue('course_id', $courseId);
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

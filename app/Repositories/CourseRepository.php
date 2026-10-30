<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

final class CourseRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function create(string $name, ?string $code, string $term, ?string $description, ?int $groupMin = null, ?int $groupMax = null): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO courses (name, code, term, description, group_min_members, group_max_members)
             VALUES (:name, :code, :term, :description, :group_min, :group_max)'
        );
        $stmt->execute([
            'name' => $name,
            'code' => $code === '' ? null : $code,
            'term' => $term,
            'description' => $description === '' ? null : $description,
            'group_min' => $groupMin,
            'group_max' => $groupMax,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    public function update(int $id, string $name, ?string $code, string $term, ?string $description, ?int $groupMin = null, ?int $groupMax = null): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE courses SET name = :name, code = :code, term = :term, description = :description,
                group_min_members = :group_min, group_max_members = :group_max
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'code' => $code === '' ? null : $code,
            'term' => $term,
            'description' => $description === '' ? null : $description,
            'group_min' => $groupMin,
            'group_max' => $groupMax,
            'id' => $id,
        ]);
    }

    public function archive(int $id): void
    {
        $stmt = $this->db()->prepare("UPDATE courses SET status = 'archived' WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM courses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 全部课程数量（教师端分页用） */
    public function countAll(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    }

    /** @return array<int, array> 全部课程（教师端）；传入 $pagination 时按分页切片 */
    public function listAll(?Pagination $pagination = null): array
    {
        $sql = 'SELECT * FROM courses ORDER BY id DESC';
        if ($pagination === null) {
            return $this->db()->query($sql)->fetchAll();
        }
        $stmt = $this->db()->prepare($sql . ' LIMIT :limit OFFSET :offset');
        $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
        $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 学生课程权限实时查询：学号与姓名必须同时与名单一致，且课程未归档。
     * 名单变动后无需任何额外操作，下次查询即时生效。
     *
     * @return array<int, array>
     */
    public function listActiveByStudent(string $studentNo, string $studentName): array
    {
        $stmt = $this->db()->prepare(
            'SELECT c.*
             FROM courses c
             INNER JOIN course_roster r ON r.course_id = c.id
             WHERE r.student_no = :student_no
               AND r.name = :student_name
               AND c.status = \'active\'
             ORDER BY c.id DESC'
        );
        $stmt->execute(['student_no' => $studentNo, 'student_name' => $studentName]);
        return $stmt->fetchAll();
    }

    /** 服务端权限复检：学生是否能访问指定课程 */
    public function studentCanAccess(int $courseId, string $studentNo, string $studentName): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1
             FROM courses c
             INNER JOIN course_roster r ON r.course_id = c.id
             WHERE c.id = :course_id
               AND r.student_no = :student_no
               AND r.name = :student_name
               AND c.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'student_no' => $studentNo,
            'student_name' => $studentName,
        ]);
        return (bool) $stmt->fetchColumn();
    }
}

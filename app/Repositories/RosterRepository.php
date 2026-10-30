<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

final class RosterRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function add(int $courseId, string $studentNo, string $name, string $className): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO course_roster (course_id, student_no, name, class_name)
             VALUES (:course_id, :student_no, :name, :class_name)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'student_no' => $studentNo,
            'name' => $name,
            'class_name' => $className,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    public function remove(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM course_roster WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM course_roster WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array> */
    public function listByCourse(int $courseId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM course_roster WHERE course_id = :course_id ORDER BY student_no'
        );
        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll();
    }
}

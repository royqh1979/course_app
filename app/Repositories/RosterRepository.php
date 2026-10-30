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

    /** @return array<int, string> 该课程当前名单中的全部学号（供导入预校验区分新增/更新） */
    public function existingStudentNos(int $courseId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT student_no FROM course_roster WHERE course_id = :course_id'
        );
        $stmt->execute(['course_id' => $courseId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Excel 增量导入：新学号插入，已有学号更新姓名和班级，未出现的学生不移除。
     * 整体在一个事务中执行，失败时回滚。
     *
     * @param array<int, array{student_no: string, name: string, class_name: string}> $rows 已通过预校验的有效行
     * @return array{inserted: int, updated: int}
     */
    public function importBatch(int $courseId, array $rows): array
    {
        return Database::transaction(function () use ($courseId, $rows) {
            $stmt = $this->db()->prepare(
                'INSERT INTO course_roster (course_id, student_no, name, class_name)
                 VALUES (:course_id, :student_no, :name, :class_name)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), class_name = VALUES(class_name)'
            );
            $inserted = 0;
            $updated = 0;
            foreach ($rows as $row) {
                $stmt->execute([
                    'course_id' => $courseId,
                    'student_no' => $row['student_no'],
                    'name' => $row['name'],
                    'class_name' => $row['class_name'],
                ]);
                // MySQL ON DUPLICATE KEY UPDATE：1=新增，2=更新，0=内容未变化
                match ($stmt->rowCount()) {
                    1 => $inserted++,
                    2 => $updated++,
                    default => null,
                };
            }
            return ['inserted' => $inserted, 'updated' => $updated];
        });
    }
}

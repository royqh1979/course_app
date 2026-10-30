<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
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
}

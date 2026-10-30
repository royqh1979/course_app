<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

final class AdminRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM admins WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $username, string $passwordHash): int
    {
        $stmt = $this->db()->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :hash)');
        $stmt->execute(['username' => $username, 'hash' => $passwordHash]);
        return (int) $this->db()->lastInsertId();
    }

    /** 仅更新哈希（password_needs_rehash 场景），不使其他会话失效 */
    public function rehashPassword(int $id, string $hash): void
    {
        $stmt = $this->db()->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }

    /** 修改密码：提升 session_version，使该用户其他会话全部失效 */
    public function changePassword(int $id, string $hash): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE admins SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id'
        );
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }
}

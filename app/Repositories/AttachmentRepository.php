<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

final class AttachmentRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function add(int $assignmentId, int $fileId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO assignment_attachments (assignment_id, file_id) VALUES (:assignment_id, :file_id)'
        );
        $stmt->execute(['assignment_id' => $assignmentId, 'file_id' => $fileId]);
        return (int) $this->db()->lastInsertId();
    }

    public function remove(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM assignment_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** 含 files 元数据与归属作业 */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT a.*, f.original_name, f.stored_path, f.mime_type, f.size
             FROM assignment_attachments a INNER JOIN files f ON f.id = a.file_id
             WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array> */
    public function listByAssignment(int $assignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT a.*, f.original_name, f.size
             FROM assignment_attachments a INNER JOIN files f ON f.id = a.file_id
             WHERE a.assignment_id = :assignment_id ORDER BY a.id'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        return $stmt->fetchAll();
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

final class FileRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    /** @param array{original_name: string, stored_path: string, extension: string, mime_type: string, size: int, sha256: string} $meta */
    public function create(array $meta): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO files (original_name, stored_path, extension, mime_type, size, sha256)
             VALUES (:original_name, :stored_path, :extension, :mime_type, :size, :sha256)'
        );
        $stmt->execute([
            'original_name' => $meta['original_name'],
            'stored_path' => $meta['stored_path'],
            'extension' => $meta['extension'],
            'mime_type' => $meta['mime_type'],
            'size' => $meta['size'],
            'sha256' => $meta['sha256'],
        ]);
        return (int) $this->db()->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM files WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

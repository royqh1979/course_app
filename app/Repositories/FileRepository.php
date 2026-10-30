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

    /**
     * 文件被引用的次数（5 张引用表统算）。
     * 学生提交永不共享文件，但一并统计保证正确性。
     */
    public function countReferences(int $id): int
    {
        $stmt = $this->db()->prepare(
            'SELECT (SELECT COUNT(*) FROM handouts WHERE file_id = :id1)
                  + (SELECT COUNT(*) FROM assignment_attachments WHERE file_id = :id2)
                  + (SELECT COUNT(*) FROM submissions WHERE file_id = :id3)
                  + (SELECT COUNT(*) FROM library_handouts WHERE file_id = :id4)
                  + (SELECT COUNT(*) FROM library_assignment_attachments WHERE file_id = :id5)'
        );
        $stmt->execute(['id1' => $id, 'id2' => $id, 'id3' => $id, 'id4' => $id, 'id5' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 引用归零才删除 files 行并返回 stored_path（由调用方删磁盘文件）；
     * 仍被引用返回 null（保留文件）。
     */
    public function deleteIfUnused(int $id): ?string
    {
        if ($this->countReferences($id) > 0) {
            return null;
        }
        $file = $this->findById($id);
        if ($file === null) {
            return null;
        }
        $this->delete($id);
        return (string) $file['stored_path'];
    }
}

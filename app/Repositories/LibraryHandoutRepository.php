<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

/**
 * 备课区课件模板。导入课程 = 复制业务行、共享同一 files 行（file_id 引用）。
 */
final class LibraryHandoutRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function create(string $title, ?string $description, int $fileId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO library_handouts (title, description, file_id) VALUES (:title, :description, :file_id)'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'file_id' => $fileId,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    public function update(int $id, string $title, ?string $description): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE library_handouts SET title = :title, description = :description WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'id' => $id,
        ]);
    }

    public function replaceFile(int $id, int $fileId): void
    {
        $stmt = $this->db()->prepare('UPDATE library_handouts SET file_id = :file_id WHERE id = :id');
        $stmt->execute(['file_id' => $fileId, 'id' => $id]);
    }

    /** 含 files 元数据 */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT h.*, f.original_name, f.stored_path, f.mime_type, f.size
             FROM library_handouts h INNER JOIN files f ON f.id = h.file_id
             WHERE h.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM library_handouts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function countAll(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM library_handouts')->fetchColumn();
    }

    /** @return array<int, array> */
    public function listAll(?Pagination $pagination = null): array
    {
        $sql = 'SELECT h.*, f.original_name, f.size
                FROM library_handouts h INNER JOIN files f ON f.id = h.file_id
                ORDER BY h.id DESC';
        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

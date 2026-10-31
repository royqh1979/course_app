<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

final class HandoutRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    public function create(int $courseId, string $title, ?string $description, int $fileId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO handouts (course_id, title, description, file_id) VALUES (:course_id, :title, :description, :file_id)'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'file_id' => $fileId,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    public function update(int $id, string $title, ?string $description): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE handouts SET title = :title, description = :description WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'id' => $id,
        ]);
    }

    /** 替换讲义文件（先存新文件并调用本方法，成功后再由调用方清理旧文件） */
    public function replaceFile(int $id, int $fileId): void
    {
        $stmt = $this->db()->prepare('UPDATE handouts SET file_id = :file_id WHERE id = :id');
        $stmt->execute(['file_id' => $fileId, 'id' => $id]);
    }

    /** 发布/下线；发布时记录发布时间 */
    public function setStatus(int $id, string $status): void
    {
        $sql = $status === 'published'
            ? "UPDATE handouts SET status = :status, published_at = NOW() WHERE id = :id"
            : 'UPDATE handouts SET status = :status WHERE id = :id';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM handouts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** 含 files 元数据 */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT h.*, f.original_name, f.stored_path, f.mime_type, f.size
             FROM handouts h INNER JOIN files f ON f.id = h.file_id
             WHERE h.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function countByCourse(int $courseId, bool $publishedOnly = false): int
    {
        $sql = 'SELECT COUNT(*) FROM handouts h WHERE h.course_id = :course_id';
        if ($publishedOnly) {
            $sql .= " AND h.status = 'published'";
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(['course_id' => $courseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 课程讲义列表（含文件名）。
     * $publishedOnly=true 供学生端：仅已发布。
     *
     * @return array<int, array>
     */
    public function listByCourse(int $courseId, bool $publishedOnly = false, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT h.*, f.original_name, f.size
                FROM handouts h INNER JOIN files f ON f.id = h.file_id
                WHERE h.course_id = :course_id';
        if ($publishedOnly) {
            $sql .= " AND h.status = 'published'";
        }
        $sql .= ' ORDER BY h.title, h.id';
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

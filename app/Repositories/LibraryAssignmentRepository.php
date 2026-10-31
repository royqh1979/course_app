<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use App\Support\Pagination;
use PDO;

/**
 * 备课区作业模板（含附件）。不存开始/截止时间——导入课程后才设置。
 */
final class LibraryAssignmentRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    /**
     * @param array{title: string, requirements: ?string, allowed_extensions: string,
     *              max_file_size: int, allow_late: bool, folder_id?: ?int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO library_assignments (folder_id, title, requirements, allowed_extensions, max_file_size, allow_late)
             VALUES (:folder_id, :title, :requirements, :allowed_extensions, :max_file_size, :allow_late)'
        );
        $stmt->execute([
            'folder_id' => $data['folder_id'] ?? null,
            'title' => $data['title'],
            'requirements' => $data['requirements'] === '' || $data['requirements'] === null ? null : $data['requirements'],
            'allowed_extensions' => $data['allowed_extensions'],
            'max_file_size' => $data['max_file_size'],
            'allow_late' => $data['allow_late'] ? 1 : 0,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db()->prepare(
            'UPDATE library_assignments
             SET folder_id = :folder_id, title = :title, requirements = :requirements, allowed_extensions = :allowed_extensions,
                 max_file_size = :max_file_size, allow_late = :allow_late
             WHERE id = :id'
        );
        $stmt->execute([
            'folder_id' => $data['folder_id'] ?? null,
            'title' => $data['title'],
            'requirements' => $data['requirements'] === '' || $data['requirements'] === null ? null : $data['requirements'],
            'allowed_extensions' => $data['allowed_extensions'],
            'max_file_size' => $data['max_file_size'],
            'allow_late' => $data['allow_late'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM library_assignments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM library_assignments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function countAll(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM library_assignments')->fetchColumn();
    }

    /** 按文件夹 + 标题查重（存入备课区冲突检测用；NULL 文件夹 = 根目录） */
    public function findByFolderTitle(?int $folderId, string $title): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM library_assignments WHERE folder_id <=> :folder_id AND title = :title LIMIT 1');
        $stmt->execute(['folder_id' => $folderId, 'title' => $title]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array> */
    public function listAll(?Pagination $pagination = null): array
    {
        $sql = 'SELECT la.*,
                       (SELECT COUNT(*) FROM library_assignment_attachments a
                        WHERE a.library_assignment_id = la.id) AS attachment_count
                FROM library_assignments la ORDER BY la.title, la.id';
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

    public function countByFolder(?int $folderId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM library_assignments WHERE folder_id <=> :folder_id');
        $stmt->execute(['folder_id' => $folderId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array> */
    public function listByFolder(?int $folderId, ?Pagination $pagination = null): array
    {
        $sql = 'SELECT la.*,
                       (SELECT COUNT(*) FROM library_assignment_attachments a
                        WHERE a.library_assignment_id = la.id) AS attachment_count
                FROM library_assignments la
                WHERE la.folder_id <=> :folder_id
                ORDER BY la.title, la.id';
        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->bindValue('folder_id', $folderId);
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ---- 附件 ----

    public function addAttachment(int $libraryAssignmentId, int $fileId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO library_assignment_attachments (library_assignment_id, file_id) VALUES (:laid, :file_id)'
        );
        $stmt->execute(['laid' => $libraryAssignmentId, 'file_id' => $fileId]);
        return (int) $this->db()->lastInsertId();
    }

    public function removeAttachment(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM library_assignment_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** 含 files 元数据 */
    public function findAttachmentById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT a.*, f.original_name, f.stored_path, f.mime_type, f.size
             FROM library_assignment_attachments a INNER JOIN files f ON f.id = a.file_id
             WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array> */
    public function listAttachments(int $libraryAssignmentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT a.*, f.original_name, f.size
             FROM library_assignment_attachments a INNER JOIN files f ON f.id = a.file_id
             WHERE a.library_assignment_id = :laid ORDER BY a.id'
        );
        $stmt->execute(['laid' => $libraryAssignmentId]);
        return $stmt->fetchAll();
    }
}

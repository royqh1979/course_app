<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * 备课区文件夹：自引用树，item_type 区分课件（handout）/作业（assignment）两棵独立树。
 * 树深上限 3 层由业务层校验；删除仅限空文件夹（无子文件夹、无条目）。
 */
final class LibraryFolderRepository
{
    private const MAX_DEPTH = 3;

    /** 条目所在表（按文件夹类型） */
    private const ITEM_TABLES = ['handout' => 'library_handouts', 'assignment' => 'library_assignments'];

    private function db(): PDO
    {
        return Database::pdo();
    }

    public function create(?int $parentId, string $name, string $itemType): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO library_folders (parent_id, name, item_type) VALUES (:parent_id, :name, :item_type)'
        );
        $stmt->execute(['parent_id' => $parentId, 'name' => $name, 'item_type' => $itemType]);
        return (int) $this->db()->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->db()->prepare('UPDATE library_folders SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM library_folders WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM library_folders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 直接子文件夹 */
    public function childrenOf(?int $parentId, string $itemType): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM library_folders WHERE parent_id <=> :parent_id AND item_type = :item_type ORDER BY name'
        );
        $stmt->execute(['parent_id' => $parentId, 'item_type' => $itemType]);
        return $stmt->fetchAll();
    }

    /** 某类型全部文件夹（树渲染用） */
    public function listByType(string $itemType): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM library_folders WHERE item_type = :item_type ORDER BY name');
        $stmt->execute(['item_type' => $itemType]);
        return $stmt->fetchAll();
    }

    /** 文件夹深度（顶层文件夹 = 1），根（无文件夹）= 0 */
    public function depthOf(?int $folderId): int
    {
        $depth = 0;
        $current = $folderId;
        while ($current !== null) {
            $stmt = $this->db()->prepare('SELECT parent_id FROM library_folders WHERE id = :id');
            $stmt->execute(['id' => $current]);
            $parent = $stmt->fetchColumn();
            if ($parent === false) {
                break;
            }
            $depth++;
            $current = $parent !== null ? (int) $parent : null;
        }
        return $depth;
    }

    public function maxDepth(): int
    {
        return self::MAX_DEPTH;
    }

    /** 直接子文件夹数 */
    public function countChildren(int $id): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM library_folders WHERE parent_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /** 文件夹内直接条目数 */
    public function countItems(int $id, string $itemType): int
    {
        $table = self::ITEM_TABLES[$itemType] ?? throw new \InvalidArgumentException('未知条目类型');
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM {$table} WHERE folder_id = :id");
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    /** 各文件夹直接条目数（map: folder_id => count），树徽标用 */
    public function itemCountsByType(string $itemType): array
    {
        $table = self::ITEM_TABLES[$itemType] ?? throw new \InvalidArgumentException('未知条目类型');
        $stmt = $this->db()->query("SELECT folder_id, COUNT(*) AS c FROM {$table} WHERE folder_id IS NOT NULL GROUP BY folder_id");
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['folder_id']] = (int) $row['c'];
        }
        return $counts;
    }

    /** 根目录直接条目数 */
    public function countRootItems(string $itemType): int
    {
        $table = self::ITEM_TABLES[$itemType] ?? throw new \InvalidArgumentException('未知条目类型');
        return (int) $this->db()->query("SELECT COUNT(*) FROM {$table} WHERE folder_id IS NULL")->fetchColumn();
    }
}

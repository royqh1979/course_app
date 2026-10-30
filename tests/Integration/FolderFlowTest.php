<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\LibraryAssignmentRepository;
use App\Repositories\LibraryFolderRepository;
use App\Repositories\LibraryHandoutRepository;

/**
 * 备课区文件夹仓库：两棵按类型独立的树、深度限制、空文件夹删除限制、条目计数。
 */
final class FolderFlowTest extends DatabaseTestCase
{
    public function testCreateRenameAndDepthLimit(): void
    {
        $folders = new LibraryFolderRepository();

        $root = $folders->create(null, '第一章', 'handout');
        $this->assertSame(1, $folders->depthOf($root));

        $level2 = $folders->create($root, '课件', 'handout');
        $level3 = $folders->create($level2, '视频', 'handout');
        $this->assertSame(3, $folders->depthOf($level3));

        // 类型隔离：作业树不受影响
        $this->assertCount(0, $folders->listByType('assignment'));
        $this->assertCount(3, $folders->listByType('handout'));

        $folders->rename($root, '第1章（更新）');
        $this->assertSame('第1章（更新）', $folders->findById($root)['name']);

        // 子级查询
        $children = $folders->childrenOf($root, 'handout');
        $this->assertCount(1, $children);
        $this->assertSame($level2, (int) $children[0]['id']);
        $this->assertCount(0, $folders->childrenOf(null, 'assignment'));
    }

    public function testItemCountsAndDeleteConstraints(): void
    {
        $folders = new LibraryFolderRepository();
        $handouts = new LibraryHandoutRepository();
        $assignments = new LibraryAssignmentRepository();

        $root = $folders->create(null, '课件', 'handout');
        $sub = $folders->create($root, '子目录', 'handout');

        // 空文件夹可删
        $folders->delete($sub);
        $this->assertNull($folders->findById($sub));

        // 有子文件夹时不可删（业务层校验，仓库层验证计数）
        $sub = $folders->create($root, '子目录', 'handout');
        $this->assertSame(1, $folders->countChildren($root));

        // 条目计数：课件表
        $fileId = $this->createFile();
        $h1 = $handouts->create('课件一', null, $fileId, $root);
        $h2 = $handouts->create('课件二', null, $fileId, $sub);
        $h3 = $handouts->create('课件三', null, $fileId, null);
        $this->assertSame(1, $folders->countItems($root, 'handout'));
        $this->assertSame(1, $folders->countItems($sub, 'handout'));
        $this->assertSame(1, $folders->countRootItems('handout'));
        $counts = $folders->itemCountsByType('handout');
        $this->assertSame(1, $counts[$root]);
        $this->assertSame(1, $counts[$sub]);

        // 按文件夹过滤
        $this->assertCount(1, $handouts->listByFolder($root));
        $this->assertSame('课件一', $handouts->listByFolder($root)[0]['title']);
        $this->assertCount(1, $handouts->listByFolder(null));
        $this->assertSame('课件三', $handouts->listByFolder(null)[0]['title']);
        $this->assertSame(1, $handouts->countByFolder($sub));

        // 移动条目到根目录
        $handouts->update($h1, '课件一', null, null);
        $this->assertCount(0, $handouts->listByFolder($root));

        // 作业表 folder_id
        $a1 = $assignments->create([
            'title' => '作业一',
            'requirements' => null,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024,
            'allow_late' => true,
            'folder_id' => null,
        ]);
        $this->assertCount(1, $assignments->listByFolder(null));
        $assignments->update($a1, [
            'title' => '作业一',
            'requirements' => null,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024,
            'allow_late' => true,
            'folder_id' => null,
        ]);
        $this->assertNull($assignments->findById($a1)['folder_id']);
    }

    private function createFile(): int
    {
        $pdo = \App\Support\Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO files (original_name, stored_path, extension, mime_type, size) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['t.pdf', 'tmp/' . uniqid('', true) . '.pdf', 'pdf', 'application/pdf', 10]);
        return (int) $pdo->lastInsertId();
    }
}

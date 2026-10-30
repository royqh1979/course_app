<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\FileStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * 文件存储：校验顺序（后缀 → 大小 → MIME）、UUID v7 命名、元数据（验收标准 7、8 的核心逻辑）。
 * 使用临时目录，不依赖数据库。
 */
final class FileStorageTest extends TestCase
{
    private string $storageDir;
    private FileStorage $storage;

    /** @var array<int, string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/filestorage_test_' . bin2hex(random_bytes(4));
        $this->storage = new FileStorage($this->storageDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeDir($this->storageDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*', GLOB_MARK) ?: [] as $path) {
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** 伪造一个 $_FILES 项 */
    private function fakeUpload(string $contentPath, string $originalName, int $error = UPLOAD_ERR_OK): array
    {
        return [
            'name' => $originalName,
            'tmp_name' => $contentPath,
            'error' => $error,
            'size' => is_file($contentPath) ? filesize($contentPath) : 0,
        ];
    }

    private function makeZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'up_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('hello.txt', '作业内容');
        $zip->close();
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function makePdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'up_') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%fake pdf for test\n%%EOF");
        $this->tmpFiles[] = $path;
        return $path;
    }

    public function testStoreGeneratesUuidV7NameAndMetadata(): void
    {
        $meta = $this->storage->store($this->fakeUpload($this->makeZip(), '张三的作业.zip'), 'courses/1/assignments/2/submissions', ['zip'], 1024 * 1024);

        $this->assertSame('张三的作业.zip', $meta['original_name']);
        $this->assertSame('zip', $meta['extension']);
        $this->assertSame('application/zip', $meta['mime_type']);
        $this->assertGreaterThan(0, $meta['size']);
        $this->assertSame(64, strlen($meta['sha256']));

        // 磁盘文件名是 UUID（不是原始文件名），且保留白名单扩展名
        $basename = basename($meta['stored_path']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.zip$/', $basename);
        $this->assertStringNotContainsString('张三', $basename);
        $this->assertStringStartsWith('courses/1/assignments/2/submissions/', $meta['stored_path']);
        $this->assertFileExists($this->storage->absolutePath($meta['stored_path']));
    }

    public function testRejectsDisallowedExtension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不允许的文件类型');
        $this->storage->store($this->fakeUpload($this->makeZip(), 'evil.exe'), 'courses/1/handouts', ['zip', 'pdf'], 1024 * 1024);
    }

    public function testRejectsOversizedFile(): void
    {
        $zip = $this->makeZip();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('超过限制');
        $this->storage->store($this->fakeUpload($zip, 'big.zip'), 'courses/1/handouts', ['zip'], 10);
    }

    public function testRejectsMimeMismatch(): void
    {
        // 内容是 zip 却伪装成 pdf
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不符');
        $this->storage->store($this->fakeUpload($this->makeZip(), 'fake.pdf'), 'courses/1/handouts', ['pdf'], 1024 * 1024);
    }

    public function testRejectsUploadError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->store($this->fakeUpload('/nonexistent', 'a.zip', UPLOAD_ERR_NO_FILE), 'courses/1/handouts', ['zip'], 1024);
    }

    public function testPdfPassesMimeCheck(): void
    {
        $meta = $this->storage->store($this->fakeUpload($this->makePdf(), '讲义.pdf'), 'courses/1/handouts', ['pdf'], 1024 * 1024);
        $this->assertSame('application/pdf', $meta['mime_type']);
    }

    public function testDeleteRemovesFile(): void
    {
        $meta = $this->storage->store($this->fakeUpload($this->makeZip(), 'a.zip'), 'courses/1/handouts', ['zip'], 1024 * 1024);
        $absolute = $this->storage->absolutePath($meta['stored_path']);
        $this->assertFileExists($absolute);

        $this->storage->delete($meta['stored_path']);
        $this->assertFileDoesNotExist($absolute);
        // 再次删除不报错
        $this->storage->delete($meta['stored_path']);
    }

    public function testTransactionalDeletesQueuedFilesAfterSuccess(): void
    {
        $old = $this->storage->store($this->fakeUpload($this->makeZip(), 'old.zip'), 'courses/1/handouts', ['zip'], 1024 * 1024);
        $oldAbsolute = $this->storage->absolutePath($old['stored_path']);

        $this->storage->transactional(function () use ($old) {
            // 新文件落盘（登记）+ 旧文件登记待删
            $this->storage->store($this->fakeUpload($this->makeZip(), 'new.zip'), 'courses/1/handouts', ['zip'], 1024 * 1024);
            $this->storage->delete($old['stored_path']);
            $this->assertFileExists($oldAbsolute = $this->storage->absolutePath($old['stored_path']), '事务未提交前旧文件不删');
        });

        $this->assertFileDoesNotExist($oldAbsolute, '提交成功后旧文件才被删除');
    }

    public function testTransactionalCompensatesNewFilesOnException(): void
    {
        $old = $this->storage->store($this->fakeUpload($this->makeZip(), 'old.zip'), 'courses/1/handouts', ['zip'], 1024 * 1024);
        $oldAbsolute = $this->storage->absolutePath($old['stored_path']);
        $newAbsolute = null;

        try {
            $this->storage->transactional(function () use ($old, &$newAbsolute) {
                $meta = $this->storage->store($this->fakeUpload($this->makeZip(), 'new.zip'), 'courses/1/handouts', ['zip'], 1024 * 1024);
                $newAbsolute = $this->storage->absolutePath($meta['stored_path']);
                $this->storage->delete($old['stored_path']);
                throw new \RuntimeException('模拟 DB 写入失败');
            });
            $this->fail('应抛出异常');
        } catch (\RuntimeException) {
            // 预期
        }

        $this->assertFileDoesNotExist($newAbsolute, '异常时新建文件必须补偿删除');
        $this->assertFileExists($oldAbsolute, '异常时旧文件必须保留');
    }

    public function testTransactionalRejectsNesting(): void
    {
        $this->expectException(\LogicException::class);
        $this->storage->transactional(function () {
            $this->storage->transactional(function () {
            });
        });
    }
}

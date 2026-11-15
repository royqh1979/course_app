<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileRepository;
use App\Support\App;
use PHPUnit\Framework\TestCase;

final class MaintenanceScriptTest extends TestCase
{
    private string $storage;
    private string $backupDest;
    private string $logDir;

    protected function setUp(): void
    {
        $this->storage = rtrim((string) App::config('storage_path'), '/\\');
        $this->removeDir($this->storage);
        mkdir($this->storage, 0750, true);
        $this->makeWritableByWwwData($this->storage);

        $suffix = bin2hex(random_bytes(4));
        $this->backupDest = sys_get_temp_dir() . '/course_app_backup_test_' . $suffix;
        $this->logDir = sys_get_temp_dir() . '/course_app_log_test_' . $suffix;
        mkdir($this->backupDest, 0750, true);
        mkdir($this->logDir, 0750, true);
        $this->makeWritableByWwwData($this->backupDest);
        $this->makeWritableByWwwData($this->logDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storage);
        $this->removeDir($this->backupDest);
        $this->removeDir($this->logDir);
    }

    public function testOrphanCleanupKeepsNestedFilesRegisteredInDatabase(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $knownPath = "courses/999/assignments/888/submissions/known_{$suffix}.docx";
        $orphanPath = "courses/999/assignments/888/submissions/orphan_{$suffix}.docx";
        file_put_contents($this->absolutePath($knownPath), 'registered');
        file_put_contents($this->absolutePath($orphanPath), 'orphan');

        $files = new FileRepository();
        $fileId = $files->create([
            'original_name' => 'known.docx',
            'stored_path' => $knownPath,
            'extension' => 'docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 10,
            'sha256' => hash('sha256', 'registered'),
        ]);

        try {
            $result = $this->runApplicationCommand(
                [PHP_BINARY, 'scripts/check_orphan_files.php', '--delete'],
                ['APP_ENV' => 'test', 'DB_NAME' => App::config('db')['name']]
            );

            $this->assertSame(0, $result['status'], $result['output']);
            $this->assertStringContainsString('[孤立文件]（磁盘存在但 files 表无记录）：1 个', $result['output']);
            $this->assertStringContainsString($orphanPath, $result['output']);
            $this->assertStringNotContainsString('//', $result['output']);
            $this->assertFileExists($this->absolutePath($knownPath));
            $this->assertFileDoesNotExist($this->absolutePath($orphanPath));
        } finally {
            $files->delete($fileId);
        }
    }

    public function testBackupScriptCreatesVerifiableDatabaseAndStorageArchives(): void
    {
        $storedPath = 'courses/999/backup_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($this->absolutePath($storedPath), 'backup-marker');

        $result = $this->runBackup($this->backupDest, $this->logDir);
        $this->assertSame(0, $result['status'], $result['output']);

        $dbArchives = glob($this->backupDest . '/db_*.sql.gz') ?: [];
        $storageArchives = glob($this->backupDest . '/storage_*.tar.gz') ?: [];
        $this->assertCount(1, $dbArchives);
        $this->assertCount(1, $storageArchives);

        $sql = gzdecode((string) file_get_contents($dbArchives[0]));
        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE `files`', $sql);

        exec('tar -tzf ' . escapeshellarg($storageArchives[0]), $entries, $status);
        $this->assertSame(0, $status);
        $this->assertContains('storage/' . $storedPath, $entries);
        $this->assertSame([], glob($this->backupDest . '/.backup_*') ?: []);

        $log = (string) file_get_contents($this->logDir . '/course_app_backup.log');
        $this->assertStringContainsString($dbArchives[0], $log);
        $this->assertStringContainsString($storageArchives[0], $log);
    }

    public function testBackupScriptRejectsMissingOrUnwritableOutputDirectories(): void
    {
        $cases = ['backup' => '备份目录', 'log' => '日志目录'];
        foreach ($cases as $type => $label) {
            foreach (['missing' => '目录不存在', 'unwritable' => '目录不可写'] as $condition => $message) {
                $base = $this->backupDest . "/case_{$type}_{$condition}";
                $backup = $base . '/backup';
                $log = $base . '/log';
                mkdir($backup, 0750, true);
                mkdir($log, 0750, true);
                $this->makeWritableByWwwData($base);
                $this->makeWritableByWwwData($backup);
                $this->makeWritableByWwwData($log);

                $target = $type === 'backup' ? $backup : $log;
                if ($condition === 'missing') {
                    rmdir($target);
                } else {
                    chmod($target, 0550);
                }

                try {
                    $result = $this->runBackup($backup, $log);
                    $this->assertNotSame(0, $result['status'], "{$label}{$condition} 应失败");
                    $this->assertStringContainsString($message . '：' . $target, $result['output']);
                    $this->assertSame([], glob($base . '/*/db_*.sql.gz') ?: []);
                } finally {
                    if (is_dir($target)) {
                        chmod($target, 0750);
                    }
                }
            }
        }
    }

    private function runBackup(string $backupDest, string $logDir): array
    {
        return $this->runApplicationCommand(
            ['bash', 'scripts/backup_course_app.sh'],
            [
                'APP_ENV' => 'test',
                'DB_NAME' => App::config('db')['name'],
                'COURSE_APP_BACKUP_DEST' => $backupDest,
                'COURSE_APP_LOG_DIR' => $logDir,
            ]
        );
    }

    private function absolutePath(string $relative): string
    {
        $path = $this->storage . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->makeWritableByWwwData($dir);
        }
        return $path;
    }

    private function runApplicationCommand(array $command, array $env): array
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $wrapped = ['runuser', '-u', 'www-data', '--', 'env'];
            foreach ($env as $name => $value) {
                $wrapped[] = $name . '=' . $value;
            }
            return $this->runCommand(array_merge($wrapped, $command), []);
        }
        return $this->runCommand($command, $env);
    }

    private function runCommand(array $command, array $env): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            array_merge(getenv(), $env)
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return ['status' => $status, 'output' => $stdout . $stderr];
    }

    private function makeWritableByWwwData(string $path): void
    {
        chmod($path, 0750);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown($path, 'www-data');
            chgrp($path, 'www-data');
        }
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
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\App;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * 私有文件存储：磁盘文件名 = UUID v7 + 白名单校验后的扩展名。
 * 校验顺序：后缀白名单 → 大小限制 → MIME 检查（finfo）。
 * 所有私有文件经 PHP 下载程序输出，不暴露 UUID 文件名。
 */
final class FileStorage
{
    /** 各允许后缀对应的 MIME 白名单（finfo 检测结果） */
    private const MIME_MAP = [
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];

    public function __construct(private readonly string $storagePath)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) App::config('storage_path'));
    }

    public function absolutePath(string $storedPath): string
    {
        return rtrim($this->storagePath, '/\\') . '/' . $storedPath;
    }

    /**
     * 校验并保存上传文件，返回 files 表元数据。
     *
     * @param array $file $_FILES 中的一项
     * @param string $subdir 相对 storage/ 的目录，如 courses/15/handouts
     * @param array<int, string> $allowedExtensions 后缀白名单（小写、不含点）
     * @return array{original_name: string, stored_path: string, extension: string, mime_type: string, size: int, sha256: string}
     * @throws RuntimeException 校验或保存失败（消息可直接展示给用户）
     */
    public function store(array $file, string $subdir, array $allowedExtensions, int $maxSize): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器允许的上传大小。',
                UPLOAD_ERR_PARTIAL => '文件上传不完整，请重试。',
                default => '请选择要上传的文件。',
            });
        }

        $originalName = basename((string) $file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('不允许的文件类型：.' . ($extension === '' ? '（无扩展名）' : $extension)
                . '，允许：' . implode('、', array_map(fn ($e) => '.' . $e, $allowedExtensions)));
        }
        if ((int) $file['size'] > $maxSize) {
            throw new RuntimeException('文件大小超过限制（最大 ' . (int) round($maxSize / 1024 / 1024) . ' MB）。');
        }
        $mime = (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), (string) $file['tmp_name']) ?: '');
        if (!in_array($mime, self::MIME_MAP[$extension] ?? [], true)) {
            throw new RuntimeException("文件内容类型（{$mime}）与扩展名 .{$extension} 不符。");
        }

        $storedPath = trim($subdir, '/') . '/' . Uuid::uuid7()->toString() . '.' . $extension;
        $absolute = $this->absolutePath($storedPath);
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0750, true)) {
            throw new RuntimeException('服务器存储目录不可用，请联系管理员。');
        }
        if (!move_uploaded_file((string) $file['tmp_name'], $absolute)
            && !rename((string) $file['tmp_name'], $absolute)) {
            throw new RuntimeException('文件保存失败，请重试。');
        }
        // transactional 内：登记新文件，异常时补偿删除
        if ($this->pendingNew !== null) {
            $this->pendingNew[] = $storedPath;
        }

        return [
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'extension' => $extension,
            'mime_type' => $mime,
            'size' => (int) $file['size'],
            'sha256' => (string) hash_file('sha256', $absolute),
        ];
    }

    /** @var array<int, string>|null 操作中新建的文件（补偿删除用）；null 表示不在 transactional 内 */
    private ?array $pendingNew = null;

    /** @var array<int, string> 操作中登记待删的旧文件（提交成功后才删） */
    private array $pendingDelete = [];

    /**
     * 事务 + 补偿：回调内 store() 的新文件登记入册、delete() 延后执行；
     * 回调内 DB 写入须用 Database::transaction() 包裹。
     * 回调正常返回 → 统一删除待删旧文件；任何异常 → 删除本次新建文件（补偿），旧文件不动。
     * 极端情况（提交后进程被硬杀）残留孤儿文件，由孤立文件定期检查兜底。
     * 约定：凡伴随 DB 写入的文件操作必须走本方法。
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pendingNew !== null) {
            throw new \LogicException('FileStorage::transactional 不支持嵌套调用');
        }
        $this->pendingNew = [];
        $this->pendingDelete = [];
        try {
            $result = $work($this);
            $toDelete = $this->pendingDelete;
        } catch (\Throwable $e) {
            foreach ($this->pendingNew as $path) {
                $this->deleteNow($path);
            }
            throw $e;
        } finally {
            $this->pendingNew = null;
            $this->pendingDelete = [];
        }
        foreach ($toDelete as $path) {
            $this->deleteNow($path);
        }
        return $result;
    }

    /** 删除磁盘文件：transactional 内登记延后删除，否则立即删除 */
    public function delete(string $storedPath): void
    {
        if ($this->pendingNew !== null) {
            $this->pendingDelete[] = $storedPath;
            return;
        }
        $this->deleteNow($storedPath);
    }

    /** 立即删除磁盘文件（不存在时静默忽略） */
    private function deleteNow(string $storedPath): void
    {
        $absolute = $this->absolutePath($storedPath);
        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    /**
     * 以指定下载文件名输出文件（RFC 5987 处理中文名），随后终止请求。
     */
    public function output(string $storedPath, string $downloadName, string $mimeType = 'application/octet-stream'): void
    {
        $absolute = $this->absolutePath($storedPath);
        if (!is_file($absolute)) {
            http_response_code(404);
            echo '文件不存在或已被移除。';
            exit;
        }
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $downloadName);
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Content-Length: ' . filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
        exit;
    }
}

<?php

declare(strict_types=1);

/**
 * HTML 转义，防 XSS。所有输出到页面的用户内容必须经过本函数。
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** 文件大小人性化显示 */
function format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * 静态资源 URL（带版本号）：以 public/ 下相对路径引用，追加 ?v=filemtime。
 * 文件内容一变 URL 即变，浏览器必拉新版，配合 Nginx 对 assets 的 immutable 长缓存，
 * 既避免陈旧缓存（启发式缓存坑），又保留缓存效率。
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__, 2) . '/public/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '0';
    return $path . '?v=' . $version;
}

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

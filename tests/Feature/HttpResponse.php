<?php

declare(strict_types=1);

namespace App\Tests\Feature;

final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** 从 HTML 中提取 CSRF 隐藏域的值 */
    public function csrfToken(): ?string
    {
        if (preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/', $this->body, $m)) {
            return $m[1];
        }
        return null;
    }
}

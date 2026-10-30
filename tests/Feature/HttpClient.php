<?php

declare(strict_types=1);

namespace App\Tests\Feature;

/**
 * 基于 PHP 流的极简 HTTP 客户端：维护 Cookie 罐，不自动跟随重定向。
 * （本机 PHP 未启用 curl 扩展，故用 stream 实现。）
 */
final class HttpClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function get(string $path): HttpResponse
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, string> $data */
    public function post(string $path, array $data = []): HttpResponse
    {
        return $this->request('POST', $path, $data);
    }

    /** @param array<string, string>|null $data */
    public function request(string $method, string $path, ?array $data = null): HttpResponse
    {
        $headers = [];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $content = null;
        if ($data !== null) {
            $content = http_build_query($data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 15,
        ]]);

        $body = file_get_contents($this->baseUrl . $path, false, $context);
        $rawHeaders = $http_response_header ?? [];

        $status = 0;
        $responseHeaders = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $responseHeaders[strtolower($name)] = $value;

            if (strcasecmp($name, 'Set-Cookie') === 0) {
                [$pair] = explode(';', $value);
                [$cookieName, $cookieValue] = array_map('trim', explode('=', $pair, 2));
                if ($cookieValue === '' || $cookieValue === 'deleted') {
                    unset($this->cookies[$cookieName]);
                } else {
                    $this->cookies[$cookieName] = $cookieValue;
                }
            }
        }

        return new HttpResponse($status, $responseHeaders, $body === false ? '' : $body);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 分页值对象：页码越界自动回落到最后一页（clamp）。
 * 每页条数可选（白名单），未显式指定时按设备默认：手机 20、电脑 50。
 */
final class Pagination
{
    public const ALLOWED_PER_PAGE = [10, 20, 50, 100];
    public const PER_PAGE_MOBILE = 20;
    public const PER_PAGE_DESKTOP = 50;

    public readonly int $page;
    public readonly int $pages;

    public function __construct(
        int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min(max(1, $page), $this->pages);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    /** 手机 User-Agent 识别（常规正则；个别伪装 UA 以 per_page 显式参数为准） */
    public static function isMobile(string $userAgent): bool
    {
        return (bool) preg_match('/Mobi|Android|iPhone|iPad|iPod|Mobile|Windows Phone/i', $userAgent);
    }

    /** 解析每页条数：显式 per_page 白名单优先，否则按 UA（手机 20 / 电脑 50） */
    public static function resolvePerPage(mixed $perPageParam, string $userAgent): int
    {
        $value = (int) $perPageParam;
        if (in_array($value, self::ALLOWED_PER_PAGE, true)) {
            return $value;
        }
        return self::isMobile($userAgent) ? self::PER_PAGE_MOBILE : self::PER_PAGE_DESKTOP;
    }

    /** 从当前请求构造（total 由调用方查询提供） */
    public static function fromRequest(string $pageParam, int $total): self
    {
        return new self(
            (int) ($_GET[$pageParam] ?? 1),
            self::resolvePerPage($_GET['per_page'] ?? null, (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            $total
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Pagination;
use PHPUnit\Framework\TestCase;

/**
 * 分页组件：设备识别、每页条数解析（手机 20 / 电脑 50 / 显式白名单优先）、页码 clamp。
 */
final class PaginationTest extends TestCase
{
    public function testIsMobile(): void
    {
        $this->assertTrue(Pagination::isMobile('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'));
        $this->assertTrue(Pagination::isMobile('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Mobile Safari/537.36'));
        $this->assertTrue(Pagination::isMobile('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15'));
        $this->assertFalse(Pagination::isMobile('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'));
        $this->assertFalse(Pagination::isMobile('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15'));
        $this->assertFalse(Pagination::isMobile(''));
    }

    public function testResolvePerPageDefaultsByDevice(): void
    {
        $this->assertSame(20, Pagination::resolvePerPage(null, 'iPhone Mobile'));
        $this->assertSame(50, Pagination::resolvePerPage(null, 'Windows NT 10.0'));
        $this->assertSame(50, Pagination::resolvePerPage(null, ''));
    }

    public function testResolvePerPageExplicitWhitelist(): void
    {
        $this->assertSame(10, Pagination::resolvePerPage('10', ''));
        $this->assertSame(100, Pagination::resolvePerPage('100', 'iPhone Mobile'), '显式参数优先于设备默认');
        $this->assertSame(50, Pagination::resolvePerPage('15', ''), '非法值回落设备默认（电脑 50）');
        $this->assertSame(20, Pagination::resolvePerPage('999', 'iPhone Mobile'), '非法值回落设备默认（手机 20）');
    }

    public function testOffsetLimitAndPages(): void
    {
        $p = new Pagination(2, 20, 45);
        $this->assertSame(3, $p->pages);
        $this->assertSame(20, $p->offset());
        $this->assertSame(20, $p->limit());
    }

    public function testPageClampedToLastPage(): void
    {
        $p = new Pagination(99, 20, 45);
        $this->assertSame(3, $p->page, '越界页码回落到最后一页');

        $p = new Pagination(0, 20, 45);
        $this->assertSame(1, $p->page, '页码小于 1 回落到第一页');
    }

    public function testEmptyTotal(): void
    {
        $p = new Pagination(5, 20, 0);
        $this->assertSame(1, $p->pages);
        $this->assertSame(1, $p->page);
        $this->assertSame(0, $p->offset());
    }
}

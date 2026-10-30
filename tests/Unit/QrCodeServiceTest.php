<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\QrCodeService;
use PHPUnit\Framework\TestCase;

/**
 * 二维码生成（纯逻辑，不依赖数据库与网络）。
 */
final class QrCodeServiceTest extends TestCase
{
    public function testSvgOutputIsValidInlineSvg(): void
    {
        $svg = (new QrCodeService())->svg('http://192.168.1.10:8080/?r=register');

        $this->assertStringStartsWith('<svg', $svg, '应输出可直接内嵌 HTML 的 SVG（不含 XML 声明）');
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function testDifferentContentProducesDifferentSvg(): void
    {
        $service = new QrCodeService();
        $this->assertNotSame(
            $service->svg('http://a.example/?r=register'),
            $service->svg('http://b.example/?r=register')
        );
    }
}

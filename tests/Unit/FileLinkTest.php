<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\App;
use PHPUnit\Framework\TestCase;

final class FileLinkTest extends TestCase
{
    private string $relative;
    private string $absolute;

    protected function setUp(): void
    {
        $this->relative = 'file_link_test_' . bin2hex(random_bytes(4)) . '/讲义.pdf';
        $this->absolute = rtrim((string) App::config('storage_path'), '/\\') . '/' . $this->relative;
        mkdir(dirname($this->absolute), 0750, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->absolute)) {
            unlink($this->absolute);
        }
        if (is_dir(dirname($this->absolute))) {
            rmdir(dirname($this->absolute));
        }
    }

    public function testExistingFileRendersDownloadLink(): void
    {
        file_put_contents($this->absolute, 'pdf');
        $html = file_download_link('handout/download', 12, '讲义.pdf', $this->relative);
        $this->assertStringContainsString('href="?r=handout/download&id=12"', $html);
        $this->assertStringContainsString('>讲义.pdf</a>', $html);
        $this->assertStringNotContainsString('text-decoration-line-through', $html);
    }

    public function testMissingFileRendersStrikethroughWithLink(): void
    {
        $html = file_download_link('handout/download', 12, '<讲义>.pdf', $this->relative);
        $this->assertStringContainsString('link-primary', $html);
        $this->assertStringContainsString('text-decoration-line-through', $html);
        $this->assertStringNotContainsString('text-muted', $html);
        $this->assertStringContainsString('文件已缺失', $html);
        $this->assertStringContainsString('&lt;讲义&gt;.pdf', $html);
        $this->assertStringContainsString('href="?r=handout/download&id=12"', $html);
        $this->assertStringContainsString('text-decoration-line-through', file_download_link('handout/download', 12, '空路径.pdf', null));
    }
}

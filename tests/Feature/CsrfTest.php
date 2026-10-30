<?php

declare(strict_types=1);

namespace App\Tests\Feature;

/**
 * CSRF 防护：写操作缺少或携带错误 token 一律 403。
 */
final class CsrfTest extends FeatureTestCase
{
    public function testLoginWithoutCsrfTokenRejected(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);
        $client->get('/?r=login');

        $res = $client->post('/?r=login', [
            'user_type' => 'admin',
            'identifier' => 'teacher',
            'password' => 'admin-pass-123',
        ]);

        $this->assertSame(403, $res->status);
    }

    public function testLoginWithWrongCsrfTokenRejected(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);
        $client->get('/?r=login');

        $res = $client->post('/?r=login', [
            '_csrf' => str_repeat('a', 64),
            'user_type' => 'admin',
            'identifier' => 'teacher',
            'password' => 'admin-pass-123',
        ]);

        $this->assertSame(403, $res->status);
    }

    public function testAdminWriteWithoutCsrfTokenRejected(): void
    {
        $this->createAdmin();
        $client = new HttpClient(self::$baseUrl);
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');

        $res = $client->post('/?r=admin/courses/store', ['name' => '数据结构']);
        $this->assertSame(403, $res->status);
    }
}

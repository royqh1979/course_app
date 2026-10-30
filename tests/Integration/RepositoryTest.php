<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AdminRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Services\PasswordService;
use PDOException;

final class RepositoryTest extends DatabaseTestCase
{
    public function testAdminCreateAndFind(): void
    {
        $admins = new AdminRepository();
        $id = $admins->create('teacher', PasswordService::hash('password-123'));

        $found = $admins->findByUsername('teacher');
        $this->assertSame($id, (int) $found['id']);
        $this->assertSame('active', $found['status']);
        $this->assertNull($admins->findByUsername('nobody'));
    }

    public function testAdminUsernameUnique(): void
    {
        $admins = new AdminRepository();
        $admins->create('teacher', PasswordService::hash('password-123'));

        $this->expectException(PDOException::class);
        $admins->create('teacher', PasswordService::hash('password-456'));
    }

    public function testStudentStudentNoUnique(): void
    {
        $students = new StudentRepository();
        $students->create('2024001', '张三', PasswordService::hash('password-123'));

        $this->expectException(PDOException::class);
        $students->create('2024001', '李四', PasswordService::hash('password-123'));
    }

    public function testStudentNoKeepsLeadingZeros(): void
    {
        $students = new StudentRepository();
        $students->create('00123', '张三', PasswordService::hash('password-123'));

        $found = $students->findByStudentNo('00123');
        $this->assertSame('00123', $found['student_no'], '学号按字符串存储，前导零必须保留');
    }

    public function testRosterCourseStudentNoUnique(): void
    {
        $roster = new RosterRepository();
        $roster->add(1, '2024001', '张三', '计科2401');

        $this->expectException(PDOException::class);
        $roster->add(1, '2024001', '张三丰', '计科2402');
    }

    public function testChangePasswordBumpsSessionVersion(): void
    {
        $students = new StudentRepository();
        $id = $students->create('2024001', '张三', PasswordService::hash('password-123'));
        $before = (int) $students->findById($id)['session_version'];

        $students->changePassword($id, PasswordService::hash('new-password-123'));
        $after = $students->findById($id);

        $this->assertSame($before + 1, (int) $after['session_version']);
        $this->assertTrue(PasswordService::verify('new-password-123', $after['password_hash']));
    }

    public function testRehashDoesNotBumpSessionVersion(): void
    {
        $admins = new AdminRepository();
        $id = $admins->create('teacher', PasswordService::hash('password-123'));
        $before = (int) $admins->findById($id)['session_version'];

        $admins->rehashPassword($id, PasswordService::hash('password-123'));

        $this->assertSame($before, (int) $admins->findById($id)['session_version']);
    }
}

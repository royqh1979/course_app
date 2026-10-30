<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\LogRepository;

final class LogRepositoryTest extends DatabaseTestCase
{
    public function testAuthLogRecordsAllFields(): void
    {
        $logs = new LogRepository();
        $logs->addAuthLog('student', 7, '2024001', 'login', 'success', '10.0.0.1', 'TestAgent/1.0');

        $row = $this->pdo->query('SELECT * FROM auth_logs ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('student', $row['user_type']);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame('2024001', $row['identifier']);
        $this->assertSame('login', $row['action']);
        $this->assertSame('success', $row['result']);
        $this->assertSame('10.0.0.1', $row['ip']);
        $this->assertSame('TestAgent/1.0', $row['user_agent']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testAuditLogRecordsManagementAction(): void
    {
        $logs = new LogRepository();
        $logs->addAuditLog('admin', 1, 'course.create', 'course', '15', '课程：数据结构', '10.0.0.1');

        $row = $this->pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('admin', $row['actor_type']);
        $this->assertSame('course.create', $row['action']);
        $this->assertSame('course', $row['target_type']);
        $this->assertSame('15', $row['target_id']);
        $this->assertSame('课程：数据结构', $row['detail']);
    }

    public function testCountRecentFailuresOnlyCountsFailuresInWindow(): void
    {
        $logs = new LogRepository();
        $logs->addAuthLog('student', null, '2024001', 'login', 'fail', '10.0.0.1', '');
        $logs->addAuthLog('student', null, '2024001', 'login', 'fail', '10.0.0.1', '');
        $logs->addAuthLog('student', 1, '2024001', 'login', 'success', '10.0.0.1', '');
        $logs->addAuthLog('student', null, '2024002', 'login', 'fail', '10.0.0.1', '');

        $this->assertSame(2, $logs->countRecentFailures('student', '2024001', 600), '成功记录和其他学号不计入');

        // 超出时间窗的失败不计入
        $this->pdo->exec("UPDATE auth_logs SET created_at = NOW() - INTERVAL 1 HOUR WHERE identifier = '2024001'");
        $this->assertSame(0, $logs->countRecentFailures('student', '2024001', 600));
    }
}

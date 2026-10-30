<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;

/**
 * 教师端学生账号管理（第二阶段）：筛选、归档/恢复、重置密码、安全删除。
 */
final class AdminStudentTest extends FeatureTestCase
{
    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    /** 带 CSRF 的学生管理 POST 操作 */
    private function adminPost(HttpClient $client, string $route, array $data): HttpResponse
    {
        $page = $client->get('/?r=admin/students');
        return $client->post('/?r=' . $route, ['_csrf' => $page->csrfToken() ?? ''] + $data);
    }

    public function testStudentListRequiresAdmin(): void
    {
        $client = new HttpClient(self::$baseUrl);
        $res = $client->get('/?r=admin/students');
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
    }

    public function testFilterByKeywordStatusAndUnmatched(): void
    {
        $this->createStudent('2024001', '张三');
        $this->createStudent('2024002', '李四');
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $page = $client->get('/?r=admin/students');
        $this->assertStringContainsString('张三', $page->body);
        $this->assertStringContainsString('李四', $page->body);
        $this->assertStringContainsString('数据结构', $page->body);

        $unmatched = $client->get('/?r=admin/students&unmatched=1');
        $this->assertStringNotContainsString('2024001', $unmatched->body, '已匹配名单的学生不应出现在未匹配筛选中');
        $this->assertStringContainsString('2024002', $unmatched->body);

        $byKeyword = $client->get('/?r=admin/students&keyword=' . urlencode('张三'));
        $this->assertStringContainsString('2024001', $byKeyword->body);
        $this->assertStringNotContainsString('2024002', $byKeyword->body);
    }

    public function testArchiveAndRestoreOverHttp(): void
    {
        $studentId = $this->createStudent();
        $students = new StudentRepository();

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $this->adminPost($client, 'admin/students/archive', ['id' => (string) $studentId]);
        $this->assertSame('archived', $students->findById($studentId)['status']);

        // 归档学生不能登录
        $studentClient = new HttpClient(self::$baseUrl);
        $login = $this->login($studentClient, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('r=login', $login->header('location') ?? '');

        $this->adminPost($client, 'admin/students/restore', ['id' => (string) $studentId]);
        $this->assertSame('active', $students->findById($studentId)['status']);

        $login = $this->login($studentClient, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('r=student/home', $login->header('location') ?? '');
    }

    public function testResetPasswordOverHttp(): void
    {
        $studentId = $this->createStudent();
        $students = new StudentRepository();
        $oldHash = $students->findById($studentId)['password_hash'];

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);
        $res = $this->adminPost($client, 'admin/students/reset-password', ['id' => (string) $studentId]);
        $this->assertSame(302, $res->status);

        $student = $students->findById($studentId);
        $this->assertNotSame($oldHash, $student['password_hash']);

        // 重置后的新密码显示在管理页面上（仅一次）
        $page = $client->get('/?r=admin/students');
        $this->assertStringContainsString('新密码', $page->body);

        // 旧密码已失效
        $studentClient = new HttpClient(self::$baseUrl);
        $login = $this->login($studentClient, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('r=login', $login->header('location') ?? '');
    }

    public function testDeleteAllowedOnlyWhenUnmatchedAndNoSubmissions(): void
    {
        $students = new StudentRepository();
        $matchedId = $this->createStudent('2024001', '张三');
        $unmatchedId = $this->createStudent('2024002', '李四');
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');

        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        // 匹配名单的账号拒绝删除
        $this->adminPost($client, 'admin/students/delete', ['id' => (string) $matchedId]);
        $this->assertNotNull($students->findById($matchedId), '匹配名单的账号不能被物理删除');

        // 未匹配且无提交记录的账号可以删除
        $this->adminPost($client, 'admin/students/delete', ['id' => (string) $unmatchedId]);
        $this->assertNull($students->findById($unmatchedId));

        // 未匹配但有提交记录的账号拒绝删除
        $withSubmissionId = $this->createStudent('2024003', '王五');
        \App\Support\Database::pdo()
            ->prepare('INSERT INTO submissions (assignment_id, student_id, file_id) VALUES (1, :id, 1)')
            ->execute(['id' => $withSubmissionId]);
        $this->adminPost($client, 'admin/students/delete', ['id' => (string) $withSubmissionId]);
        $this->assertNotNull($students->findById($withSubmissionId), '有提交记录的账号不能被物理删除');
    }

    public function testStudentManagementActionsRequireCsrf(): void
    {
        $studentId = $this->createStudent();
        $client = new HttpClient(self::$baseUrl);
        $this->loginAdmin($client);

        $res = $client->post('/?r=admin/students/archive', ['id' => (string) $studentId]);
        $this->assertSame(403, $res->status);
        $this->assertSame('active', (new StudentRepository())->findById($studentId)['status']);
    }
}

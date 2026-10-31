<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Repositories\CourseRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryHandoutRepository;
use App\Repositories\RosterRepository;
use App\Support\App;

/**
 * 备课区 HTTP 流程：模板上传、导入课程（逻辑快照）、模板删除后课程副本可用、
 * 存回备课区、归档课程禁止导入、权限控制。
 */
final class LibraryFlowTest extends FeatureTestCase
{
    /** @var array<int, string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $storage = rtrim((string) App::config('storage_path'), '/\\');
        $this->removeDir($storage . '/courses');
        $this->removeDir($storage . '/library');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*', GLOB_MARK) ?: [] as $path) {
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makePdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lib_');
        file_put_contents($path, "%PDF-1.4\n%template\n%%EOF");
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function loginAdmin(HttpClient $client): void
    {
        $this->createAdmin();
        $this->login($client, 'admin', 'teacher', 'admin-pass-123');
    }

    /** 上传课件模板，返回模板 ID */
    private function uploadTemplate(HttpClient $admin, string $title = '第一章课件'): int
    {
        $page = $admin->get('/?r=admin/library/handouts');
        $admin->upload('/?r=admin/library/handouts/store', 'file', $this->makePdf(), '第一章.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'title' => $title,
            'description' => '模板说明',
        ]);
        return (int) (new LibraryHandoutRepository())->listAll()[0]['id'];
    }

    public function testLibraryRequiresAdmin(): void
    {
        $guest = new HttpClient(self::$baseUrl);
        $this->assertSame(302, $guest->get('/?r=admin/library/handouts')->status);

        $this->createStudent();
        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $res = $student->get('/?r=admin/library/handouts');
        $this->assertStringContainsString('r=login', $res->header('location') ?? '');
        $this->assertSame(403, $student->get('/?r=library/handout/download&id=1')->status);
    }

    public function testImportHandoutFlowAndTemplateIndependence(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        (new RosterRepository())->add($courseId, '2024001', '张三', '计科2401');
        $this->createStudent();

        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $templateId = $this->uploadTemplate($admin);

        // 导入选择页
        $select = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $this->assertSame(200, $select->status);
        $this->assertStringContainsString('第一章课件', $select->body);

        // 导入为草稿
        $res = $admin->post('/?r=admin/library/import', [
            '_csrf' => $select->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $templateId,
        ]);
        $this->assertSame(302, $res->status);

        $handouts = new HandoutRepository();
        $courseHandout = $handouts->listByCourse($courseId)[0];
        $this->assertSame('draft', $courseHandout['status'], '导入后为草稿');

        // 删除模板：课程副本不受影响
        $page = $admin->get('/?r=admin/library/handouts');
        $admin->post('/?r=admin/library/handouts/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $templateId,
        ]);
        $this->assertSame([], (new LibraryHandoutRepository())->listAll());

        // 发布课程副本，学生可见可下载（文件未被模板删除波及）
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->post('/?r=admin/handouts/publish', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $courseHandout['id'],
        ]);

        $student = new HttpClient(self::$baseUrl);
        $this->login($student, 'student', '2024001', 'student-pass-123');
        $this->assertStringContainsString('第一章课件', $student->get('/?r=student/course/handouts&id=' . $courseId)->body);
        $download = $student->get('/?r=handout/download&id=' . $courseHandout['id']);
        $this->assertSame(200, $download->status, '模板删除后课程副本仍可下载');
        $this->assertStringContainsString('%PDF-1.4', $download->body);
    }

    public function testTemplateReplaceDoesNotAffectImportedCopy(): void
    {
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $templateId = $this->uploadTemplate($admin);

        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $page = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $admin->post('/?r=admin/library/import', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $templateId,
        ]);
        $handouts = new HandoutRepository();
        $imported = $handouts->listByCourse($courseId)[0];
        $importedFileId = (int) $imported['file_id'];

        // 模板替换文件
        $edit = $admin->get('/?r=admin/library/handouts/edit&id=' . $templateId);
        $admin->upload('/?r=admin/library/handouts/replace', 'file', $this->makePdf(), '第二章新版.pdf', [
            '_csrf' => $edit->csrfToken() ?? '',
            'id' => (string) $templateId,
        ]);

        // 课程副本仍指向原文件（共享语义下旧文件被课程引用，不得删除）
        $after = $handouts->findById((int) $imported['id']);
        $this->assertSame($importedFileId, (int) $after['file_id'], '模板替换不影响已导入副本');
        $this->assertSame('第一章.pdf', $after['original_name']);
        // 模板侧已是新文件
        $template = (new LibraryHandoutRepository())->findById($templateId);
        $this->assertSame('第二章新版.pdf', $template['original_name']);
        // 课程副本可正常下载
        $this->assertSame(200, $admin->get('/?r=handout/download&id=' . $imported['id'])->status);
    }

    public function testSaveBackToLibrary(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 课程里上传一份讲义
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '课程内讲义.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'title' => '课程内沉淀的好讲义',
        ]);
        $handout = (new HandoutRepository())->listByCourse($courseId)[0];

        // 存回备课区
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $res = $admin->post('/?r=admin/library/save-back', [
            '_csrf' => $page->csrfToken() ?? '',
            'type' => 'handout',
            'id' => (string) $handout['id'],
        ]);
        $this->assertSame(302, $res->status);

        $library = (new LibraryHandoutRepository())->listAll();
        $this->assertCount(1, $library);
        $this->assertSame('课程内沉淀的好讲义', $library[0]['title']);

        // 存回后删除课程侧引用……此处验证共享：两处都指向同一文件，模板下载正常
        $this->assertSame(200, $admin->get('/?r=library/handout/download&id=' . $library[0]['id'])->status);
    }

    public function testImportBlockedForArchivedCourse(): void
    {
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $templateId = $this->uploadTemplate($admin);

        $courses = new CourseRepository();
        $courseId = $courses->create('数据结构', null, '', null);
        $courses->archive($courseId);

        $page = $admin->get('/?r=admin/library/handouts');
        $admin->post('/?r=admin/library/import', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $templateId,
        ]);
        $this->assertSame([], (new HandoutRepository())->listByCourse($courseId), '归档课程禁止导入');
    }

    public function testFolderOrganizeFlow(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        // 备课区两个列表页均正常渲染（历史 500 盲区）
        $handoutsPage = $admin->get('/?r=admin/library/handouts');
        $assignmentsPage = $admin->get('/?r=admin/library/assignments');
        $this->assertSame(200, $handoutsPage->status);
        $this->assertSame(200, $assignmentsPage->status);

        // 建课件文件夹「第1章」（根下），再建子文件夹「习题」
        $res = $admin->post('/?r=admin/library/folders/store', [
            '_csrf' => $handoutsPage->csrfToken() ?? '',
            'item_type' => 'handout',
            'parent_id' => '',
            'name' => '第1章',
        ]);
        $this->assertSame(302, $res->status);
        $folders = new \App\Repositories\LibraryFolderRepository();
        $chapter = $folders->listByType('handout')[0];
        $this->assertSame('第1章', $chapter['name']);

        $page = $admin->get('/?r=admin/library/handouts&folder_id=' . $chapter['id']);
        $res = $admin->post('/?r=admin/library/folders/store', [
            '_csrf' => $page->csrfToken() ?? '',
            'item_type' => 'handout',
            'parent_id' => (string) $chapter['id'],
            'name' => '习题',
        ]);
        $this->assertSame(302, $res->status);
        $sub = $folders->childrenOf((int) $chapter['id'], 'handout')[0];
        $this->assertSame('习题', $sub['name']);

        // 在子文件夹上传课件模板
        $page = $admin->get('/?r=admin/library/handouts&folder_id=' . $sub['id']);
        $admin->upload('/?r=admin/library/handouts/store', 'file', $this->makePdf(), '课件A.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'folder_id' => (string) $sub['id'],
            'title' => '课件A',
        ]);
        $repo = new LibraryHandoutRepository();
        $this->assertCount(1, $repo->listAll());
        $template = $repo->listAll()[0];
        $this->assertSame((int) $sub['id'], (int) $template['folder_id']);

        // 子文件夹列表只显示本文件夹内容；根目录看不到
        $page = $admin->get('/?r=admin/library/handouts&folder_id=' . $sub['id']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('课件A', $page->body);
        $rootPage = $admin->get('/?r=admin/library/handouts');
        $this->assertStringNotContainsString('课件A', $rootPage->body);
        $this->assertStringContainsString('第1章', $rootPage->body, '根目录显示子文件夹入口');

        // 树徽标：根目录显示递归计数 1
        $this->assertStringContainsString('>1</span>', $rootPage->body);

        // 作业树独立：课件文件夹不出现在作业页
        $assignmentsPage = $admin->get('/?r=admin/library/assignments');
        $this->assertStringNotContainsString('第1章', $assignmentsPage->body);

        // 深度限制：第3层下不能再建（chapter=1, sub=2, 再建=3 层允许，其下禁止）
        $deepPage = $admin->get('/?r=admin/library/handouts&folder_id=' . $sub['id']);
        $res = $admin->post('/?r=admin/library/folders/store', [
            '_csrf' => $deepPage->csrfToken() ?? '',
            'item_type' => 'handout',
            'parent_id' => (string) $sub['id'],
            'name' => '第3层',
        ]);
        $this->assertSame(302, $res->status);
        $level3 = $folders->childrenOf((int) $sub['id'], 'handout')[0];
        $deepPage = $admin->get('/?r=admin/library/handouts&folder_id=' . $level3['id']);
        $res = $admin->post('/?r=admin/library/folders/store', [
            '_csrf' => $deepPage->csrfToken() ?? '',
            'item_type' => 'handout',
            'parent_id' => (string) $level3['id'],
            'name' => '超限',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertCount(0, $folders->childrenOf((int) $level3['id'], 'handout'), '最多 3 层');

        // 非空文件夹拒绝删除；先移走条目、删除子树后可删
        $page = $admin->get('/?r=admin/library/handouts&folder_id=' . $chapter['id']);
        $admin->post('/?r=admin/library/folders/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $chapter['id'],
        ]);
        $this->assertNotNull($folders->findById((int) $chapter['id']), '非空文件夹不可删除');

        // 模板移到根目录后，子树逐级删除成功
        $edit = $admin->get('/?r=admin/library/handouts/edit&id=' . $template['id']);
        $admin->post('/?r=admin/library/handouts/update', [
            '_csrf' => $edit->csrfToken() ?? '',
            'id' => (string) $template['id'],
            'title' => '课件A',
            'folder_id' => '',
        ]);
        $page = $admin->get('/?r=admin/library/handouts&folder_id=' . $level3['id']);
        $admin->post('/?r=admin/library/folders/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $level3['id'],
        ]);
        $page = $admin->get('/?r=admin/library/handouts&folder_id=' . $chapter['id']);
        $admin->post('/?r=admin/library/folders/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $sub['id'],
        ]);
        $page = $admin->get('/?r=admin/library/handouts');
        $admin->post('/?r=admin/library/folders/delete', [
            '_csrf' => $page->csrfToken() ?? '',
            'id' => (string) $chapter['id'],
        ]);
        $this->assertCount(0, $folders->listByType('handout'), '空文件夹可逐级删除');

        // 导入页按文件夹浏览
        $select = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $this->assertSame(200, $select->status);
        $this->assertStringContainsString('课件A', $select->body);
    }

    public function testImportBatchOverHttp(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $first = $this->uploadTemplate($admin, '第一章课件');
        $second = $this->uploadTemplate($admin, '第二章课件');

        // 导入选择页为复选框，可勾选多项
        $select = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $this->assertSame(200, $select->status);
        $this->assertStringContainsString('template_ids[]', $select->body);
        $this->assertStringContainsString('data-import-batch', $select->body);

        // XHR 单条导入（批量队列每次一个）返回 JSON
        $page = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $admin->xhr = true;
        $res = $admin->upload('/?r=admin/library/import', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_id' => (string) $first,
        ]);
        $admin->xhr = false;
        $json = json_decode($res->body, true);
        $this->assertSame(200, $res->status);
        $this->assertIsArray($json);
        $this->assertTrue($json['ok'], 'XHR 导入应返回 JSON 成功');

        // 无 JS 原生提交多选：仅导入第一个，并给出提示
        $admin->post('/?r=admin/library/import', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
            'template_ids' => [(string) $second, (string) $first],
        ]);
        $handouts = (new HandoutRepository())->listByCourse($courseId);
        $this->assertCount(2, $handouts, '原生提交一次仅导入第一个所选条目');
        $coursePage = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $this->assertStringContainsString('一次仅导入第一个', $coursePage->body, '无 JS 多选须给出提示');

        // 未选择任何条目：错误提示并回到导入页
        $empty = $admin->post('/?r=admin/library/import', [
            '_csrf' => $page->csrfToken() ?? '',
            'course_id' => (string) $courseId,
            'type' => 'handout',
        ]);
        $this->assertSame(302, $empty->status);
        $back = $admin->get('/?r=admin/library/import&course_id=' . $courseId . '&type=handout');
        $this->assertStringContainsString('请先选择要导入的内容', $back->body);
    }

    public function testSaveBackFolderAndConflictResolution(): void
    {
        $courseId = (new CourseRepository())->create('数据结构', null, '', null);
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);
        $page = $admin->get('/?r=admin/courses/handouts&id=' . $courseId);
        $csrf = $page->csrfToken() ?? '';
        $admin->upload('/?r=admin/handouts/store', 'file', $this->makePdf(), '冲突A.pdf', [
            '_csrf' => $csrf,
            'course_id' => (string) $courseId,
            'title' => '冲突测试A',
        ]);
        $handout = (new HandoutRepository())->listByCourse($courseId)[0];
        $folders = new \App\Repositories\LibraryFolderRepository();
        $folderId = $folders->create(null, '第1章', 'handout');
        $lib = new LibraryHandoutRepository();

        $admin->xhr = true;
        // 1) XHR 存入指定文件夹
        $res = $admin->upload('/?r=admin/library/save-back', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $csrf, 'type' => 'handout', 'id' => (string) $handout['id'], 'folder_id' => (string) $folderId,
        ]);
        $json = json_decode($res->body, true);
        $this->assertTrue($json['ok']);
        $items = $lib->listByFolder($folderId);
        $this->assertCount(1, $items);
        $firstId = (int) $items[0]['id'];

        // 2) 同名再存同文件夹 → 冲突 JSON
        $res = $admin->upload('/?r=admin/library/save-back', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $csrf, 'type' => 'handout', 'id' => (string) $handout['id'], 'folder_id' => (string) $folderId,
        ]);
        $json = json_decode($res->body, true);
        $this->assertFalse($json['ok']);
        $this->assertTrue($json['conflict'], '同名冲突须返回 conflict 标记');

        // 3) 覆盖 → 复用既有条目（同 id）
        $res = $admin->upload('/?r=admin/library/save-back', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $csrf, 'type' => 'handout', 'id' => (string) $handout['id'], 'folder_id' => (string) $folderId,
            'on_conflict' => 'overwrite',
        ]);
        $json = json_decode($res->body, true);
        $this->assertTrue($json['ok']);
        $items = $lib->listByFolder($folderId);
        $this->assertCount(1, $items);
        $this->assertSame($firstId, (int) $items[0]['id'], '覆盖应复用既有条目而非新建');

        // 4) 改名存入 → 新条目；再次撞名 → 报错
        $res = $admin->upload('/?r=admin/library/save-back', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $csrf, 'type' => 'handout', 'id' => (string) $handout['id'], 'folder_id' => (string) $folderId,
            'on_conflict' => 'rename', 'new_title' => '冲突测试A（2）',
        ]);
        $json = json_decode($res->body, true);
        $this->assertTrue($json['ok']);
        $this->assertCount(2, $lib->listByFolder($folderId));

        $res = $admin->upload('/?r=admin/library/save-back', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $csrf, 'type' => 'handout', 'id' => (string) $handout['id'], 'folder_id' => (string) $folderId,
            'on_conflict' => 'rename', 'new_title' => '冲突测试A（2）',
        ]);
        $json = json_decode($res->body, true);
        $this->assertFalse($json['ok']);

        // 5) 作业存入备课区（assignment 树独立、可指定文件夹）
        $assignmentId = (new \App\Repositories\AssignmentRepository())->create($courseId, [
            'title' => '作业冲突测试',
            'requirements' => null,
            'start_at' => null,
            'deadline_at' => null,
            'allowed_extensions' => 'zip',
            'max_file_size' => 1024,
            'allow_late' => true,
        ]);
        $aFolderId = $folders->create(null, '实验一', 'assignment');
        $res = $admin->upload('/?r=admin/library/save-back', 'ignored', $this->makePdf(), 'x.pdf', [
            '_csrf' => $csrf, 'type' => 'assignment', 'id' => (string) $assignmentId, 'folder_id' => (string) $aFolderId,
        ]);
        $json = json_decode($res->body, true);
        $this->assertTrue($json['ok']);
        $this->assertCount(1, (new \App\Repositories\LibraryAssignmentRepository())->listByFolder($aFolderId));
        $admin->xhr = false;
    }

    public function testAssignmentTemplatePagesRenderAndCreate(): void
    {
        $admin = new HttpClient(self::$baseUrl);
        $this->loginAdmin($admin);

        $create = $admin->get('/?r=admin/library/assignments/create');
        $this->assertSame(200, $create->status);
        $res = $admin->post('/?r=admin/library/assignments/store', [
            '_csrf' => $create->csrfToken() ?? '',
            'title' => '实验一：环境搭建',
            'requirements' => "完成环境搭建\n提交截图",
            'allowed_extensions' => ['zip', 'pdf'],
            'max_file_size_mb' => '10',
            'allow_late' => '1',
            'folder_id' => '',
        ]);
        $this->assertSame(302, $res->status);
        $list = (new \App\Repositories\LibraryAssignmentRepository())->listAll();
        $this->assertCount(1, $list);
        $this->assertSame('实验一：环境搭建', $list[0]['title']);

        $page = $admin->get('/?r=admin/library/assignments');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('实验一：环境搭建', $page->body);

        $edit = $admin->get('/?r=admin/library/assignments/edit&id=' . $list[0]['id']);
        $this->assertSame(200, $edit->status);
    }
}

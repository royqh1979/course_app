<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\GroupRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryFolderRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Services\AuthService;
use App\Support\Pagination;
use App\Support\Session;
use App\Support\View;

final class CourseController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly CourseRepository $courses,
        private readonly RosterRepository $roster,
        private readonly LogRepository $logs,
        private readonly HandoutRepository $handouts,
        private readonly AssignmentRepository $assignments,
        private readonly GroupRepository $groups,
        private readonly LibraryFolderRepository $libraryFolders,
    ) {
        parent::__construct($auth);
    }

    public function index(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $pagination = Pagination::fromRequest('page', $this->courses->countAll());
        View::render('admin/courses/index', [
            'title' => '课程管理',
            'courses' => $this->courses->listAll($pagination),
            'pagination' => $pagination,
        ]);
    }

    public function create(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        View::render('admin/courses/form', [
            'title' => '新建课程',
            'course' => null,
            'action' => '?r=admin/courses/store',
        ]);
    }

    public function store(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            Session::flash('error', '课程名称必填且不超过 100 字。');
            redirect('?r=admin/courses/create');
        }
        [$groupMin, $groupMax] = $this->readGroupLimits('?r=admin/courses/create');
        $id = $this->courses->create(
            $name,
            trim((string) ($_POST['code'] ?? '')),
            trim((string) ($_POST['term'] ?? '')),
            trim((string) ($_POST['description'] ?? '')),
            $groupMin,
            $groupMax
        );
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'course.create', 'course', (string) $id, "课程：{$name}", $this->ip());

        Session::flash('success', '课程已创建。');
        redirect('?r=admin/courses/handouts&id=' . $id);
    }

    public function edit(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $course = $this->courses->findById((int) ($_GET['id'] ?? 0));
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        View::render('admin/courses/form', [
            'title' => '编辑课程',
            'course' => $course,
            'action' => '?r=admin/courses/update',
        ]);
    }

    public function update(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $course = $this->courses->findById($id);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            Session::flash('error', '课程名称必填且不超过 100 字。');
            redirect('?r=admin/courses/edit&id=' . $id);
        }
        [$groupMin, $groupMax] = $this->readGroupLimits('?r=admin/courses/edit&id=' . $id);
        $this->courses->update(
            $id,
            $name,
            trim((string) ($_POST['code'] ?? '')),
            trim((string) ($_POST['term'] ?? '')),
            trim((string) ($_POST['description'] ?? '')),
            $groupMin,
            $groupMax
        );
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'course.update', 'course', (string) $id, "课程：{$name}", $this->ip());

        Session::flash('success', '课程已保存。');
        redirect('?r=admin/courses/handouts&id=' . $id);
    }

    /** 小组人数上下限：可选正整数，留空不限；非法则 flash 并跳转 */
    private function readGroupLimits(string $redirectOnError): array
    {
        $parse = function (string $key) use ($redirectOnError): ?int {
            $raw = trim((string) ($_POST[$key] ?? ''));
            if ($raw === '') {
                return null;
            }
            if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 999) {
                Session::flash('error', '小组人数限制须为 1–999 的整数，或留空不限。');
                redirect($redirectOnError);
            }
            return (int) $raw;
        };
        return [$parse('group_min_members'), $parse('group_max_members')];
    }

    public function archive(): void
    {
        $admin = $this->requireUser(AuthService::TYPE_ADMIN);
        $this->requireCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $course = $this->courses->findById($id);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        $this->courses->archive($id);
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'course.archive', 'course', (string) $id, "课程：{$course['name']}", $this->ip());

        Session::flash('success', '课程已归档，学生端不再显示，历史数据保留。');
        redirect('?r=admin/courses');
    }

    /** 课程详情旧入口：跳转到讲义页（各区块已拆分为独立页面） */
    public function show(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $id = (int) ($_GET['id'] ?? 0);
        if ($this->courses->findById($id) === null) {
            $this->notFound('课程不存在。');
        }
        redirect('?r=admin/courses/handouts&id=' . $id);
    }

    /** 课程讲义页（上传 + 列表） */
    public function handouts(): void
    {
        $data = $this->coursePageData('handouts');
        $id = (int) $data['course']['id'];
        $pagination = Pagination::fromRequest('page', $this->handouts->countByCourse($id));
        View::render('admin/courses/handouts', $data + [
            'handouts' => $this->handouts->listByCourse($id, false, $pagination),
            'pagination' => $pagination,
        ]);
    }

    /** 课程作业页（新建/导入 + 列表） */
    public function assignments(): void
    {
        $data = $this->coursePageData('assignments');
        $id = (int) $data['course']['id'];
        $pagination = Pagination::fromRequest('page', $this->assignments->countByCourse($id));
        View::render('admin/courses/assignments', $data + [
            'assignments' => $this->assignments->listByCourse($id, false, $pagination),
            'pagination' => $pagination,
        ]);
    }

    /** 课程名单页（导入/添加 + 列表） */
    public function roster(): void
    {
        $data = $this->coursePageData('roster');
        $id = (int) $data['course']['id'];
        $pagination = Pagination::fromRequest('page', $this->roster->countByCourse($id));
        View::render('admin/courses/roster', $data + [
            'roster' => $this->roster->listByCourse($id, $pagination),
            'pagination' => $pagination,
        ]);
    }

    /** 课程分组页（教师只读查看） */
    public function groups(): void
    {
        $data = $this->coursePageData('groups');
        $id = (int) $data['course']['id'];
        View::render('admin/courses/groups', $data + [
            'groups' => $this->groups->listByCourse($id),
            'groupMembers' => $this->groups->membersByCourse($id),
        ]);
    }

    /** 课程子页面公共数据：课程实体、tabs 计数、当前 tab */
    private function coursePageData(string $tab): array
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $id = (int) ($_GET['id'] ?? 0);
        $course = $this->courses->findById($id);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        return [
            'title' => $course['name'],
            'course' => $course,
            'tab' => $tab,
            'tabCounts' => [
                'handouts' => $this->handouts->countByCourse($id),
                'assignments' => $this->assignments->countByCourse($id),
                'roster' => $this->roster->countByCourse($id),
                'groups' => $this->groups->countByCourse($id),
            ],
            // 存入备课区模态框：两类型文件夹下拉（扁平缩进）
            'saveBackFolderOptions' => [
                'handout' => $this->folderOptions('handout'),
                'assignment' => $this->folderOptions('assignment'),
            ],
        ];
    }

    /** 该类型全部文件夹的扁平缩进列表（select 用）：[id, name, depth] */
    private function folderOptions(string $type): array
    {
        $byParent = [];
        foreach ($this->libraryFolders->listByType($type) as $folder) {
            $byParent[$folder['parent_id'] ?? 0][] = $folder;
        }
        $options = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$options, $byParent): void {
            foreach ($byParent[$parentId ?? 0] ?? [] as $folder) {
                $options[] = ['id' => (int) $folder['id'], 'name' => (string) $folder['name'], 'depth' => $depth];
                $walk((int) $folder['id'], $depth + 1);
            }
        };
        $walk(null, 1);
        return $options;
    }
}

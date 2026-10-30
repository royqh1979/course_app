<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AssignmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Services\AuthService;
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
    ) {
        parent::__construct($auth);
    }

    public function index(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        View::render('admin/courses/index', [
            'title' => '课程管理',
            'courses' => $this->courses->listAll(),
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
        $id = $this->courses->create(
            $name,
            trim((string) ($_POST['code'] ?? '')),
            trim((string) ($_POST['term'] ?? '')),
            trim((string) ($_POST['description'] ?? ''))
        );
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'course.create', 'course', (string) $id, "课程：{$name}", $this->ip());

        Session::flash('success', '课程已创建。');
        redirect('?r=admin/courses/show&id=' . $id);
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
        $this->courses->update(
            $id,
            $name,
            trim((string) ($_POST['code'] ?? '')),
            trim((string) ($_POST['term'] ?? '')),
            trim((string) ($_POST['description'] ?? ''))
        );
        $this->logs->addAuditLog('admin', (int) $admin['id'], 'course.update', 'course', (string) $id, "课程：{$name}", $this->ip());

        Session::flash('success', '课程已保存。');
        redirect('?r=admin/courses/show&id=' . $id);
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

    public function show(): void
    {
        $this->requireUser(AuthService::TYPE_ADMIN);
        $id = (int) ($_GET['id'] ?? 0);
        $course = $this->courses->findById($id);
        if ($course === null) {
            $this->notFound('课程不存在。');
        }
        View::render('admin/courses/show', [
            'title' => $course['name'],
            'course' => $course,
            'roster' => $this->roster->listByCourse($id),
            'handouts' => $this->handouts->listByCourse($id),
            'assignments' => $this->assignments->listByCourse($id),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CourseRepository;
use App\Services\AuthService;
use App\Support\View;

final class StudentController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly CourseRepository $courses,
    ) {
        parent::__construct($auth);
    }

    public function home(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        View::render('student/home', [
            'title' => '我的课程',
            'courses' => $this->courses->listActiveByStudent($user['student_no'], $user['name']),
        ]);
    }

    public function course(): void
    {
        $user = $this->requireUser(AuthService::TYPE_STUDENT);
        $id = (int) ($_GET['id'] ?? 0);
        $course = $this->courses->findById($id);

        // 服务端权限复检：不能只靠隐藏页面入口
        if ($course === null || !$this->courses->studentCanAccess($id, $user['student_no'], $user['name'])) {
            $this->forbidden('您没有访问该课程的权限。');
        }

        View::render('student/course', [
            'title' => $course['name'],
            'course' => $course,
        ]);
    }
}

<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\RosterController;
use App\Controllers\StudentController;
use App\Repositories\AdminRepository;
use App\Repositories\CourseRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Services\AuthService;
use App\Services\RateLimiter;
use App\Support\App;
use App\Support\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
App::init($config);
Session::start();

$admins = new AdminRepository();
$students = new StudentRepository();
$logs = new LogRepository();
$auth = new AuthService(
    $admins,
    $students,
    $logs,
    new RateLimiter(
        $logs,
        (int) App::config('auth.login_max_attempts', 5),
        (int) App::config('auth.login_window_seconds', 600)
    )
);
$courses = new CourseRepository();
$roster = new RosterRepository();

$authController = new AuthController($auth);
$courseController = new CourseController($auth, $courses, $roster, $logs);
$rosterController = new RosterController($auth, $roster, $courses, $logs);
$studentController = new StudentController($auth, $courses);

$home = static function () use ($auth): void {
    $user = $auth->currentUser();
    if ($user === null) {
        redirect('?r=login');
    }
    redirect($user['type'] === AuthService::TYPE_ADMIN ? '?r=admin/courses' : '?r=student/home');
};

$route = (string) ($_GET['r'] ?? 'home');
$method = (string) $_SERVER['REQUEST_METHOD'];

match ([$method, $route]) {
    ['GET', 'login'] => $authController->showLogin(),
    ['POST', 'login'] => $authController->login(),
    ['POST', 'logout'] => $authController->logout(),
    ['GET', 'password'] => $authController->showChangePassword(),
    ['POST', 'password'] => $authController->changePassword(),
    ['GET', 'home'] => $home(),
    ['GET', 'admin/courses'] => $courseController->index(),
    ['GET', 'admin/courses/create'] => $courseController->create(),
    ['POST', 'admin/courses/store'] => $courseController->store(),
    ['GET', 'admin/courses/edit'] => $courseController->edit(),
    ['POST', 'admin/courses/update'] => $courseController->update(),
    ['POST', 'admin/courses/archive'] => $courseController->archive(),
    ['GET', 'admin/courses/show'] => $courseController->show(),
    ['POST', 'admin/roster/add'] => $rosterController->add(),
    ['POST', 'admin/roster/delete'] => $rosterController->delete(),
    ['GET', 'student/home'] => $studentController->home(),
    ['GET', 'student/course'] => $studentController->course(),
    default => http_response_code(404),
};

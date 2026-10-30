<?php

declare(strict_types=1);

use App\Controllers\AdminStudentController;
use App\Controllers\AssignmentController;
use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\FileController;
use App\Controllers\HandoutController;
use App\Controllers\LibraryController;
use App\Controllers\QrCodeController;
use App\Controllers\RosterController;
use App\Controllers\StudentController;
use App\Repositories\AdminRepository;
use App\Repositories\AssignmentRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\FileRepository;
use App\Repositories\HandoutRepository;
use App\Repositories\LibraryAssignmentRepository;
use App\Repositories\LibraryHandoutRepository;
use App\Repositories\LogRepository;
use App\Repositories\RosterRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SubmissionRepository;
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
$handouts = new HandoutRepository();
$assignments = new AssignmentRepository();
$attachments = new AttachmentRepository();
$submissions = new SubmissionRepository();
$files = new FileRepository();
$libraryHandouts = new LibraryHandoutRepository();
$libraryAssignments = new LibraryAssignmentRepository();

$authController = new AuthController($auth, $students, $logs);
$courseController = new CourseController($auth, $courses, $roster, $logs, $handouts, $assignments);
$rosterController = new RosterController($auth, $roster, $courses, $logs);
$studentController = new StudentController($auth, $courses, $handouts, $assignments, $attachments, $submissions, $files);
$adminStudentController = new AdminStudentController($auth, $students, $logs);
$qrCodeController = new QrCodeController($auth);
$handoutController = new HandoutController($auth, $handouts, $courses, $files, $logs);
$assignmentController = new AssignmentController($auth, $assignments, $courses, $attachments, $files, $logs, $submissions);
$fileController = new FileController($auth, $handouts, $assignments, $attachments, $submissions, $courses, $libraryHandouts, $libraryAssignments);
$libraryController = new LibraryController($auth, $libraryHandouts, $libraryAssignments, $handouts, $assignments, $attachments, $courses, $files, $logs);

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
    ['GET', 'register'] => $authController->showRegister(),
    ['POST', 'register'] => $authController->register(),
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
    ['GET', 'admin/roster/template'] => $rosterController->template(),
    ['POST', 'admin/roster/import'] => $rosterController->import(),
    ['POST', 'admin/roster/import/confirm'] => $rosterController->confirmImport(),
    ['GET', 'admin/qrcode'] => $qrCodeController->show(),
    ['GET', 'admin/students'] => $adminStudentController->index(),
    ['POST', 'admin/students/archive'] => $adminStudentController->archive(),
    ['POST', 'admin/students/restore'] => $adminStudentController->restore(),
    ['POST', 'admin/students/reset-password'] => $adminStudentController->resetPassword(),
    ['POST', 'admin/students/delete'] => $adminStudentController->delete(),
    ['POST', 'admin/handouts/store'] => $handoutController->store(),
    ['GET', 'admin/handouts/edit'] => $handoutController->edit(),
    ['POST', 'admin/handouts/update'] => $handoutController->update(),
    ['POST', 'admin/handouts/replace'] => $handoutController->replace(),
    ['POST', 'admin/handouts/publish'] => $handoutController->publish(),
    ['POST', 'admin/handouts/offline'] => $handoutController->offline(),
    ['GET', 'admin/assignments/create'] => $assignmentController->create(),
    ['POST', 'admin/assignments/store'] => $assignmentController->store(),
    ['GET', 'admin/assignments/edit'] => $assignmentController->edit(),
    ['POST', 'admin/assignments/update'] => $assignmentController->update(),
    ['POST', 'admin/assignments/publish'] => $assignmentController->publish(),
    ['POST', 'admin/assignments/close'] => $assignmentController->close(),
    ['GET', 'admin/assignments/submissions'] => $assignmentController->submissions(),
    ['POST', 'admin/assignments/grade'] => $assignmentController->grade(),
    ['POST', 'admin/assignments/attachments/add'] => $assignmentController->addAttachment(),
    ['POST', 'admin/assignments/attachments/delete'] => $assignmentController->deleteAttachment(),
    ['GET', 'admin/library'] => $libraryController->index(),
    ['POST', 'admin/library/handouts/store'] => $libraryController->handoutStore(),
    ['GET', 'admin/library/handouts/edit'] => $libraryController->handoutEdit(),
    ['POST', 'admin/library/handouts/update'] => $libraryController->handoutUpdate(),
    ['POST', 'admin/library/handouts/replace'] => $libraryController->handoutReplace(),
    ['POST', 'admin/library/handouts/delete'] => $libraryController->handoutDelete(),
    ['GET', 'admin/library/assignments/create'] => $libraryController->assignmentCreate(),
    ['POST', 'admin/library/assignments/store'] => $libraryController->assignmentStore(),
    ['GET', 'admin/library/assignments/edit'] => $libraryController->assignmentEdit(),
    ['POST', 'admin/library/assignments/update'] => $libraryController->assignmentUpdate(),
    ['POST', 'admin/library/assignments/delete'] => $libraryController->assignmentDelete(),
    ['POST', 'admin/library/attachments/add'] => $libraryController->attachmentAdd(),
    ['POST', 'admin/library/attachments/delete'] => $libraryController->attachmentDelete(),
    ['GET', 'admin/library/import'] => $libraryController->importSelect(),
    ['POST', 'admin/library/import'] => $libraryController->import(),
    ['POST', 'admin/library/save-back'] => $libraryController->saveBack(),
    ['GET', 'library/handout/download'] => $fileController->libraryHandout(),
    ['GET', 'library/attachment/download'] => $fileController->libraryAttachment(),
    ['GET', 'handout/download'] => $fileController->handout(),
    ['GET', 'attachment/download'] => $fileController->attachment(),
    ['GET', 'submission/download'] => $fileController->submission(),
    ['GET', 'student/home'] => $studentController->home(),
    ['GET', 'student/course'] => $studentController->course(),
    ['GET', 'student/assignment'] => $studentController->assignment(),
    ['POST', 'student/submissions/submit'] => $studentController->submit(),
    default => http_response_code(404),
};

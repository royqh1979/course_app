<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\LogRepository;
use App\Repositories\StudentRepository;
use App\Support\Session;

final class AuthService
{
    public const TYPE_ADMIN = 'admin';
    public const TYPE_STUDENT = 'student';

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly StudentRepository $students,
        private readonly LogRepository $logs,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * 尝试登录。成功写入会话并重新生成 Session ID；所有结果记录 auth_logs。
     */
    public function attempt(string $userType, string $identifier, string $password, string $ip, string $userAgent): bool
    {
        if ($this->limiter->tooManyAttempts($userType, $identifier)) {
            $this->logs->addAuthLog($userType, null, $identifier, 'login', 'rate_limited', $ip, $userAgent);
            return false;
        }

        $user = $userType === self::TYPE_ADMIN
            ? $this->admins->findByUsername($identifier)
            : $this->students->findByStudentNo($identifier);

        if ($user === null || !PasswordService::verify($password, $user['password_hash'])) {
            $this->logs->addAuthLog($userType, $user['id'] ?? null, $identifier, 'login', 'fail', $ip, $userAgent);
            return false;
        }

        // 已归档学生不能登录（历史数据保留）
        if ($userType === self::TYPE_STUDENT && $user['status'] !== 'active') {
            $this->logs->addAuthLog($userType, (int) $user['id'], $identifier, 'login', 'archived', $ip, $userAgent);
            return false;
        }

        if (PasswordService::needsRehash($user['password_hash'])) {
            $hash = PasswordService::hash($password);
            $userType === self::TYPE_ADMIN
                ? $this->admins->rehashPassword((int) $user['id'], $hash)
                : $this->students->rehashPassword((int) $user['id'], $hash);
        }

        Session::regenerate();
        $_SESSION['user'] = [
            'type' => $userType,
            'id' => (int) $user['id'],
            'display_name' => $userType === self::TYPE_ADMIN ? $user['username'] : $user['name'],
            'session_version' => (int) $user['session_version'],
        ];
        $this->logs->addAuthLog($userType, (int) $user['id'], $identifier, 'login', 'success', $ip, $userAgent);
        return true;
    }

    public function logout(): void
    {
        $user = $_SESSION['user'] ?? null;
        if ($user !== null) {
            $this->logs->addAuthLog(
                $user['type'],
                (int) $user['id'],
                (string) $user['id'],
                'logout',
                'success',
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
        }
        Session::destroy();
    }

    /**
     * 修改密码：必须验证原密码；成功后提升 session_version 使其他会话失效，当前会话保持有效。
     */
    public function changePassword(string $userType, int $userId, string $oldPassword, string $newPassword, string $ip, string $userAgent): bool
    {
        $user = $userType === self::TYPE_ADMIN
            ? $this->admins->findById($userId)
            : $this->students->findById($userId);

        if ($user === null || !PasswordService::verify($oldPassword, $user['password_hash'])) {
            $this->logs->addAuthLog($userType, $userId, (string) $userId, 'password_change', 'fail', $ip, $userAgent);
            return false;
        }

        $hash = PasswordService::hash($newPassword);
        $userType === self::TYPE_ADMIN
            ? $this->admins->changePassword($userId, $hash)
            : $this->students->changePassword($userId, $hash);

        $fresh = $userType === self::TYPE_ADMIN
            ? $this->admins->findById($userId)
            : $this->students->findById($userId);
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['session_version'] = (int) $fresh['session_version'];
        }

        $this->logs->addAuthLog($userType, $userId, (string) $userId, 'password_change', 'success', $ip, $userAgent);
        return true;
    }

    /**
     * 当前登录用户。校验会话版本（改密后旧会话失效）与学生归档状态。
     */
    public function currentUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        if ($user === null) {
            return null;
        }
        $fresh = $user['type'] === self::TYPE_ADMIN
            ? $this->admins->findById((int) $user['id'])
            : $this->students->findById((int) $user['id']);

        if ($fresh === null || (int) $fresh['session_version'] !== (int) $user['session_version']) {
            Session::destroy();
            return null;
        }
        if ($user['type'] === self::TYPE_STUDENT && $fresh['status'] !== 'active') {
            Session::destroy();
            return null;
        }

        $user['display_name'] = $user['type'] === self::TYPE_ADMIN ? $fresh['username'] : $fresh['name'];
        $user['student_no'] = $fresh['student_no'] ?? null;
        $user['name'] = $fresh['name'] ?? null;
        return $user;
    }
}

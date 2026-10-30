<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuthLogCounter;
use App\Support\Database;
use PDO;

final class LogRepository implements AuthLogCounter
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    /** 认证日志：登录/退出/密码操作。禁止记录密码、密码哈希或完整 Session。 */
    public function addAuthLog(
        string $userType,
        ?int $userId,
        string $identifier,
        string $action,
        string $result,
        string $ip,
        string $userAgent
    ): void {
        $stmt = $this->db()->prepare(
            'INSERT INTO auth_logs (user_type, user_id, identifier, action, result, ip, user_agent)
             VALUES (:user_type, :user_id, :identifier, :action, :result, :ip, :user_agent)'
        );
        $stmt->execute([
            'user_type' => $userType,
            'user_id' => $userId,
            'identifier' => $identifier,
            'action' => $action,
            'result' => $result,
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
        ]);
    }

    /** 审计日志：课程、名单、账号等关键管理操作 */
    public function addAuditLog(
        string $actorType,
        int $actorId,
        string $action,
        string $targetType,
        string $targetId,
        ?string $detail,
        string $ip
    ): void {
        $stmt = $this->db()->prepare(
            'INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, detail, ip)
             VALUES (:actor_type, :actor_id, :action, :target_type, :target_id, :detail, :ip)'
        );
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail' => $detail,
            'ip' => $ip,
        ]);
    }

    /** 时间窗内同一账号的登录失败次数（供限流判定） */
    public function countRecentFailures(string $userType, string $identifier, int $windowSeconds): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM auth_logs
             WHERE user_type = :user_type
               AND identifier = :identifier
               AND result = 'fail'
               AND created_at >= (NOW() - INTERVAL :window SECOND)"
        );
        $stmt->bindValue('user_type', $userType);
        $stmt->bindValue('identifier', $identifier);
        $stmt->bindValue('window', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuthLogCounter;
use App\Support\Database;
use App\Support\Pagination;
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

    /** 认证日志分页列表（教师查看；可按用户类型/动作筛选） */
    public function listAuthLogs(string $userType = '', string $action = '', ?Pagination $pagination = null): array
    {
        $params = [];
        $where = $this->logWhere($userType, $action, $params);
        $sql = 'SELECT * FROM auth_logs' . $where . ' ORDER BY id DESC';
        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAuthLogs(string $userType = '', string $action = ''): int
    {
        $params = [];
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM auth_logs' . $this->logWhere($userType, $action, $params));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** 审计日志分页列表（可按动作筛选） */
    public function listAuditLogs(string $action = '', ?Pagination $pagination = null): array
    {
        $params = [];
        $sql = 'SELECT * FROM audit_logs';
        if ($action !== '') {
            $sql .= ' WHERE action = :action';
            $params['action'] = $action;
        }
        $sql .= ' ORDER BY id DESC';
        if ($pagination !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        if ($pagination !== null) {
            $stmt->bindValue('limit', $pagination->limit(), PDO::PARAM_INT);
            $stmt->bindValue('offset', $pagination->offset(), PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAuditLogs(string $action = ''): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*) FROM audit_logs';
        if ($action !== '') {
            $sql .= ' WHERE action = :action';
            $params['action'] = $action;
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, string> 审计日志中出现过的动作（筛选用） */
    public function distinctAuditActions(): array
    {
        return $this->db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function logWhere(string $userType, string $action, array &$params): string
    {
        $sql = ' WHERE 1 = 1';
        if ($userType !== '') {
            $sql .= ' AND user_type = :user_type';
            $params['user_type'] = $userType;
        }
        if ($action !== '') {
            $sql .= ' AND action = :action';
            $params['action'] = $action;
        }
        return $sql;
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

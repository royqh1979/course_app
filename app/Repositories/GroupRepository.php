<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * 课程级小组。不变式：
 * - 一个学生在同一课程最多属于一个组（pending 或 active 都算占用）；
 * - 组长自动持有 active 成员行；组长不能退出，只能转让或解散；
 * - 已有提交的小组不能解散（由控制器校验）。
 */
final class GroupRepository
{
    private function db(): PDO
    {
        return Database::pdo();
    }

    /** 建组：组行 + 组长 active 成员行（同一事务，由调用方包裹或此处自包） */
    public function create(int $courseId, string $name, int $leaderStudentId): int
    {
        return Database::transaction(function () use ($courseId, $name, $leaderStudentId) {
            $stmt = $this->db()->prepare(
                'INSERT INTO course_groups (course_id, name, leader_student_id) VALUES (:course_id, :name, :leader)'
            );
            $stmt->execute(['course_id' => $courseId, 'name' => $name, 'leader' => $leaderStudentId]);
            $groupId = (int) $this->db()->lastInsertId();
            $this->addMember($groupId, $leaderStudentId, 'active');
            return $groupId;
        });
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT g.*, st.student_no AS leader_no, st.name AS leader_name
             FROM course_groups g INNER JOIN students st ON st.id = g.leader_student_id
             WHERE g.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->db()->prepare('UPDATE course_groups SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public function transferLeader(int $id, int $newLeaderStudentId): void
    {
        $stmt = $this->db()->prepare('UPDATE course_groups SET leader_student_id = :leader WHERE id = :id');
        $stmt->execute(['leader' => $newLeaderStudentId, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM course_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** 课程小组列表（含组长信息、active/pending 人数），教师查看与学生申请列表共用 */
    public function listByCourse(int $courseId): array
    {
        $stmt = $this->db()->prepare(
            "SELECT g.*, st.student_no AS leader_no, st.name AS leader_name,
                    (SELECT COUNT(*) FROM course_group_members m WHERE m.group_id = g.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM course_group_members m WHERE m.group_id = g.id AND m.status = 'pending') AS pending_count
             FROM course_groups g INNER JOIN students st ON st.id = g.leader_student_id
             WHERE g.course_id = :course_id ORDER BY g.id"
        );
        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll();
    }

    public function countByCourse(int $courseId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM course_groups WHERE course_id = :course_id');
        $stmt->execute(['course_id' => $courseId]);
        return (int) $stmt->fetchColumn();
    }

    /** 某组 active 成员（含学生信息） */
    public function members(int $groupId, string $status = 'active'): array
    {
        $stmt = $this->db()->prepare(
            'SELECT m.*, st.student_no, st.name
             FROM course_group_members m INNER JOIN students st ON st.id = m.student_id
             WHERE m.group_id = :group_id AND m.status = :status
             ORDER BY m.id'
        );
        $stmt->execute(['group_id' => $groupId, 'status' => $status]);
        return $stmt->fetchAll();
    }

    public function countActiveMembers(int $groupId): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM course_group_members WHERE group_id = :group_id AND status = 'active'"
        );
        $stmt->execute(['group_id' => $groupId]);
        return (int) $stmt->fetchColumn();
    }

    /** 学生在某课程的成员行（任意状态；一课一组占用判断） */
    public function membershipInCourse(int $courseId, int $studentId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT m.*, g.course_id, g.name AS group_name, g.leader_student_id
             FROM course_group_members m INNER JOIN course_groups g ON g.id = m.group_id
             WHERE g.course_id = :course_id AND m.student_id = :student_id
             LIMIT 1'
        );
        $stmt->execute(['course_id' => $courseId, 'student_id' => $studentId]);
        return $stmt->fetch() ?: null;
    }

    /** 学生在某课程的 active 组（提交/展示用） */
    public function activeGroupOfStudent(int $courseId, int $studentId): ?array
    {
        $membership = $this->membershipInCourse($courseId, $studentId);
        if ($membership === null || $membership['status'] !== 'active') {
            return null;
        }
        return $this->findById((int) $membership['group_id']);
    }

    public function addMember(int $groupId, int $studentId, string $status): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO course_group_members (group_id, student_id, status) VALUES (:group_id, :student_id, :status)'
        );
        $stmt->execute(['group_id' => $groupId, 'student_id' => $studentId, 'status' => $status]);
        return (int) $this->db()->lastInsertId();
    }

    public function setMemberStatus(int $memberId, string $status): void
    {
        $stmt = $this->db()->prepare('UPDATE course_group_members SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $memberId]);
    }

    public function removeMember(int $memberId): void
    {
        $stmt = $this->db()->prepare('DELETE FROM course_group_members WHERE id = :id');
        $stmt->execute(['id' => $memberId]);
    }

    public function findMember(int $memberId): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM course_group_members WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $memberId]);
        return $stmt->fetch() ?: null;
    }

    /** 该组是否已有任何作业提交（解散保护） */
    public function hasSubmissions(int $groupId): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM submissions WHERE group_id = :group_id LIMIT 1');
        $stmt->execute(['group_id' => $groupId]);
        return (bool) $stmt->fetchColumn();
    }

    /** 教师查看：课程全部成员（含 pending），按组聚在视图层处理 */
    public function membersByCourse(int $courseId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT m.group_id, m.status, st.student_no, st.name
             FROM course_group_members m
             INNER JOIN course_groups g ON g.id = m.group_id
             INNER JOIN students st ON st.id = m.student_id
             WHERE g.course_id = :course_id
             ORDER BY m.group_id, m.status, m.id'
        );
        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll();
    }
}

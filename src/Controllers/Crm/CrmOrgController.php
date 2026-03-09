<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmOrgController
{
    /** @var array<int,string> */
    private const ALLOWED_TABLES = [
        'qiling_crm_departments',
        'qiling_crm_teams',
    ];

    public static function context(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.view');

        $uid = (int) ($user['id'] ?? 0);
        $scope = CrmSupport::userOrgScope($pdo, $uid);
        $teams = self::fetchUserTeams($pdo, $uid);

        Response::json([
            'user_id' => $uid,
            'scope' => $scope,
            'teams' => $teams,
        ]);
    }

    public static function departments(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.view');

        $status = CrmSupport::queryStr('status');
        $limit = CrmSupport::queryInt('limit') ?? 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT d.id, d.department_name, d.parent_id, d.manager_user_id, d.status, d.created_at, d.updated_at,
                       mu.username AS manager_username
                FROM qiling_crm_departments d
                LEFT JOIN qiling_users mu ON mu.id = d.manager_user_id
                WHERE 1 = 1';
        $params = [];
        if ($status !== '') {
            $sql .= ' AND d.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY d.id ASC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        Response::json(['data' => $rows]);
    }

    public static function upsertDepartment(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.edit');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $id = Request::int($data, 'id', 0);
        $departmentName = Request::str($data, 'department_name');
        if ($departmentName === '') {
            Response::json(['message' => 'department_name is required'], 422);
            return;
        }

        $parentId = Request::int($data, 'parent_id', 0);
        $managerUserId = Request::int($data, 'manager_user_id', 0);
        $status = CrmSupport::normalizeStatus(Request::str($data, 'status', 'active'), ['active', 'inactive'], 'active');
        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);

        if ($parentId > 0 && !self::recordExists($pdo, 'qiling_crm_departments', $parentId)) {
            Response::json(['message' => 'parent department not found'], 404);
            return;
        }
        if ($managerUserId > 0 && !self::activeUserExists($pdo, $managerUserId)) {
            Response::json(['message' => 'manager user not found'], 422);
            return;
        }

        if ($id > 0) {
            if (!self::recordExists($pdo, 'qiling_crm_departments', $id)) {
                Response::json(['message' => 'department not found'], 404);
                return;
            }
            $stmt = $pdo->prepare(
                'UPDATE qiling_crm_departments
                 SET department_name = :department_name,
                     parent_id = :parent_id,
                     manager_user_id = :manager_user_id,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'department_name' => $departmentName,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'manager_user_id' => $managerUserId > 0 ? $managerUserId : null,
                'status' => $status,
                'updated_at' => $now,
                'id' => $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_departments
                 (department_name, parent_id, manager_user_id, status, created_by, created_at, updated_at)
                 VALUES
                 (:department_name, :parent_id, :manager_user_id, :status, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute([
                'department_name' => $departmentName,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'manager_user_id' => $managerUserId > 0 ? $managerUserId : null,
                'status' => $status,
                'created_by' => $uid > 0 ? $uid : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        Audit::log($uid, 'crm.org.department.upsert', 'crm_department', $id, 'Upsert crm department', [
            'department_name' => $departmentName,
            'status' => $status,
        ]);
        Response::json(['department_id' => $id]);
    }

    public static function teams(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.view');

        $departmentId = CrmSupport::queryInt('department_id');
        $status = CrmSupport::queryStr('status');
        $limit = CrmSupport::queryInt('limit') ?? 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT t.id, t.team_name, t.department_id, t.leader_user_id, t.status, t.created_at, t.updated_at,
                       d.department_name,
                       lu.username AS leader_username
                FROM qiling_crm_teams t
                LEFT JOIN qiling_crm_departments d ON d.id = t.department_id
                LEFT JOIN qiling_users lu ON lu.id = t.leader_user_id
                WHERE 1 = 1';
        $params = [];
        if ($departmentId !== null && $departmentId > 0) {
            $sql .= ' AND t.department_id = :department_id';
            $params['department_id'] = $departmentId;
        }
        if ($status !== '') {
            $sql .= ' AND t.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY t.id ASC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        Response::json(['data' => $rows]);
    }

    public static function upsertTeam(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.edit');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $id = Request::int($data, 'id', 0);
        $teamName = Request::str($data, 'team_name');
        if ($teamName === '') {
            Response::json(['message' => 'team_name is required'], 422);
            return;
        }

        $departmentId = Request::int($data, 'department_id', 0);
        $leaderUserId = Request::int($data, 'leader_user_id', 0);
        $status = CrmSupport::normalizeStatus(Request::str($data, 'status', 'active'), ['active', 'inactive'], 'active');
        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);

        if ($departmentId > 0 && !self::recordExists($pdo, 'qiling_crm_departments', $departmentId)) {
            Response::json(['message' => 'department not found'], 404);
            return;
        }
        if ($leaderUserId > 0 && !self::activeUserExists($pdo, $leaderUserId)) {
            Response::json(['message' => 'leader user not found'], 422);
            return;
        }

        if ($id > 0) {
            if (!self::recordExists($pdo, 'qiling_crm_teams', $id)) {
                Response::json(['message' => 'team not found'], 404);
                return;
            }
            $stmt = $pdo->prepare(
                'UPDATE qiling_crm_teams
                 SET team_name = :team_name,
                     department_id = :department_id,
                     leader_user_id = :leader_user_id,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'team_name' => $teamName,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'leader_user_id' => $leaderUserId > 0 ? $leaderUserId : null,
                'status' => $status,
                'updated_at' => $now,
                'id' => $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_teams
                 (team_name, department_id, leader_user_id, status, created_by, created_at, updated_at)
                 VALUES
                 (:team_name, :department_id, :leader_user_id, :status, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute([
                'team_name' => $teamName,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'leader_user_id' => $leaderUserId > 0 ? $leaderUserId : null,
                'status' => $status,
                'created_by' => $uid > 0 ? $uid : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        Audit::log($uid, 'crm.org.team.upsert', 'crm_team', $id, 'Upsert crm team', [
            'team_name' => $teamName,
            'status' => $status,
        ]);
        Response::json(['team_id' => $id]);
    }

    public static function members(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.view');

        $teamId = CrmSupport::queryInt('team_id');
        $userId = CrmSupport::queryInt('user_id');
        $status = CrmSupport::queryStr('status');
        $limit = CrmSupport::queryInt('limit') ?? 500;
        $limit = max(1, min($limit, 2000));

        $sql = 'SELECT tm.id, tm.team_id, tm.user_id, tm.department_id, tm.member_role, tm.status, tm.joined_at, tm.created_at, tm.updated_at,
                       t.team_name, d.department_name, u.username, u.email
                FROM qiling_crm_team_members tm
                LEFT JOIN qiling_crm_teams t ON t.id = tm.team_id
                LEFT JOIN qiling_crm_departments d ON d.id = tm.department_id
                LEFT JOIN qiling_users u ON u.id = tm.user_id
                WHERE 1 = 1';
        $params = [];
        if ($teamId !== null && $teamId > 0) {
            $sql .= ' AND tm.team_id = :team_id';
            $params['team_id'] = $teamId;
        }
        if ($userId !== null && $userId > 0) {
            $sql .= ' AND tm.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($status !== '') {
            $sql .= ' AND tm.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY tm.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        Response::json(['data' => $rows]);
    }

    public static function upsertMember(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.org.edit');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $teamId = Request::int($data, 'team_id', 0);
        $userId = Request::int($data, 'user_id', 0);
        if ($teamId <= 0 || $userId <= 0) {
            Response::json(['message' => 'team_id and user_id are required'], 422);
            return;
        }
        if (!self::recordExists($pdo, 'qiling_crm_teams', $teamId)) {
            Response::json(['message' => 'team not found'], 404);
            return;
        }
        if (!self::activeUserExists($pdo, $userId)) {
            Response::json(['message' => 'user not found'], 422);
            return;
        }

        $departmentId = Request::int($data, 'department_id', 0);
        if ($departmentId <= 0) {
            $teamDeptStmt = $pdo->prepare('SELECT department_id FROM qiling_crm_teams WHERE id = :id LIMIT 1');
            $teamDeptStmt->execute(['id' => $teamId]);
            $departmentId = (int) $teamDeptStmt->fetchColumn();
        } elseif (!self::recordExists($pdo, 'qiling_crm_departments', $departmentId)) {
            Response::json(['message' => 'department not found'], 404);
            return;
        }

        $memberRole = Request::str($data, 'member_role', 'member');
        if (!in_array($memberRole, ['leader', 'member'], true)) {
            $memberRole = 'member';
        }
        $status = CrmSupport::normalizeStatus(Request::str($data, 'status', 'active'), ['active', 'inactive'], 'active');
        $joinedAt = CrmSupport::parseDateTime(Request::str($data, 'joined_at')) ?? gmdate('Y-m-d H:i:s');
        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_team_members
             (team_id, user_id, department_id, member_role, status, joined_at, created_by, created_at, updated_at)
             VALUES
             (:team_id, :user_id, :department_id, :member_role, :status, :joined_at, :created_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                department_id = VALUES(department_id),
                member_role = VALUES(member_role),
                status = VALUES(status),
                joined_at = VALUES(joined_at),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'user_id' => $userId,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'member_role' => $memberRole,
            'status' => $status,
            'joined_at' => $joinedAt,
            'created_by' => $uid > 0 ? $uid : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log($uid, 'crm.org.member.upsert', 'crm_team_member', 0, 'Upsert crm team member', [
            'team_id' => $teamId,
            'user_id' => $userId,
            'status' => $status,
        ]);
        Response::json([
            'team_id' => $teamId,
            'user_id' => $userId,
            'status' => $status,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchUserTeams(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT tm.team_id, tm.department_id, tm.member_role, tm.status,
                    t.team_name, t.department_id AS team_department_id,
                    d.department_name
             FROM qiling_crm_team_members tm
             LEFT JOIN qiling_crm_teams t ON t.id = tm.team_id
             LEFT JOIN qiling_crm_departments d ON d.id = COALESCE(tm.department_id, t.department_id)
             WHERE tm.user_id = :user_id
             ORDER BY tm.id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function recordExists(PDO $pdo, string $table, int $id): bool
    {
        if ($id <= 0 || !in_array($table, self::ALLOWED_TABLES, true)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function activeUserExists(PDO $pdo, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT id FROM qiling_users WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'status' => 'active',
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

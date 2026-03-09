<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmActivityController
{
    public static function activities(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.activities.view');
        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $manageAll = CrmService::canManageAll($user);
        $status = CrmSupport::queryStr('status');
        $entityType = CrmSupport::queryStr('entity_type');
        $entityId = CrmSupport::queryInt('entity_id');
        $scope = strtolower(CrmSupport::queryStr('scope'));
        if (!in_array($scope, ['', 'visible', 'mine', 'team', 'department', 'public'], true)) {
            $scope = '';
        }
        $visibilityLevel = strtolower(CrmSupport::queryStr('visibility_level'));
        if (!in_array($visibilityLevel, ['', 'private', 'team', 'department', 'public'], true)) {
            $visibilityLevel = '';
        }
        $dueFrom = CrmSupport::parseDateTime(CrmSupport::queryStr('due_from'));
        $dueTo = CrmSupport::parseDateTime(CrmSupport::queryStr('due_to'));

        $sql = 'SELECT a.*, ou.username AS owner_username, cu.username AS creator_username
                FROM qiling_crm_activities a
                LEFT JOIN qiling_users ou ON ou.id = a.owner_user_id
                LEFT JOIN qiling_users cu ON cu.id = a.created_by
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND a.owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $requestedOwnerId;
            }
        } else {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0 && $requestedOwnerId !== (int) ($user['id'] ?? 0)) {
                Response::json(['message' => 'forbidden: cross-owner query denied'], 403);
                return;
            }
            CrmSupport::appendVisibilityReadScope(
                $sql,
                $params,
                $pdo,
                $user,
                'a',
                true,
                $scope === 'mine'
            );
        }
        if (in_array($scope, ['team', 'department', 'public'], true)) {
            $sql .= ' AND a.visibility_level = :scope_visibility_level';
            $params['scope_visibility_level'] = $scope;
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND a.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND a.status = :status';
            $params['status'] = $status;
        }
        if ($entityType !== '') {
            $sql .= ' AND a.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($entityId !== null && $entityId > 0) {
            $sql .= ' AND a.entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }
        if ($dueFrom !== null) {
            $sql .= ' AND a.due_at >= :due_from';
            $params['due_from'] = $dueFrom;
        }
        if ($dueTo !== null) {
            $sql .= ' AND a.due_at <= :due_to';
            $params['due_to'] = $dueTo;
        }
        if ($cursor > 0) {
            $sql .= ' AND a.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function createActivity(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.activities.edit');
        $data = Request::jsonBody();

        $entityType = Request::str($data, 'entity_type');
        if (!in_array($entityType, ['lead', 'contact', 'company', 'deal'], true)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }
        $entityId = Request::int($data, 'entity_id', 0);
        if ($entityId <= 0) {
            Response::json(['message' => 'entity_id is required'], 422);
            return;
        }
        if (!CrmSupport::entityExists($pdo, $entityType, $entityId)) {
            Response::json(['message' => 'entity not found'], 404);
            return;
        }

        $ownerUserId = CrmService::resolveOwnerInput($pdo, $user, Request::int($data, 'owner_user_id', 0));
        $ownerOrg = CrmSupport::resolveOwnerOrgScope($pdo, $ownerUserId);
        $visibilityLevel = CrmSupport::normalizeVisibilityLevel(Request::str($data, 'visibility_level', 'private'));
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_activities
             (entity_type, entity_id, activity_type, subject, content, due_at, done_at, status, owner_user_id, owner_team_id, owner_department_id, visibility_level, created_by, created_at, updated_at)
             VALUES
             (:entity_type, :entity_id, :activity_type, :subject, :content, :due_at, :done_at, :status, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'activity_type' => CrmSupport::normalizeStatus(Request::str($data, 'activity_type', 'note'), ['note', 'call', 'email', 'meeting', 'task'], 'note'),
            'subject' => Request::str($data, 'subject'),
            'content' => Request::str($data, 'content'),
            'due_at' => CrmSupport::parseDateTime(Request::str($data, 'due_at')),
            'done_at' => null,
            'status' => CrmSupport::normalizeStatus(Request::str($data, 'status', 'todo'), ['todo', 'done', 'cancelled'], 'todo'),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'created_by' => (int) $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $activityId = (int) $pdo->lastInsertId();
        Audit::log((int) $user['id'], 'crm.activity.create', 'crm_activity', $activityId, 'Create crm activity', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'owner_user_id' => $ownerUserId,
        ]);

        Response::json(['activity_id' => $activityId], 201);
    }

    public static function updateActivityStatus(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.activities.edit');
        $data = Request::jsonBody();
        $activityId = Request::int($data, 'activity_id', 0);
        if ($activityId <= 0) {
            Response::json(['message' => 'activity_id is required'], 422);
            return;
        }
        $status = CrmSupport::normalizeStatus(Request::str($data, 'status'), ['todo', 'done', 'cancelled'], '');
        if ($status === '') {
            Response::json(['message' => 'status invalid'], 422);
            return;
        }

        $row = CrmSupport::findWritableRecord($pdo, 'qiling_crm_activities', $activityId, $user);
        if (!is_array($row)) {
            return;
        }

        $doneAt = $status === 'done'
            ? (CrmSupport::parseDateTime(Request::str($data, 'done_at')) ?? gmdate('Y-m-d H:i:s'))
            : null;
        $stmt = $pdo->prepare(
            'UPDATE qiling_crm_activities
             SET status = :status,
                 done_at = :done_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'done_at' => $doneAt,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $activityId,
        ]);

        Audit::log((int) $user['id'], 'crm.activity.status', 'crm_activity', $activityId, 'Update crm activity status', [
            'status' => $status,
        ]);

        Response::json([
            'activity_id' => $activityId,
            'status' => $status,
        ]);
    }
}

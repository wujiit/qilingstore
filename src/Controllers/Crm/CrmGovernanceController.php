<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmGovernanceController
{
    public static function lifecycle(): void
    {
        [$user, $pdo] = CrmSupport::context();
        $data = Request::jsonBody();

        $entityType = strtolower(Request::str($data, 'entity_type'));
        $config = self::entityConfig($entityType);
        if (!is_array($config)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }

        CrmSupport::requirePermission($user, (string) $config['edit_permission']);
        $action = strtolower(Request::str($data, 'action'));
        $actionAliases = [
            'restore_archive' => 'unarchive',
            'restore_recycle' => 'recover',
        ];
        if (isset($actionAliases[$action])) {
            $action = $actionAliases[$action];
        }
        if ($action === '') {
            Response::json(['message' => 'action is required'], 422);
            return;
        }
        $commonActions = ['archive', 'unarchive', 'delete', 'recover', 'purge'];
        $leadAssignActions = ['public_pool', 'claim', 'transfer', 'assign'];
        $allowedActions = $commonActions;
        if ($entityType === 'lead') {
            $allowedActions = array_merge($allowedActions, $leadAssignActions);
        }
        if (!in_array($action, $allowedActions, true)) {
            Response::json(['message' => 'action invalid'], 422);
            return;
        }
        if ($action === 'purge' && !CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all for purge'], 403);
            return;
        }

        $entityIds = CrmSupport::positiveIdList($data['entity_ids'] ?? null, 500);
        if ($entityIds === []) {
            Response::json(['message' => 'entity_ids is required'], 422);
            return;
        }

        if ($entityType === 'lead' && in_array($action, $leadAssignActions, true)) {
            CrmSupport::requirePermission($user, 'crm.leads.assign');
        }

        $targetOwnerId = 0;
        if ($entityType === 'lead' && in_array($action, ['claim', 'transfer', 'assign'], true)) {
            $targetOwnerId = self::resolveTargetOwnerId($pdo, $user, $action, Request::int($data, 'owner_user_id', 0));
            if ($targetOwnerId <= 0) {
                Response::json(['message' => 'owner_user_id invalid'], 422);
                return;
            }
        }

        $rows = self::fetchEntityRows($pdo, (string) $config['table'], $entityIds);
        if ($rows === []) {
            Response::json([
                'summary' => [
                    'total' => count($entityIds),
                    'found' => 0,
                    'affected' => 0,
                    'skipped_not_found' => count($entityIds),
                    'skipped_forbidden' => 0,
                ],
            ]);
            return;
        }

        $writableRows = [];
        $forbiddenCount = 0;
        foreach ($rows as $row) {
            if (self::canWriteEntity($user, $row, $entityType, $action)) {
                $writableRows[] = $row;
                continue;
            }
            $forbiddenCount++;
        }

        if ($writableRows === []) {
            Response::json([
                'message' => 'forbidden: no writable records',
                'summary' => [
                    'total' => count($entityIds),
                    'found' => count($rows),
                    'affected' => 0,
                    'skipped_not_found' => count($entityIds) - count($rows),
                    'skipped_forbidden' => $forbiddenCount,
                ],
            ], 403);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);
        $affected = 0;
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }
        try {
            $affected = self::applyLifecycleAction(
                $pdo,
                $entityType,
                (string) $config['table'],
                $action,
                $writableRows,
                $targetOwnerId,
                $uid,
                $now,
                Request::str($data, 'note')
            );
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm lifecycle failed', $e);
            return;
        }

        Audit::log($uid, 'crm.lifecycle.' . $entityType, 'crm_' . $entityType, 0, 'CRM lifecycle action', [
            'entity_type' => $entityType,
            'action' => $action,
            'entity_ids' => array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $writableRows),
            'target_owner_user_id' => $targetOwnerId > 0 ? $targetOwnerId : null,
        ]);

        Response::json([
            'summary' => [
                'total' => count($entityIds),
                'found' => count($rows),
                'affected' => $affected,
                'skipped_not_found' => count($entityIds) - count($rows),
                'skipped_forbidden' => $forbiddenCount,
            ],
        ]);
    }

    public static function duplicates(): void
    {
        [$user, $pdo] = CrmSupport::context();
        $entityType = strtolower(CrmSupport::queryStr('entity_type'));
        $config = self::entityConfig($entityType);
        if (!is_array($config)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }
        CrmSupport::requirePermission($user, (string) $config['view_permission']);

        $excludeId = CrmSupport::queryInt('exclude_id') ?? 0;
        $limit = CrmSupport::queryInt('limit') ?? 20;
        $limit = max(1, min($limit, 100));

        $sql = '';
        $params = [];
        if ($entityType === 'lead') {
            $rule = CrmSupport::dedupeRule($pdo, 'lead');
            $mobile = trim(CrmSupport::queryStr('mobile'));
            $email = strtolower(trim(CrmSupport::queryStr('email')));
            $companyName = trim(CrmSupport::queryStr('company_name'));
            if ($companyName === '') {
                $companyName = trim(CrmSupport::queryStr('q'));
            }
            if (
                (!$rule['match_mobile'] || $mobile === '')
                && (!$rule['match_email'] || $email === '')
                && (!$rule['match_company'] || $companyName === '')
            ) {
                Response::json(['message' => 'mobile / email / company_name is required by dedupe rule'], 422);
                return;
            }

            $sql = 'SELECT id, lead_name, mobile, email, company_name, owner_user_id, status, visibility_scope, created_at
                    FROM qiling_crm_leads
                    WHERE deleted_at IS NULL';
            $or = [];
            if ($rule['match_mobile'] && $mobile !== '') {
                $or[] = '(mobile = :mobile AND mobile <> \'\')';
                $params['mobile'] = $mobile;
            }
            if ($rule['match_email'] && $email !== '') {
                $or[] = '(email = :email AND email <> \'\')';
                $params['email'] = $email;
            }
            if ($rule['match_company'] && $companyName !== '') {
                $or[] = '(company_name = :company_name AND company_name <> \'\')';
                $params['company_name'] = strtolower($companyName);
            }
            if ($or === []) {
                Response::json(['message' => 'no valid duplicate filter'], 422);
                return;
            }

            $sql .= ' AND (' . implode(' OR ', $or) . ')';
            $sql .= ' AND (is_archived = 0 OR is_archived = 1)';
            self::appendLeadReadScope($sql, $params, $pdo, $user);
        } elseif ($entityType === 'contact') {
            $rule = CrmSupport::dedupeRule($pdo, 'contact');
            $mobile = trim(CrmSupport::queryStr('mobile'));
            $email = strtolower(trim(CrmSupport::queryStr('email')));
            $whatsapp = trim(CrmSupport::queryStr('whatsapp'));
            $companyId = CrmSupport::queryInt('company_id') ?? 0;
            if (
                (!$rule['match_mobile'] || $mobile === '')
                && (!$rule['match_email'] || $email === '')
                && (!$rule['match_company'] || $companyId <= 0)
                && $whatsapp === ''
            ) {
                Response::json(['message' => 'mobile / email / company_id / whatsapp is required'], 422);
                return;
            }

            $sql = 'SELECT id, contact_name, mobile, email, whatsapp, company_id, owner_user_id, status, created_at
                    FROM qiling_crm_contacts
                    WHERE deleted_at IS NULL';
            $or = [];
            if ($rule['match_mobile'] && $mobile !== '') {
                $or[] = '(mobile = :mobile AND mobile <> \'\')';
                $params['mobile'] = $mobile;
            }
            if ($rule['match_email'] && $email !== '') {
                $or[] = '(email = :email AND email <> \'\')';
                $params['email'] = $email;
            }
            if ($rule['match_company'] && $companyId > 0) {
                $or[] = '(company_id = :company_id AND company_id > 0)';
                $params['company_id'] = $companyId;
            }
            if ($whatsapp !== '') {
                $or[] = '(whatsapp = :whatsapp AND whatsapp <> \'\')';
                $params['whatsapp'] = $whatsapp;
            }
            $sql .= ' AND (' . implode(' OR ', $or) . ')';
            self::appendOwnerReadScope($sql, $params, $pdo, $user, 'owner_user_id', 'created_by');
        } else {
            $rule = CrmSupport::dedupeRule($pdo, 'company');
            if (!$rule['enabled'] || !$rule['match_company']) {
                Response::json(['message' => 'company dedupe rule is disabled'], 422);
                return;
            }
            $companyName = trim(CrmSupport::queryStr('company_name'));
            if ($companyName === '') {
                $companyName = trim(CrmSupport::queryStr('q'));
            }
            if ($companyName === '') {
                Response::json(['message' => 'company_name is required'], 422);
                return;
            }

            $sql = 'SELECT id, company_name, company_type, owner_user_id, status, created_at
                    FROM qiling_crm_companies
                    WHERE deleted_at IS NULL
                      AND company_name = :company_name';
            $params['company_name'] = strtolower($companyName);
            self::appendOwnerReadScope($sql, $params, $pdo, $user, 'owner_user_id', 'created_by');
        }

        if ($excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        Response::json([
            'entity_type' => $entityType,
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    public static function merge(): void
    {
        [$user, $pdo] = CrmSupport::context();
        $data = Request::jsonBody();
        $entityType = strtolower(Request::str($data, 'entity_type'));
        $config = self::entityConfig($entityType);
        if (!is_array($config)) {
            Response::json(['message' => 'entity_type invalid'], 422);
            return;
        }

        CrmSupport::requirePermission($user, (string) $config['edit_permission']);
        CrmSupport::requirePermission($user, 'crm.governance.manage');

        $primaryId = Request::int($data, 'primary_id', 0);
        if ($primaryId <= 0) {
            Response::json(['message' => 'primary_id is required'], 422);
            return;
        }

        $mergeIds = CrmSupport::positiveIdList($data['merge_ids'] ?? null, 200);
        $mergeIds = array_values(array_filter($mergeIds, static fn (int $id): bool => $id !== $primaryId));
        if ($mergeIds === []) {
            Response::json(['message' => 'merge_ids is required'], 422);
            return;
        }

        $strategy = strtolower(Request::str($data, 'strategy', 'fill_empty'));
        if (!in_array($strategy, ['fill_empty', 'overwrite'], true)) {
            $strategy = 'fill_empty';
        }

        $table = (string) $config['table'];
        $primary = self::fetchOneRow($pdo, $table, $primaryId);
        if (!is_array($primary)) {
            Response::json(['message' => 'primary record not found'], 404);
            return;
        }
        if (!self::canWriteEntity($user, $primary, $entityType, 'merge')) {
            Response::json(['message' => 'forbidden: primary record is not writable'], 403);
            return;
        }

        $rows = self::fetchEntityRows($pdo, $table, $mergeIds);
        if ($rows === []) {
            Response::json(['message' => 'merge records not found'], 404);
            return;
        }
        foreach ($rows as $row) {
            if (!self::canWriteEntity($user, $row, $entityType, 'merge')) {
                Response::json(['message' => 'forbidden: merge record is not writable'], 403);
                return;
            }
        }

        $mergeFields = self::mergeFields($entityType);
        $merged = self::mergeRowValues($primary, $rows, $mergeFields, $strategy);

        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) ($user['id'] ?? 0);
        $pdo->beginTransaction();
        try {
            self::updatePrimaryAfterMerge($pdo, $table, $primaryId, $merged, $now);
            self::rebindReferencesAfterMerge($pdo, $entityType, $primaryId, $mergeIds);
            self::markMergedRowsDeleted($pdo, $table, $mergeIds, $uid, $now);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm merge failed', $e);
            return;
        }

        Audit::log($uid, 'crm.merge.' . $entityType, 'crm_' . $entityType, $primaryId, 'CRM merge records', [
            'entity_type' => $entityType,
            'primary_id' => $primaryId,
            'merge_ids' => $mergeIds,
            'strategy' => $strategy,
        ]);

        Response::json([
            'entity_type' => $entityType,
            'primary_id' => $primaryId,
            'merged_count' => count($mergeIds),
            'strategy' => $strategy,
        ]);
    }

    public static function transferLogs(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.transfer_logs.view');
        $limit = CrmSupport::queryInt('limit') ?? 50;
        $limit = max(1, min($limit, 200));

        $entityType = strtolower(CrmSupport::queryStr('entity_type'));
        $entityId = CrmSupport::queryInt('entity_id') ?? 0;

        $sql = 'SELECT l.*,
                       fu.username AS from_owner_username,
                       tu.username AS to_owner_username,
                       cu.username AS created_by_username
                FROM qiling_crm_transfer_logs l
                LEFT JOIN qiling_users fu ON fu.id = l.from_owner_user_id
                LEFT JOIN qiling_users tu ON tu.id = l.to_owner_user_id
                LEFT JOIN qiling_users cu ON cu.id = l.created_by
                WHERE 1 = 1';
        $params = [];
        if ($entityType !== '') {
            $sql .= ' AND l.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($entityId > 0) {
            $sql .= ' AND l.entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }
        if (!CrmService::canManageAll($user)) {
            $uid = (int) ($user['id'] ?? 0);
            if ($uid <= 0) {
                Response::json(['message' => 'unauthorized'], 401);
                return;
            }
            $sql .= ' AND (l.from_owner_user_id = :scope_uid OR l.to_owner_user_id = :scope_uid OR l.created_by = :scope_uid)';
            $params['scope_uid'] = $uid;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        Response::json(['data' => $rows]);
    }

    public static function assignmentRules(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.assignment_rules.view');
        $manageAll = CrmService::canManageAll($user);
        $uid = (int) ($user['id'] ?? 0);
        $limit = CrmSupport::queryInt('limit') ?? 100;
        $limit = max(1, min($limit, 200));
        $enabledRaw = CrmSupport::queryStr('enabled');
        $enabled = '';
        if ($enabledRaw !== '') {
            $enabled = in_array(strtolower($enabledRaw), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }

        $sql = 'SELECT *
                FROM qiling_crm_assignment_rules
                WHERE 1 = 1';
        $params = [];
        if ($enabled !== '') {
            $sql .= ' AND enabled = :enabled';
            $params['enabled'] = (int) $enabled;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        if (!$manageAll && $uid > 0) {
            $scoped = [];
            foreach ($rows as $row) {
                $members = CrmSupport::decodeJsonArray($row['member_user_ids_json'] ?? null);
                $memberIds = [];
                foreach ($members as $member) {
                    if (is_numeric($member)) {
                        $id = (int) $member;
                        if ($id > 0) {
                            $memberIds[$id] = $id;
                        }
                    }
                }
                if (isset($memberIds[$uid])) {
                    $scoped[] = $row;
                }
            }
            $rows = $scoped;
        }

        $memberIds = [];
        foreach ($rows as &$row) {
            $members = CrmSupport::decodeJsonArray($row['member_user_ids_json'] ?? null);
            $ids = [];
            foreach ($members as $member) {
                if (is_numeric($member)) {
                    $id = (int) $member;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
            $memberIds += $ids;
            $row['member_user_ids'] = array_values($ids);
            unset($row['member_user_ids_json']);
        }
        unset($row);

        $memberMap = self::userMapByIds($pdo, array_values($memberIds));
        foreach ($rows as &$row) {
            $users = [];
            foreach (($row['member_user_ids'] ?? []) as $uid) {
                $userInfo = $memberMap[(int) $uid] ?? null;
                if (is_array($userInfo)) {
                    $users[] = $userInfo;
                }
            }
            $row['member_users'] = $users;
        }
        unset($row);

        Response::json(['data' => $rows]);
    }

    public static function upsertAssignmentRule(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.assignment_rules.edit');
        $data = Request::jsonBody();
        $uid = (int) ($user['id'] ?? 0);

        $ruleId = Request::int($data, 'rule_id', 0);
        $ruleName = Request::str($data, 'rule_name');
        if ($ruleName === '') {
            Response::json(['message' => 'rule_name is required'], 422);
            return;
        }

        $strategy = strtolower(Request::str($data, 'strategy', 'round_robin'));
        if (!in_array($strategy, ['round_robin', 'random'], true)) {
            $strategy = 'round_robin';
        }
        $sourceScope = strtolower(Request::str($data, 'source_scope', 'public_pool'));
        if (!in_array($sourceScope, ['public_pool'], true)) {
            $sourceScope = 'public_pool';
        }
        $enabled = CrmSupport::boolValue($data['enabled'] ?? true) ? 1 : 0;

        $members = CrmSupport::positiveIdList($data['member_user_ids'] ?? null, 200);
        if (!CrmService::canManageAll($user)) {
            $members = $uid > 0 ? [$uid] : [];
        }
        if ($members === []) {
            Response::json(['message' => 'member_user_ids is required'], 422);
            return;
        }

        $activeIds = self::activeUserIds($pdo, $members);
        if ($activeIds === []) {
            Response::json(['message' => 'member_user_ids has no active users'], 422);
            return;
        }
        $memberJson = json_encode(array_values($activeIds), JSON_UNESCAPED_UNICODE);
        if (!is_string($memberJson)) {
            Response::json(['message' => 'member_user_ids invalid'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($ruleId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE qiling_crm_assignment_rules
                 SET rule_name = :rule_name,
                     source_scope = :source_scope,
                     strategy = :strategy,
                     member_user_ids_json = :member_user_ids_json,
                     enabled = :enabled,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'rule_name' => $ruleName,
                'source_scope' => $sourceScope,
                'strategy' => $strategy,
                'member_user_ids_json' => $memberJson,
                'enabled' => $enabled,
                'updated_at' => $now,
                'id' => $ruleId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_assignment_rules
                 (rule_name, entity_type, source_scope, strategy, member_user_ids_json, enabled, last_pick_index, created_by, created_at, updated_at)
                 VALUES
                 (:rule_name, :entity_type, :source_scope, :strategy, :member_user_ids_json, :enabled, :last_pick_index, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute([
                'rule_name' => $ruleName,
                'entity_type' => 'lead',
                'source_scope' => $sourceScope,
                'strategy' => $strategy,
                'member_user_ids_json' => $memberJson,
                'enabled' => $enabled,
                'last_pick_index' => -1,
                'created_by' => $uid,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ruleId = (int) $pdo->lastInsertId();
        }

        Audit::log($uid, 'crm.assignment_rule.upsert', 'crm_assignment_rule', $ruleId, 'Upsert crm assignment rule', [
            'strategy' => $strategy,
            'member_user_ids' => $activeIds,
            'enabled' => $enabled,
        ]);

        Response::json(['rule_id' => $ruleId]);
    }

    public static function applyAssignmentRule(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.assign');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }
        $data = Request::jsonBody();
        $uid = (int) ($user['id'] ?? 0);

        $ruleId = Request::int($data, 'rule_id', 0);
        if ($ruleId <= 0) {
            Response::json(['message' => 'rule_id is required'], 422);
            return;
        }
        $rule = self::fetchOneRow($pdo, 'qiling_crm_assignment_rules', $ruleId);
        if (!is_array($rule)) {
            Response::json(['message' => 'rule not found'], 404);
            return;
        }
        if ((int) ($rule['enabled'] ?? 0) !== 1) {
            Response::json(['message' => 'rule is disabled'], 422);
            return;
        }
        if (strtolower((string) ($rule['entity_type'] ?? 'lead')) !== 'lead') {
            Response::json(['message' => 'rule entity_type invalid'], 422);
            return;
        }

        $memberRaw = CrmSupport::decodeJsonArray($rule['member_user_ids_json'] ?? null);
        $memberIds = [];
        foreach ($memberRaw as $member) {
            if (!is_numeric($member)) {
                continue;
            }
            $id = (int) $member;
            if ($id > 0) {
                $memberIds[$id] = $id;
            }
        }
        $memberIds = self::activeUserIds($pdo, array_values($memberIds));
        if ($memberIds === []) {
            Response::json(['message' => 'rule has no active member users'], 422);
            return;
        }

        $leadIds = CrmSupport::positiveIdList($data['lead_ids'] ?? null, 500);
        $limit = Request::int($data, 'limit', 50);
        $limit = max(1, min($limit, 500));

        $candidates = self::fetchAssignableLeads($pdo, $leadIds, $limit);
        if ($candidates === []) {
            Response::json([
                'summary' => [
                    'rule_id' => $ruleId,
                    'candidate_total' => 0,
                    'assigned_total' => 0,
                ],
                'assignments' => [],
            ]);
            return;
        }

        $strategy = strtolower((string) ($rule['strategy'] ?? 'round_robin'));
        if (!in_array($strategy, ['round_robin', 'random'], true)) {
            $strategy = 'round_robin';
        }

        $now = gmdate('Y-m-d H:i:s');
        $assignStmt = $pdo->prepare(
            'UPDATE qiling_crm_leads
             SET owner_user_id = :owner_user_id,
                 visibility_scope = :visibility_scope,
                 public_pool_at = :public_pool_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $cursor = (int) ($rule['last_pick_index'] ?? -1);
        $memberCount = count($memberIds);
        $assignments = [];
        $logRows = [];
        $startedTx = !$pdo->inTransaction();
        if ($startedTx) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($candidates as $lead) {
                $leadId = (int) ($lead['id'] ?? 0);
                if ($leadId <= 0) {
                    continue;
                }

                if ($strategy === 'random') {
                    $idx = random_int(0, $memberCount - 1);
                } else {
                    $idx = ($cursor + 1) % $memberCount;
                    $cursor = $idx;
                }
                $targetOwner = (int) ($memberIds[$idx] ?? 0);
                if ($targetOwner <= 0) {
                    continue;
                }

                $assignStmt->execute([
                    'owner_user_id' => $targetOwner,
                    'visibility_scope' => 'private',
                    'public_pool_at' => null,
                    'updated_at' => $now,
                    'id' => $leadId,
                ]);

                $fromOwner = self::nullableInt($lead['owner_user_id'] ?? null);
                $logRows[] = [
                    'entity_type' => 'lead',
                    'entity_id' => $leadId,
                    'action_type' => 'assign_rule',
                    'from_owner_user_id' => $fromOwner,
                    'to_owner_user_id' => $targetOwner,
                    'note' => (string) ($rule['rule_name'] ?? ''),
                    'created_by' => $uid,
                    'created_at' => $now,
                ];

                $assignments[] = [
                    'lead_id' => $leadId,
                    'from_owner_user_id' => $fromOwner,
                    'to_owner_user_id' => $targetOwner,
                ];
            }

            self::insertTransferLogRows($pdo, $logRows);

            if ($strategy === 'round_robin') {
                $updateRule = $pdo->prepare(
                    'UPDATE qiling_crm_assignment_rules
                     SET last_pick_index = :last_pick_index,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateRule->execute([
                    'last_pick_index' => $cursor,
                    'updated_at' => $now,
                    'id' => $ruleId,
                ]);
            }

            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm assignment apply failed', $e);
            return;
        }

        Audit::log($uid, 'crm.assignment_rule.apply', 'crm_assignment_rule', $ruleId, 'Apply crm assignment rule', [
            'assigned_total' => count($assignments),
            'lead_ids' => array_map(static fn (array $item): int => (int) ($item['lead_id'] ?? 0), $assignments),
        ]);

        Response::json([
            'summary' => [
                'rule_id' => $ruleId,
                'candidate_total' => count($candidates),
                'assigned_total' => count($assignments),
            ],
            'assignments' => $assignments,
        ]);
    }

    /**
     * @return array<string,string>|null
     */
    private static function entityConfig(string $entityType): ?array
    {
        $map = [
            'lead' => [
                'table' => 'qiling_crm_leads',
                'view_permission' => 'crm.leads.view',
                'edit_permission' => 'crm.leads.edit',
            ],
            'contact' => [
                'table' => 'qiling_crm_contacts',
                'view_permission' => 'crm.contacts.view',
                'edit_permission' => 'crm.contacts.edit',
            ],
            'company' => [
                'table' => 'qiling_crm_companies',
                'view_permission' => 'crm.companies.view',
                'edit_permission' => 'crm.companies.edit',
            ],
        ];

        return $map[$entityType] ?? null;
    }

    private static function resolveTargetOwnerId(PDO $pdo, array $user, string $action, int $requestedOwnerId): int
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return 0;
        }

        if (!CrmService::canManageAll($user)) {
            if (in_array($action, ['claim', 'assign'], true)) {
                return $uid;
            }
            return 0;
        }

        if (in_array($action, ['claim', 'assign'], true) && $requestedOwnerId <= 0) {
            return $uid;
        }
        if ($requestedOwnerId <= 0) {
            return 0;
        }
        return CrmService::resolveOwnerInput($pdo, $user, $requestedOwnerId);
    }

    /**
     * @param array<int,int> $entityIds
     * @return array<int,array<string,mixed>>
     */
    private static function fetchEntityRows(PDO $pdo, string $table, array $entityIds): array
    {
        if ($entityIds === [] || !self::isAllowedEntityTable($table)) {
            return [];
        }
        $params = [];
        $idsSql = self::buildIdPlaceholders('id', $entityIds, $params);
        $stmt = $pdo->prepare(
            'SELECT *
             FROM ' . $table . '
             WHERE id IN (' . $idsSql . ')'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function canWriteEntity(array $user, array $row, string $entityType, string $action): bool
    {
        if (CrmService::canManageAll($user)) {
            return true;
        }

        if ($entityType === 'lead' && in_array($action, ['claim', 'assign'], true)) {
            $visibility = strtolower((string) ($row['visibility_scope'] ?? 'private'));
            if ($visibility === 'public_pool') {
                return true;
            }
        }

        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        $ownerUserId = (int) ($row['owner_user_id'] ?? 0);
        $createdBy = (int) ($row['created_by'] ?? 0);
        return $uid === $ownerUserId || $uid === $createdBy;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function applyLifecycleAction(
        PDO $pdo,
        string $entityType,
        string $table,
        string $action,
        array $rows,
        int $targetOwnerId,
        int $actorUserId,
        string $now,
        string $note
    ): int {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }

        if (in_array($action, ['archive', 'unarchive', 'delete', 'recover', 'purge'], true)) {
            return self::applyCommonLifecycleAction($pdo, $table, $action, $ids, $actorUserId, $now);
        }

        if ($entityType !== 'lead') {
            Response::json(['message' => 'action unsupported for entity_type'], 422);
            exit;
        }

        if ($action === 'public_pool') {
            $affected = self::updateLeadsOwnerScope($pdo, $ids, null, 'public_pool', $now);
            self::insertTransferLogs($pdo, $rows, 'public_pool', null, $actorUserId, $note, $now);
            return $affected;
        }

        if (in_array($action, ['claim', 'transfer', 'assign'], true)) {
            $affected = self::updateLeadsOwnerScope($pdo, $ids, $targetOwnerId, 'private', $now);
            $logAction = $action === 'assign' ? 'assign_manual' : $action;
            self::insertTransferLogs($pdo, $rows, $logAction, $targetOwnerId, $actorUserId, $note, $now);
            return $affected;
        }

        Response::json(['message' => 'action invalid'], 422);
        exit;
    }

    /**
     * @param array<int,int> $ids
     */
    private static function applyCommonLifecycleAction(PDO $pdo, string $table, string $action, array $ids, int $actorUserId, string $now): int
    {
        if (!self::isAllowedEntityTable($table)) {
            return 0;
        }

        $params = [
            'updated_at' => $now,
            'actor_user_id' => $actorUserId,
        ];
        $idsSql = self::buildIdPlaceholders('id', $ids, $params);

        if ($action === 'archive') {
            $stmt = $pdo->prepare(
                'UPDATE ' . $table . '
                 SET is_archived = 1,
                     archived_at = :updated_at,
                     archived_by = :actor_user_id,
                     updated_at = :updated_at
                 WHERE id IN (' . $idsSql . ')
                   AND deleted_at IS NULL'
            );
            $stmt->execute($params);
            return (int) $stmt->rowCount();
        }

        if ($action === 'unarchive') {
            $stmt = $pdo->prepare(
                'UPDATE ' . $table . '
                 SET is_archived = 0,
                     archived_at = NULL,
                     archived_by = NULL,
                     updated_at = :updated_at
                 WHERE id IN (' . $idsSql . ')
                   AND deleted_at IS NULL'
            );
            $stmt->execute($params);
            return (int) $stmt->rowCount();
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare(
                'UPDATE ' . $table . '
                 SET deleted_at = :updated_at,
                     deleted_by = :actor_user_id,
                     updated_at = :updated_at
                 WHERE id IN (' . $idsSql . ')
                   AND deleted_at IS NULL'
            );
            $stmt->execute($params);
            return (int) $stmt->rowCount();
        }

        if ($action === 'recover') {
            $stmt = $pdo->prepare(
                'UPDATE ' . $table . '
                 SET deleted_at = NULL,
                     deleted_by = NULL,
                     updated_at = :updated_at
                 WHERE id IN (' . $idsSql . ')
                   AND deleted_at IS NOT NULL'
            );
            $stmt->execute($params);
            return (int) $stmt->rowCount();
        }

        if ($action === 'purge') {
            $stmt = $pdo->prepare(
                'DELETE FROM ' . $table . '
                 WHERE id IN (' . $idsSql . ')'
            );
            $stmt->execute($params);
            return (int) $stmt->rowCount();
        }

        return 0;
    }

    /**
     * @param array<int,int> $ids
     */
    private static function updateLeadsOwnerScope(PDO $pdo, array $ids, ?int $ownerUserId, string $visibilityScope, string $now): int
    {
        $ownerOrg = $ownerUserId !== null && $ownerUserId > 0
            ? CrmSupport::resolveOwnerOrgScope($pdo, $ownerUserId)
            : ['owner_team_id' => null, 'owner_department_id' => null];
        $visibilityLevel = $visibilityScope === 'public_pool' ? 'public' : 'private';
        $params = [
            'owner_user_id' => $ownerUserId,
            'visibility_scope' => $visibilityScope,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'public_pool_at' => $visibilityScope === 'public_pool' ? $now : null,
            'updated_at' => $now,
        ];
        $idsSql = self::buildIdPlaceholders('id', $ids, $params);
        $stmt = $pdo->prepare(
            'UPDATE qiling_crm_leads
             SET owner_user_id = :owner_user_id,
                 visibility_scope = :visibility_scope,
                 owner_team_id = :owner_team_id,
                 owner_department_id = :owner_department_id,
                 visibility_level = :visibility_level,
                 public_pool_at = :public_pool_at,
                 updated_at = :updated_at
             WHERE id IN (' . $idsSql . ')
               AND deleted_at IS NULL'
        );
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function insertTransferLogs(PDO $pdo, array $rows, string $actionType, ?int $targetOwnerId, int $actorUserId, string $note, string $now): void
    {
        $items = [];
        foreach ($rows as $row) {
            $entityId = (int) ($row['id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }
            $fromOwner = self::nullableInt($row['owner_user_id'] ?? null);
            $items[] = [
                'entity_type' => 'lead',
                'entity_id' => $entityId,
                'action_type' => $actionType,
                'from_owner_user_id' => $fromOwner,
                'to_owner_user_id' => $targetOwnerId,
                'note' => $note,
                'created_by' => $actorUserId,
                'created_at' => $now,
            ];
        }
        self::insertTransferLogRows($pdo, $items);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function insertTransferLogRows(PDO $pdo, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $chunks = array_chunk($rows, 200);
        foreach ($chunks as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $index => $row) {
                $values[] = '(:entity_type_' . $index
                    . ', :entity_id_' . $index
                    . ', :action_type_' . $index
                    . ', :from_owner_user_id_' . $index
                    . ', :to_owner_user_id_' . $index
                    . ', :note_' . $index
                    . ', :created_by_' . $index
                    . ', :created_at_' . $index
                    . ')';
                $params['entity_type_' . $index] = (string) ($row['entity_type'] ?? 'lead');
                $params['entity_id_' . $index] = (int) ($row['entity_id'] ?? 0);
                $params['action_type_' . $index] = (string) ($row['action_type'] ?? 'transfer');
                $params['from_owner_user_id_' . $index] = self::nullableInt($row['from_owner_user_id'] ?? null);
                $params['to_owner_user_id_' . $index] = self::nullableInt($row['to_owner_user_id'] ?? null);
                $params['note_' . $index] = (string) ($row['note'] ?? '');
                $params['created_by_' . $index] = (int) ($row['created_by'] ?? 0);
                $params['created_at_' . $index] = (string) ($row['created_at'] ?? gmdate('Y-m-d H:i:s'));
            }

            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_transfer_logs
                 (entity_type, entity_id, action_type, from_owner_user_id, to_owner_user_id, note, created_by, created_at)
                 VALUES ' . implode(', ', $values)
            );
            $stmt->execute($params);
        }
    }

    /**
     * @param array<int,mixed> $ids
     * @return array<int,int>
     */
    private static function activeUserIds(PDO $pdo, array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $value = (int) $id;
            if ($value > 0) {
                $clean[$value] = $value;
            }
        }
        if ($clean === []) {
            return [];
        }

        $params = [];
        $idsSql = self::buildIdPlaceholders('id', array_values($clean), $params);
        $stmt = $pdo->prepare(
            'SELECT id
             FROM qiling_users
             WHERE status = :status
               AND id IN (' . $idsSql . ')'
        );
        $params['status'] = 'active';
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array{id:int,username:string}>
     */
    private static function userMapByIds(PDO $pdo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $params = [];
        $idsSql = self::buildIdPlaceholders('id', $ids, $params);
        $stmt = $pdo->prepare(
            'SELECT id, username
             FROM qiling_users
             WHERE id IN (' . $idsSql . ')'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = [
                'id' => $id,
                'username' => (string) ($row['username'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @param array<int,int> $leadIds
     * @return array<int,array<string,mixed>>
     */
    private static function fetchAssignableLeads(PDO $pdo, array $leadIds, int $limit): array
    {
        $sql = 'SELECT id, owner_user_id, visibility_scope, public_pool_at
                FROM qiling_crm_leads
                WHERE deleted_at IS NULL
                  AND is_archived = 0
                  AND visibility_scope = :visibility_scope';
        $params = ['visibility_scope' => 'public_pool'];

        if ($leadIds !== []) {
            $idsSql = self::buildIdPlaceholders('id', $leadIds, $params);
            $sql .= ' AND id IN (' . $idsSql . ')';
        }

        $sql .= ' ORDER BY public_pool_at ASC, id ASC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,string>
     */
    private static function mergeFields(string $entityType): array
    {
        if ($entityType === 'lead') {
            return [
                'lead_name',
                'mobile',
                'email',
                'company_name',
                'country_code',
                'language_code',
                'source_channel',
                'intent_level',
                'status',
                'next_followup_at',
                'last_contact_at',
                'related_company_id',
                'related_contact_id',
                'tags_json',
                'extra_json',
                'owner_user_id',
            ];
        }
        if ($entityType === 'contact') {
            return [
                'company_id',
                'contact_name',
                'mobile',
                'email',
                'wechat',
                'whatsapp',
                'title',
                'country_code',
                'language_code',
                'source_channel',
                'status',
                'tags_json',
                'extra_json',
                'owner_user_id',
            ];
        }
        return [
            'company_name',
            'company_type',
            'country_code',
            'website',
            'industry',
            'source_channel',
            'status',
            'tags_json',
            'extra_json',
            'owner_user_id',
        ];
    }

    /**
     * @param array<string,mixed> $primary
     * @param array<int,array<string,mixed>> $mergeRows
     * @param array<int,string> $fields
     * @return array<string,mixed>
     */
    private static function mergeRowValues(array $primary, array $mergeRows, array $fields, string $strategy): array
    {
        $merged = [];
        foreach ($fields as $field) {
            $value = $primary[$field] ?? null;
            foreach ($mergeRows as $row) {
                $candidate = $row[$field] ?? null;
                if ($strategy === 'overwrite') {
                    if (!self::isEmptyMergeValue($candidate)) {
                        $value = $candidate;
                    }
                    continue;
                }
                if (self::isEmptyMergeValue($value) && !self::isEmptyMergeValue($candidate)) {
                    $value = $candidate;
                }
            }
            $merged[$field] = $value;
        }
        return $merged;
    }

    /**
     * @param array<string,mixed> $merged
     */
    private static function updatePrimaryAfterMerge(PDO $pdo, string $table, int $primaryId, array $merged, string $now): void
    {
        $sets = [];
        $params = ['id' => $primaryId, 'updated_at' => $now];
        foreach ($merged as $field => $value) {
            $sets[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        $sets[] = 'updated_at = :updated_at';

        $stmt = $pdo->prepare(
            'UPDATE ' . $table . '
             SET ' . implode(', ', $sets) . '
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * @param array<int,int> $mergeIds
     */
    private static function rebindReferencesAfterMerge(PDO $pdo, string $entityType, int $primaryId, array $mergeIds): void
    {
        if ($mergeIds === []) {
            return;
        }
        $params = ['primary_id' => $primaryId];
        $idsSql = self::buildIdPlaceholders('mid', $mergeIds, $params);

        if ($entityType === 'lead') {
            $pdo->prepare(
                'UPDATE qiling_crm_deals
                 SET lead_id = :primary_id
                 WHERE lead_id IN (' . $idsSql . ')'
            )->execute($params);
            $paramsAct = ['primary_id' => $primaryId, 'entity_type' => 'lead'];
            $idsSqlAct = self::buildIdPlaceholders('amid', $mergeIds, $paramsAct);
            $pdo->prepare(
                'UPDATE qiling_crm_activities
                 SET entity_id = :primary_id
                 WHERE entity_type = :entity_type
                   AND entity_id IN (' . $idsSqlAct . ')'
            )->execute($paramsAct);
            return;
        }

        if ($entityType === 'contact') {
            $pdo->prepare(
                'UPDATE qiling_crm_deals
                 SET contact_id = :primary_id
                 WHERE contact_id IN (' . $idsSql . ')'
            )->execute($params);
            $paramsAct = ['primary_id' => $primaryId, 'entity_type' => 'contact'];
            $idsSqlAct = self::buildIdPlaceholders('amid', $mergeIds, $paramsAct);
            $pdo->prepare(
                'UPDATE qiling_crm_activities
                 SET entity_id = :primary_id
                 WHERE entity_type = :entity_type
                   AND entity_id IN (' . $idsSqlAct . ')'
            )->execute($paramsAct);
            return;
        }

        $pdo->prepare(
            'UPDATE qiling_crm_contacts
             SET company_id = :primary_id
             WHERE company_id IN (' . $idsSql . ')'
        )->execute($params);
        $pdo->prepare(
            'UPDATE qiling_crm_deals
             SET company_id = :primary_id
             WHERE company_id IN (' . $idsSql . ')'
        )->execute($params);
        $paramsAct = ['primary_id' => $primaryId, 'entity_type' => 'company'];
        $idsSqlAct = self::buildIdPlaceholders('amid', $mergeIds, $paramsAct);
        $pdo->prepare(
            'UPDATE qiling_crm_activities
             SET entity_id = :primary_id
             WHERE entity_type = :entity_type
               AND entity_id IN (' . $idsSqlAct . ')'
        )->execute($paramsAct);
    }

    /**
     * @param array<int,int> $mergeIds
     */
    private static function markMergedRowsDeleted(PDO $pdo, string $table, array $mergeIds, int $actorUserId, string $now): void
    {
        if ($mergeIds === []) {
            return;
        }
        $params = [
            'deleted_at' => $now,
            'deleted_by' => $actorUserId,
            'updated_at' => $now,
        ];
        $idsSql = self::buildIdPlaceholders('id', $mergeIds, $params);
        $stmt = $pdo->prepare(
            'UPDATE ' . $table . '
             SET deleted_at = :deleted_at,
                 deleted_by = :deleted_by,
                 is_archived = 0,
                 archived_at = NULL,
                 archived_by = NULL,
                 updated_at = :updated_at
             WHERE id IN (' . $idsSql . ')'
        );
        $stmt->execute($params);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<int,int> $ids
     */
    private static function buildIdPlaceholders(string $prefix, array $ids, array &$params): string
    {
        $list = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $list[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        return implode(',', $list);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function fetchOneRow(PDO $pdo, string $table, int $id): ?array
    {
        if ($id <= 0 || !self::isAllowedEntityTable($table)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function isAllowedEntityTable(string $table): bool
    {
        return in_array($table, [
            'qiling_crm_leads',
            'qiling_crm_contacts',
            'qiling_crm_companies',
        ], true);
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function appendOwnerReadScope(
        string &$sql,
        array &$params,
        PDO $pdo,
        array $user,
        string $ownerField,
        string $createdField
    ): void
    {
        if (CrmService::canManageAll($user)) {
            return;
        }
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $visible = [
            $ownerField . ' = :scope_uid',
            $createdField . ' = :scope_uid',
            'visibility_level = :scope_visibility_public',
        ];
        $params['scope_uid'] = $uid;
        $params['scope_visibility_public'] = 'public';

        $org = CrmSupport::userOrgScope($pdo, $uid);
        $teamIds = is_array($org['team_ids'] ?? null) ? array_values($org['team_ids']) : [];
        if ($teamIds !== []) {
            $placeholders = [];
            foreach ($teamIds as $idx => $teamId) {
                $key = 'scope_team_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $teamId;
            }
            $visible[] = '(visibility_level = :scope_visibility_team AND owner_team_id IN (' . implode(',', $placeholders) . '))';
            $params['scope_visibility_team'] = 'team';
        }

        $departmentIds = is_array($org['department_ids'] ?? null) ? array_values($org['department_ids']) : [];
        if ($departmentIds !== []) {
            $placeholders = [];
            foreach ($departmentIds as $idx => $departmentId) {
                $key = 'scope_department_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $departmentId;
            }
            $visible[] = '(visibility_level = :scope_visibility_department AND owner_department_id IN (' . implode(',', $placeholders) . '))';
            $params['scope_visibility_department'] = 'department';
        }

        $sql .= ' AND (' . implode(' OR ', $visible) . ')';
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function appendLeadReadScope(string &$sql, array &$params, PDO $pdo, array $user): void
    {
        if (CrmService::canManageAll($user)) {
            return;
        }
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $visible = [
            'owner_user_id = :scope_uid',
            'created_by = :scope_uid',
            'visibility_scope = :scope_public_pool',
            'visibility_level = :scope_visibility_public',
        ];
        $params['scope_uid'] = $uid;
        $params['scope_public_pool'] = 'public_pool';
        $params['scope_visibility_public'] = 'public';

        $org = CrmSupport::userOrgScope($pdo, $uid);
        $teamIds = is_array($org['team_ids'] ?? null) ? array_values($org['team_ids']) : [];
        if ($teamIds !== []) {
            $placeholders = [];
            foreach ($teamIds as $idx => $teamId) {
                $key = 'scope_team_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $teamId;
            }
            $visible[] = '(visibility_level = :scope_visibility_team AND owner_team_id IN (' . implode(',', $placeholders) . '))';
            $params['scope_visibility_team'] = 'team';
        }

        $departmentIds = is_array($org['department_ids'] ?? null) ? array_values($org['department_ids']) : [];
        if ($departmentIds !== []) {
            $placeholders = [];
            foreach ($departmentIds as $idx => $departmentId) {
                $key = 'scope_department_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $departmentId;
            }
            $visible[] = '(visibility_level = :scope_visibility_department AND owner_department_id IN (' . implode(',', $placeholders) . '))';
            $params['scope_visibility_department'] = 'department';
        }

        $sql .= ' AND (' . implode(' OR ', $visible) . ')';
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $v = (int) $value;
        return $v > 0 ? $v : null;
    }

    private static function isEmptyMergeValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_numeric($value)) {
            return (float) $value <= 0;
        }
        return false;
    }
}

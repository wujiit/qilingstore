<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmAutomationService;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmLeadController
{
    public static function leads(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.view');
        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('status');
        $intentLevel = CrmSupport::queryStr('intent_level');
        $scope = strtolower(CrmSupport::queryStr('scope'));
        if (!in_array($scope, ['', 'mine', 'private', 'public_pool', 'all', 'team', 'department', 'public'], true)) {
            $scope = '';
        }
        $visibilityLevel = strtolower(CrmSupport::queryStr('visibility_level'));
        if (!in_array($visibilityLevel, ['', 'private', 'team', 'department', 'public'], true)) {
            $visibilityLevel = '';
        }
        $view = strtolower(CrmSupport::queryStr('view'));
        if (!in_array($view, ['active', 'archived', 'recycle', 'all'], true)) {
            $view = 'active';
        }
        $manageAll = CrmService::canManageAll($user);
        $uid = (int) ($user['id'] ?? 0);

        $sql = 'SELECT l.*,
                       ou.username AS owner_username,
                       cu.username AS creator_username
                FROM qiling_crm_leads l
                LEFT JOIN qiling_users ou ON ou.id = l.owner_user_id
                LEFT JOIN qiling_users cu ON cu.id = l.created_by
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND l.owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $requestedOwnerId;
            }
            if ($scope === 'public_pool') {
                $sql .= ' AND l.visibility_scope = :visibility_scope';
                $params['visibility_scope'] = 'public_pool';
            } elseif ($scope === 'private' || $scope === 'mine') {
                $sql .= ' AND l.visibility_scope = :visibility_scope';
                $params['visibility_scope'] = 'private';
            } elseif (in_array($scope, ['team', 'department', 'public'], true)) {
                $sql .= ' AND l.visibility_level = :scope_visibility_level';
                $params['scope_visibility_level'] = $scope;
            }
        } else {
            if ($uid <= 0) {
                Response::json(['message' => 'unauthorized'], 401);
                return;
            }
            if ($requestedOwnerId !== null && $requestedOwnerId > 0 && $requestedOwnerId !== $uid) {
                Response::json(['message' => 'forbidden: cross-owner query denied'], 403);
                return;
            }

            if ($scope === 'public_pool') {
                $sql .= ' AND l.visibility_scope = :scope_public_pool';
                $params['scope_public_pool'] = 'public_pool';
            } elseif ($scope === 'private' || $scope === 'mine') {
                $sql .= ' AND (l.owner_user_id = :scope_uid OR l.created_by = :scope_uid)';
                $params['scope_uid'] = $uid;
                if ($scope === 'private') {
                    $sql .= ' AND l.visibility_scope = :scope_private';
                    $params['scope_private'] = 'private';
                }
            } else {
                $visible = [
                    'l.owner_user_id = :scope_uid',
                    'l.created_by = :scope_uid',
                    'l.visibility_level = :scope_visibility_public',
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
                    $visible[] = '(l.visibility_level = :scope_visibility_team AND l.owner_team_id IN (' . implode(',', $placeholders) . '))';
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
                    $visible[] = '(l.visibility_level = :scope_visibility_department AND l.owner_department_id IN (' . implode(',', $placeholders) . '))';
                    $params['scope_visibility_department'] = 'department';
                }

                $visibilitySql = '(' . implode(' OR ', $visible) . ')';
                if ($scope === 'all') {
                    $sql .= ' AND (' . $visibilitySql . ' OR l.visibility_scope = :scope_public_pool_all)';
                    $params['scope_public_pool_all'] = 'public_pool';
                } else {
                    $sql .= ' AND ' . $visibilitySql;
                }

                if (in_array($scope, ['team', 'department', 'public'], true)) {
                    $sql .= ' AND l.visibility_level = :scope_visibility_level';
                    $params['scope_visibility_level'] = $scope;
                }
            }
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND l.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND l.status = :status';
            $params['status'] = $status;
        }
        if ($intentLevel !== '') {
            $sql .= ' AND l.intent_level = :intent_level';
            $params['intent_level'] = $intentLevel;
        }
        if ($view === 'active') {
            $sql .= ' AND l.deleted_at IS NULL AND l.is_archived = 0';
        } elseif ($view === 'archived') {
            $sql .= ' AND l.deleted_at IS NULL AND l.is_archived = 1';
        } elseif ($view === 'recycle') {
            $sql .= ' AND l.deleted_at IS NOT NULL';
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_leads', 'ft_qiling_crm_leads_search')
            ) {
                $sql .= ' AND MATCH (l.lead_name, l.mobile, l.email, l.company_name) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (l.lead_name LIKE :kw OR l.mobile LIKE :kw OR l.email LIKE :kw OR l.company_name LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        if ($cursor > 0) {
            $sql .= ' AND l.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        foreach ($rows as &$row) {
            $row['tags'] = CrmSupport::decodeJsonArray($row['tags_json'] ?? null);
            $row['extra'] = CrmSupport::decodeJsonObject($row['extra_json'] ?? null);
            $row['custom_fields'] = CrmSupport::customFieldsFromExtra($row['extra']);
            unset($row['tags_json'], $row['extra_json']);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function createLead(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.edit');
        $data = Request::jsonBody();

        $leadName = Request::str($data, 'lead_name');
        if ($leadName === '') {
            Response::json(['message' => 'lead_name is required'], 422);
            return;
        }

        $ownerUserId = CrmService::resolveOwnerInput($pdo, $user, Request::int($data, 'owner_user_id', 0));
        $ownerOrg = CrmSupport::resolveOwnerOrgScope($pdo, $ownerUserId);
        $visibilityLevel = CrmSupport::normalizeVisibilityLevel(Request::str($data, 'visibility_level', 'private'));
        $now = gmdate('Y-m-d H:i:s');
        $tags = Request::strList($data, 'tags');
        $extra = CrmSupport::jsonEncode($data['extra'] ?? null);
        if (array_key_exists('custom_fields', $data) && is_array($data['custom_fields'])) {
            $customFields = CrmSupport::sanitizeCustomFields($data['custom_fields']);
            $extra = CrmSupport::mergeCustomFieldsToExtra($extra, $customFields, true);
        }
        $nextFollowupAt = CrmSupport::parseDateTime(Request::str($data, 'next_followup_at'));
        $mobile = Request::str($data, 'mobile');
        $email = strtolower(Request::str($data, 'email'));
        $companyName = Request::str($data, 'company_name');

        $duplicate = self::findDuplicateLeadByIdentity($pdo, $mobile, $email, $companyName, null);
        if (is_array($duplicate) && !CrmSupport::boolValue($data['allow_duplicate'] ?? false)) {
            Response::json([
                'message' => 'duplicate lead exists',
                'duplicate_lead_id' => (int) ($duplicate['id'] ?? 0),
            ], 409);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_leads
             (lead_name, mobile, email, company_name, country_code, language_code, source_channel, intent_level, status, visibility_scope, owner_user_id, owner_team_id, owner_department_id, visibility_level, related_company_id, related_contact_id, next_followup_at, last_contact_at, tags_json, extra_json, created_by, created_at, updated_at)
             VALUES
             (:lead_name, :mobile, :email, :company_name, :country_code, :language_code, :source_channel, :intent_level, :status, :visibility_scope, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :related_company_id, :related_contact_id, :next_followup_at, :last_contact_at, :tags_json, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'lead_name' => $leadName,
            'mobile' => $mobile,
            'email' => $email,
            'company_name' => $companyName,
            'country_code' => strtoupper(Request::str($data, 'country_code')),
            'language_code' => strtolower(Request::str($data, 'language_code')),
            'source_channel' => Request::str($data, 'source_channel'),
            'intent_level' => CrmSupport::normalizeStatus(Request::str($data, 'intent_level', 'warm'), ['cold', 'warm', 'hot'], 'warm'),
            'status' => CrmSupport::normalizeStatus(Request::str($data, 'status', 'new'), ['new', 'contacted', 'qualified', 'disqualified', 'converted'], 'new'),
            'visibility_scope' => CrmSupport::normalizeStatus(Request::str($data, 'visibility_scope', 'private'), ['private', 'public_pool'], 'private'),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'related_company_id' => null,
            'related_contact_id' => null,
            'next_followup_at' => $nextFollowupAt,
            'last_contact_at' => null,
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'extra_json' => $extra,
            'created_by' => (int) $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $leadId = (int) $pdo->lastInsertId();
        Audit::log((int) $user['id'], 'crm.lead.create', 'crm_lead', $leadId, 'Create crm lead', [
            'lead_name' => $leadName,
            'owner_user_id' => $ownerUserId,
        ]);

        Response::json(['lead_id' => $leadId], 201);
    }

    public static function updateLead(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.edit');
        $data = Request::jsonBody();
        $leadId = Request::int($data, 'lead_id', 0);
        if ($leadId <= 0) {
            Response::json(['message' => 'lead_id is required'], 422);
            return;
        }

        $row = CrmSupport::findWritableRecord($pdo, 'qiling_crm_leads', $leadId, $user);
        if (!is_array($row)) {
            return;
        }
        if (!empty($row['deleted_at'])) {
            Response::json(['message' => 'lead is in recycle bin'], 422);
            return;
        }

        $ownerUserId = CrmService::resolveOwnerInput(
            $pdo,
            $user,
            Request::int($data, 'owner_user_id', (int) ($row['owner_user_id'] ?? 0))
        );
        $ownerOrg = CrmSupport::resolveOwnerOrgScope($pdo, $ownerUserId);
        $visibilityLevel = array_key_exists('visibility_level', $data)
            ? CrmSupport::normalizeVisibilityLevel(Request::str($data, 'visibility_level'), (string) ($row['visibility_level'] ?? 'private'))
            : (string) ($row['visibility_level'] ?? 'private');
        $leadName = Request::str($data, 'lead_name', (string) ($row['lead_name'] ?? ''));
        if ($leadName === '') {
            Response::json(['message' => 'lead_name is required'], 422);
            return;
        }

        $tags = array_key_exists('tags', $data)
            ? Request::strList($data, 'tags')
            : CrmSupport::decodeJsonArray($row['tags_json'] ?? null);
        $extra = array_key_exists('extra', $data)
            ? CrmSupport::jsonEncode($data['extra'] ?? null)
            : ($row['extra_json'] ?? null);
        if (array_key_exists('custom_fields', $data) && is_array($data['custom_fields'])) {
            $customFields = CrmSupport::sanitizeCustomFields($data['custom_fields']);
            $extra = CrmSupport::mergeCustomFieldsToExtra($extra, $customFields, true);
        } elseif (array_key_exists('extra', $data)) {
            $existingCustom = CrmSupport::customFieldsFromExtra($row['extra_json'] ?? null);
            if ($existingCustom !== []) {
                $extra = CrmSupport::mergeCustomFieldsToExtra($extra, $existingCustom, true);
            }
        }
        $nextFollowupAt = array_key_exists('next_followup_at', $data)
            ? CrmSupport::parseDateTime(Request::str($data, 'next_followup_at'))
            : ($row['next_followup_at'] ?? null);
        $lastContactAt = array_key_exists('last_contact_at', $data)
            ? CrmSupport::parseDateTime(Request::str($data, 'last_contact_at'))
            : ($row['last_contact_at'] ?? null);
        $mobile = Request::str($data, 'mobile', (string) ($row['mobile'] ?? ''));
        $email = strtolower(Request::str($data, 'email', (string) ($row['email'] ?? '')));
        $companyName = Request::str($data, 'company_name', (string) ($row['company_name'] ?? ''));

        $duplicate = self::findDuplicateLeadByIdentity($pdo, $mobile, $email, $companyName, $leadId);
        if (is_array($duplicate) && !CrmSupport::boolValue($data['allow_duplicate'] ?? false)) {
            Response::json([
                'message' => 'duplicate lead exists',
                'duplicate_lead_id' => (int) ($duplicate['id'] ?? 0),
            ], 409);
            return;
        }

        $status = CrmSupport::normalizeStatus(
            Request::str($data, 'status', (string) ($row['status'] ?? 'new')),
            ['new', 'contacted', 'qualified', 'disqualified', 'converted'],
            'new'
        );

        $stmt = $pdo->prepare(
            'UPDATE qiling_crm_leads
             SET lead_name = :lead_name,
                 mobile = :mobile,
                 email = :email,
                 company_name = :company_name,
                 country_code = :country_code,
                 language_code = :language_code,
                 source_channel = :source_channel,
                 intent_level = :intent_level,
                 status = :status,
                 owner_user_id = :owner_user_id,
                 owner_team_id = :owner_team_id,
                 owner_department_id = :owner_department_id,
                 visibility_level = :visibility_level,
                 next_followup_at = :next_followup_at,
                 last_contact_at = :last_contact_at,
                 tags_json = :tags_json,
                 extra_json = :extra_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'lead_name' => $leadName,
            'mobile' => $mobile,
            'email' => $email,
            'company_name' => $companyName,
            'country_code' => strtoupper(Request::str($data, 'country_code', (string) ($row['country_code'] ?? ''))),
            'language_code' => strtolower(Request::str($data, 'language_code', (string) ($row['language_code'] ?? ''))),
            'source_channel' => Request::str($data, 'source_channel', (string) ($row['source_channel'] ?? '')),
            'intent_level' => CrmSupport::normalizeStatus(Request::str($data, 'intent_level', (string) ($row['intent_level'] ?? 'warm')), ['cold', 'warm', 'hot'], 'warm'),
            'status' => $status,
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'next_followup_at' => $nextFollowupAt,
            'last_contact_at' => $lastContactAt,
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'extra_json' => $extra,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $leadId,
        ]);

        $automationSummary = [
            'matched' => 0,
            'executed' => 0,
            'success' => 0,
            'failed' => 0,
        ];
        $fromStatus = (string) ($row['status'] ?? '');
        if ($fromStatus !== $status) {
            $result = CrmAutomationService::executeFieldChange(
                $pdo,
                $user,
                'lead',
                $leadId,
                'status',
                $fromStatus,
                $status,
                array_merge($row, [
                    'id' => $leadId,
                    'status' => $status,
                    'owner_user_id' => $ownerUserId,
                    'owner_team_id' => $ownerOrg['owner_team_id'],
                    'owner_department_id' => $ownerOrg['owner_department_id'],
                ])
            );
            $automationSummary = self::mergeAutomationSummary($automationSummary, $result);
        }

        Audit::log((int) $user['id'], 'crm.lead.update', 'crm_lead', $leadId, 'Update crm lead', [
            'owner_user_id' => $ownerUserId,
            'status' => $status,
            'automation_executed' => $automationSummary['executed'],
        ]);

        Response::json([
            'lead_id' => $leadId,
            'automation' => $automationSummary,
        ]);
    }

    public static function batchUpdateLeads(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.edit');
        $data = Request::jsonBody();

        $leadIds = CrmSupport::positiveIdList($data['lead_ids'] ?? null, 500);
        if ($leadIds === []) {
            Response::json(['message' => 'lead_ids is required'], 422);
            return;
        }

        $hasStatus = array_key_exists('status', $data);
        $hasOwner = array_key_exists('owner_user_id', $data);
        if (!$hasStatus && !$hasOwner) {
            Response::json(['message' => 'at least one update field is required'], 422);
            return;
        }

        $status = null;
        if ($hasStatus) {
            $status = CrmSupport::normalizeStatus(
                Request::str($data, 'status'),
                ['new', 'contacted', 'qualified', 'disqualified', 'converted'],
                ''
            );
            if ($status === '') {
                Response::json(['message' => 'status invalid'], 422);
                return;
            }
        }

        $ownerUserId = null;
        if ($hasOwner) {
            $requestedOwnerId = Request::int($data, 'owner_user_id', 0);
            if ($requestedOwnerId <= 0) {
                Response::json(['message' => 'owner_user_id invalid'], 422);
                return;
            }
            $ownerUserId = CrmService::resolveOwnerInput($pdo, $user, $requestedOwnerId);
        }

        $idParams = [];
        $idPlaceholders = [];
        foreach ($leadIds as $index => $id) {
            $key = 'id_' . $index;
            $idPlaceholders[] = ':' . $key;
            $idParams[$key] = $id;
        }

        $stmt = $pdo->prepare(
            'SELECT id, owner_user_id, created_by, status
             FROM qiling_crm_leads
             WHERE id IN (' . implode(',', $idPlaceholders) . ')
               AND deleted_at IS NULL'
        );
        $stmt->execute($idParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $manageAll = CrmService::canManageAll($user);
        $uid = (int) ($user['id'] ?? 0);
        $foundIds = [];
        $writableIds = [];
        $forbiddenCount = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $foundIds[$id] = true;

            if ($manageAll) {
                $writableIds[] = $id;
                continue;
            }

            $ownerId = (int) ($row['owner_user_id'] ?? 0);
            $createdBy = (int) ($row['created_by'] ?? 0);
            if ($uid > 0 && ($uid === $ownerId || $uid === $createdBy)) {
                $writableIds[] = $id;
            } else {
                $forbiddenCount++;
            }
        }

        if ($writableIds === []) {
            Response::json([
                'message' => 'forbidden: no writable leads',
                'summary' => [
                    'total' => count($leadIds),
                    'found' => count($foundIds),
                    'updated' => 0,
                    'affected_rows' => 0,
                    'skipped_not_found' => count($leadIds) - count($foundIds),
                    'skipped_forbidden' => $forbiddenCount,
                ],
            ], 403);
            return;
        }

        $updateSet = ['updated_at = :updated_at'];
        $updateParams = [
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($hasStatus) {
            $updateSet[] = 'status = :status';
            $updateParams['status'] = $status;
        }
        if ($hasOwner) {
            $updateSet[] = 'owner_user_id = :owner_user_id';
            $updateParams['owner_user_id'] = $ownerUserId;
            $ownerOrg = CrmSupport::resolveOwnerOrgScope($pdo, (int) $ownerUserId);
            $updateSet[] = 'owner_team_id = :owner_team_id';
            $updateSet[] = 'owner_department_id = :owner_department_id';
            $updateParams['owner_team_id'] = $ownerOrg['owner_team_id'];
            $updateParams['owner_department_id'] = $ownerOrg['owner_department_id'];
        }

        $updateIds = [];
        foreach ($writableIds as $index => $id) {
            $key = 'wid_' . $index;
            $updateIds[] = ':' . $key;
            $updateParams[$key] = $id;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE qiling_crm_leads
             SET ' . implode(', ', $updateSet) . '
             WHERE id IN (' . implode(',', $updateIds) . ')
               AND deleted_at IS NULL'
        );
        $updateStmt->execute($updateParams);
        $affectedRows = (int) $updateStmt->rowCount();

        $automationSummary = [
            'matched' => 0,
            'executed' => 0,
            'success' => 0,
            'failed' => 0,
        ];
        if ($hasStatus && is_string($status) && $status !== '') {
            foreach ($rows as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0 || !in_array($id, $writableIds, true)) {
                    continue;
                }
                $fromStatus = (string) ($item['status'] ?? '');
                if ($fromStatus === $status) {
                    continue;
                }
                $result = CrmAutomationService::executeFieldChange(
                    $pdo,
                    $user,
                    'lead',
                    $id,
                    'status',
                    $fromStatus,
                    $status,
                    array_merge($item, ['status' => $status])
                );
                $automationSummary = self::mergeAutomationSummary($automationSummary, $result);
            }
        }

        Audit::log((int) $user['id'], 'crm.lead.batch_update', 'crm_lead', 0, 'Batch update crm leads', [
            'lead_ids' => $writableIds,
            'status' => $status,
            'owner_user_id' => $ownerUserId,
            'requested_total' => count($leadIds),
            'automation_executed' => $automationSummary['executed'],
        ]);

        Response::json([
            'summary' => [
                'total' => count($leadIds),
                'found' => count($foundIds),
                'updated' => count($writableIds),
                'affected_rows' => $affectedRows,
                'skipped_not_found' => count($leadIds) - count($foundIds),
                'skipped_forbidden' => $forbiddenCount,
            ],
            'automation' => $automationSummary,
        ]);
    }

    public static function exportLeads(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.view');

        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('status');
        $intentLevel = CrmSupport::queryStr('intent_level');
        $scope = strtolower(CrmSupport::queryStr('scope'));
        if (!in_array($scope, ['', 'mine', 'private', 'public_pool', 'all', 'team', 'department', 'public'], true)) {
            $scope = '';
        }
        $visibilityLevel = strtolower(CrmSupport::queryStr('visibility_level'));
        if (!in_array($visibilityLevel, ['', 'private', 'team', 'department', 'public'], true)) {
            $visibilityLevel = '';
        }
        $view = strtolower(CrmSupport::queryStr('view'));
        if (!in_array($view, ['active', 'archived', 'recycle', 'all'], true)) {
            $view = 'active';
        }
        $manageAll = CrmService::canManageAll($user);
        $uid = (int) ($user['id'] ?? 0);
        $headerLang = strtolower(CrmSupport::queryStr('header_lang'));
        if (!in_array($headerLang, ['zh', 'en'], true)) {
            $headerLang = 'en';
        }
        $limit = CrmSupport::queryInt('limit') ?? 1000;
        $limit = max(1, min($limit, 5000));

        $sql = 'SELECT l.id, l.lead_name, l.mobile, l.email, l.company_name, l.country_code, l.language_code,
                       l.source_channel, l.intent_level, l.status, l.owner_user_id, ou.username AS owner_username,
                       l.next_followup_at, l.last_contact_at, l.created_at, l.updated_at, l.extra_json
                FROM qiling_crm_leads l
                LEFT JOIN qiling_users ou ON ou.id = l.owner_user_id
                WHERE 1 = 1';
        $params = [];
        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND l.owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $requestedOwnerId;
            }
            if ($scope === 'public_pool') {
                $sql .= ' AND l.visibility_scope = :visibility_scope';
                $params['visibility_scope'] = 'public_pool';
            } elseif ($scope === 'private' || $scope === 'mine') {
                $sql .= ' AND l.visibility_scope = :visibility_scope';
                $params['visibility_scope'] = 'private';
            } elseif (in_array($scope, ['team', 'department', 'public'], true)) {
                $sql .= ' AND l.visibility_level = :scope_visibility_level';
                $params['scope_visibility_level'] = $scope;
            }
        } else {
            if ($uid <= 0) {
                Response::json(['message' => 'unauthorized'], 401);
                return;
            }
            if ($requestedOwnerId !== null && $requestedOwnerId > 0 && $requestedOwnerId !== $uid) {
                Response::json(['message' => 'forbidden: cross-owner query denied'], 403);
                return;
            }

            if ($scope === 'public_pool') {
                $sql .= ' AND l.visibility_scope = :scope_public_pool';
                $params['scope_public_pool'] = 'public_pool';
            } elseif ($scope === 'private' || $scope === 'mine') {
                $sql .= ' AND (l.owner_user_id = :scope_uid OR l.created_by = :scope_uid)';
                $params['scope_uid'] = $uid;
                if ($scope === 'private') {
                    $sql .= ' AND l.visibility_scope = :scope_private';
                    $params['scope_private'] = 'private';
                }
            } else {
                $visible = [
                    'l.owner_user_id = :scope_uid',
                    'l.created_by = :scope_uid',
                    'l.visibility_level = :scope_visibility_public',
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
                    $visible[] = '(l.visibility_level = :scope_visibility_team AND l.owner_team_id IN (' . implode(',', $placeholders) . '))';
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
                    $visible[] = '(l.visibility_level = :scope_visibility_department AND l.owner_department_id IN (' . implode(',', $placeholders) . '))';
                    $params['scope_visibility_department'] = 'department';
                }

                $visibilitySql = '(' . implode(' OR ', $visible) . ')';
                if ($scope === 'all') {
                    $sql .= ' AND (' . $visibilitySql . ' OR l.visibility_scope = :scope_public_pool_all)';
                    $params['scope_public_pool_all'] = 'public_pool';
                } else {
                    $sql .= ' AND ' . $visibilitySql;
                }

                if (in_array($scope, ['team', 'department', 'public'], true)) {
                    $sql .= ' AND l.visibility_level = :scope_visibility_level';
                    $params['scope_visibility_level'] = $scope;
                }
            }
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND l.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND l.status = :status';
            $params['status'] = $status;
        }
        if ($intentLevel !== '') {
            $sql .= ' AND l.intent_level = :intent_level';
            $params['intent_level'] = $intentLevel;
        }
        if ($view === 'active') {
            $sql .= ' AND l.deleted_at IS NULL AND l.is_archived = 0';
        } elseif ($view === 'archived') {
            $sql .= ' AND l.deleted_at IS NULL AND l.is_archived = 1';
        } elseif ($view === 'recycle') {
            $sql .= ' AND l.deleted_at IS NOT NULL';
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_leads', 'ft_qiling_crm_leads_search')
            ) {
                $sql .= ' AND MATCH (l.lead_name, l.mobile, l.email, l.company_name) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (l.lead_name LIKE :kw OR l.mobile LIKE :kw OR l.email LIKE :kw OR l.company_name LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $filename = 'crm-leads-' . gmdate('Ymd-His') . '.csv';
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'wb');
        if (!is_resource($out)) {
            return;
        }

        $customDefs = CrmSupport::customFields($pdo, 'lead', true);
        $customHeaders = array_map(
            static fn (array $field): string => (string) ($field[$headerLang === 'zh' ? 'field_label' : 'field_key'] ?? ''),
            $customDefs
        );
        $customKeys = array_map(static fn (array $field): string => (string) ($field['field_key'] ?? ''), $customDefs);

        $headers = $headerLang === 'zh'
            ? [
                'ID',
                '线索名称',
                '手机号',
                '邮箱',
                '企业名称',
                '国家代码',
                '语言代码',
                '来源渠道',
                '意向等级',
                '状态',
                '负责人ID',
                '负责人账号',
                '下次跟进时间',
                '最近联系时间',
                '创建时间',
                '更新时间',
            ]
            : [
                'id',
                'lead_name',
                'mobile',
                'email',
                'company_name',
                'country_code',
                'language_code',
                'source_channel',
                'intent_level',
                'status',
                'owner_user_id',
                'owner_username',
                'next_followup_at',
                'last_contact_at',
                'created_at',
                'updated_at',
            ];
        if ($customHeaders !== []) {
            $headers = array_merge($headers, $customHeaders);
        }
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $line = [
                (string) ((int) ($row['id'] ?? 0)),
                (string) ($row['lead_name'] ?? ''),
                (string) ($row['mobile'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['company_name'] ?? ''),
                (string) ($row['country_code'] ?? ''),
                (string) ($row['language_code'] ?? ''),
                (string) ($row['source_channel'] ?? ''),
                (string) ($row['intent_level'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ((int) ($row['owner_user_id'] ?? 0)),
                (string) ($row['owner_username'] ?? ''),
                (string) ($row['next_followup_at'] ?? ''),
                (string) ($row['last_contact_at'] ?? ''),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
            ];
            if ($customKeys !== []) {
                $customValues = CrmSupport::customFieldsFromExtra($row['extra_json'] ?? null);
                foreach ($customKeys as $fieldKey) {
                    $line[] = (string) ($customValues[$fieldKey] ?? '');
                }
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    public static function convertLead(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.leads.convert');
        $data = Request::jsonBody();
        $leadId = Request::int($data, 'lead_id', 0);
        if ($leadId <= 0) {
            Response::json(['message' => 'lead_id is required'], 422);
            return;
        }

        $pdo->beginTransaction();
        try {
            $leadStmt = $pdo->prepare('SELECT * FROM qiling_crm_leads WHERE id = :id LIMIT 1 FOR UPDATE');
            $leadStmt->execute(['id' => $leadId]);
            $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($lead)) {
                $pdo->rollBack();
                Response::json(['message' => 'lead not found'], 404);
                return;
            }
            if (!empty($lead['deleted_at'])) {
                $pdo->rollBack();
                Response::json(['message' => 'lead is in recycle bin'], 422);
                return;
            }

            CrmService::assertWritable(
                $user,
                (int) ($lead['owner_user_id'] ?? 0),
                (int) ($lead['created_by'] ?? 0)
            );

            $ownerUserId = (int) ($lead['owner_user_id'] ?? 0);
            if ($ownerUserId <= 0) {
                $ownerUserId = (int) $user['id'];
            }

            $companyId = Request::int($data, 'company_id', (int) ($lead['related_company_id'] ?? 0));
            if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
                throw new \RuntimeException('company not found');
            }
            if ($companyId <= 0) {
                $companyId = CrmSupport::createCompanyFromLead($pdo, $lead, $ownerUserId, (int) $user['id']);
            }

            $contactId = Request::int($data, 'contact_id', (int) ($lead['related_contact_id'] ?? 0));
            if ($contactId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_contacts', $contactId)) {
                throw new \RuntimeException('contact not found');
            }
            if ($contactId <= 0) {
                $contactId = CrmSupport::createContactFromLead($pdo, $lead, $companyId, $ownerUserId, (int) $user['id']);
            }

            $dealId = 0;
            $createDeal = CrmSupport::boolValue($data['create_deal'] ?? 1);
            if ($createDeal) {
                $dealId = CrmSupport::createDealFromLead($pdo, $lead, $companyId, $contactId, $ownerUserId, (int) $user['id'], $data);
            }

            $updateLead = $pdo->prepare(
                'UPDATE qiling_crm_leads
                 SET status = :status,
                     related_company_id = :related_company_id,
                     related_contact_id = :related_contact_id,
                     last_contact_at = :last_contact_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $now = gmdate('Y-m-d H:i:s');
            $updateLead->execute([
                'status' => 'converted',
                'related_company_id' => $companyId,
                'related_contact_id' => $contactId,
                'last_contact_at' => $now,
                'updated_at' => $now,
                'id' => $leadId,
            ]);

            Audit::log((int) $user['id'], 'crm.lead.convert', 'crm_lead', $leadId, 'Convert crm lead', [
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'deal_id' => $dealId > 0 ? $dealId : null,
            ]);

            $pdo->commit();
            $automationSummary = [
                'matched' => 0,
                'executed' => 0,
                'success' => 0,
                'failed' => 0,
            ];
            try {
                $automationResult = CrmAutomationService::executeFieldChange(
                    $pdo,
                    $user,
                    'lead',
                    $leadId,
                    'status',
                    (string) ($lead['status'] ?? ''),
                    'converted',
                    array_merge($lead, [
                        'id' => $leadId,
                        'status' => 'converted',
                        'related_company_id' => $companyId,
                        'related_contact_id' => $contactId,
                    ])
                );
                $automationSummary = self::mergeAutomationSummary($automationSummary, $automationResult);
            } catch (\Throwable) {
                // ignore automation errors, lead conversion already committed
            }
            Response::json([
                'lead_id' => $leadId,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'deal_id' => $dealId > 0 ? $dealId : null,
                'automation' => $automationSummary,
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm lead convert failed', $e);
        }
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function mergeAutomationSummary(array $base, array $result): array
    {
        $base['matched'] = (int) ($base['matched'] ?? 0) + (int) ($result['matched'] ?? 0);
        $base['executed'] = (int) ($base['executed'] ?? 0) + (int) ($result['executed'] ?? 0);
        $base['success'] = (int) ($base['success'] ?? 0) + (int) ($result['success'] ?? 0);
        $base['failed'] = (int) ($base['failed'] ?? 0) + (int) ($result['failed'] ?? 0);
        return $base;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findDuplicateLeadByIdentity(
        PDO $pdo,
        string $mobile,
        string $email,
        string $companyName,
        ?int $excludeId
    ): ?array
    {
        $rule = CrmSupport::dedupeRule($pdo, 'lead');
        if (!$rule['enabled']) {
            return null;
        }

        $mobile = trim($mobile);
        $email = strtolower(trim($email));
        $companyName = strtolower(trim($companyName));
        if (
            (!$rule['match_mobile'] || $mobile === '')
            && (!$rule['match_email'] || $email === '')
            && (!$rule['match_company'] || $companyName === '')
        ) {
            return null;
        }

        $sql = 'SELECT id, lead_name, mobile, email, owner_user_id, created_by, company_name, country_code, language_code, source_channel, intent_level, status, next_followup_at
                FROM qiling_crm_leads
                WHERE deleted_at IS NULL';
        $params = [];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

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
            $params['company_name'] = $companyName;
        }
        if ($or === []) {
            return null;
        }

        $sql .= ' AND (' . implode(' OR ', $or) . ')';

        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

}

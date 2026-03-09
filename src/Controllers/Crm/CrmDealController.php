<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmAutomationService;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmDealController
{
    public static function deals(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.deals.view');
        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $manageAll = CrmService::canManageAll($user);
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('deal_status');
        $pipelineKey = CrmSupport::queryStr('pipeline_key');
        $stageKey = CrmSupport::queryStr('stage_key');
        $scope = strtolower(CrmSupport::queryStr('scope'));
        if (!in_array($scope, ['', 'visible', 'mine', 'team', 'department', 'public'], true)) {
            $scope = '';
        }
        $visibilityLevel = strtolower(CrmSupport::queryStr('visibility_level'));
        if (!in_array($visibilityLevel, ['', 'private', 'team', 'department', 'public'], true)) {
            $visibilityLevel = '';
        }

        $sql = 'SELECT d.*,
                       cp.company_name,
                       ct.contact_name,
                       l.lead_name,
                       ou.username AS owner_username,
                       cu.username AS creator_username
                FROM qiling_crm_deals d
                LEFT JOIN qiling_crm_companies cp ON cp.id = d.company_id
                LEFT JOIN qiling_crm_contacts ct ON ct.id = d.contact_id
                LEFT JOIN qiling_crm_leads l ON l.id = d.lead_id
                LEFT JOIN qiling_users ou ON ou.id = d.owner_user_id
                LEFT JOIN qiling_users cu ON cu.id = d.created_by
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND d.owner_user_id = :owner_user_id';
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
                'd',
                true,
                $scope === 'mine'
            );
        }
        if (in_array($scope, ['team', 'department', 'public'], true)) {
            $sql .= ' AND d.visibility_level = :scope_visibility_level';
            $params['scope_visibility_level'] = $scope;
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND d.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND d.deal_status = :deal_status';
            $params['deal_status'] = $status;
        }
        if ($pipelineKey !== '') {
            $sql .= ' AND d.pipeline_key = :pipeline_key';
            $params['pipeline_key'] = $pipelineKey;
        }
        if ($stageKey !== '') {
            $sql .= ' AND d.stage_key = :stage_key';
            $params['stage_key'] = $stageKey;
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_deals', 'ft_qiling_crm_deals_name')
            ) {
                $sql .= ' AND MATCH (d.deal_name) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (d.deal_name LIKE :kw OR cp.company_name LIKE :kw OR ct.contact_name LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        if ($cursor > 0) {
            $sql .= ' AND d.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY d.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        foreach ($rows as &$row) {
            $row['amount'] = round((float) ($row['amount'] ?? 0), 2);
            $row['extra'] = CrmSupport::decodeJsonObject($row['extra_json'] ?? null);
            $row['custom_fields'] = CrmSupport::customFieldsFromExtra($row['extra']);
            unset($row['extra_json']);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function createDeal(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.deals.edit');
        $data = Request::jsonBody();

        $dealName = Request::str($data, 'deal_name');
        if ($dealName === '') {
            Response::json(['message' => 'deal_name is required'], 422);
            return;
        }

        $companyId = Request::int($data, 'company_id', 0);
        $contactId = Request::int($data, 'contact_id', 0);
        $leadId = Request::int($data, 'lead_id', 0);
        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
            return;
        }
        if ($contactId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_contacts', $contactId)) {
            Response::json(['message' => 'contact not found'], 404);
            return;
        }
        if ($leadId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_leads', $leadId)) {
            Response::json(['message' => 'lead not found'], 404);
            return;
        }

        $ownerUserId = CrmService::resolveOwnerInput($pdo, $user, Request::int($data, 'owner_user_id', 0));
        $ownerOrg = CrmSupport::resolveOwnerOrgScope($pdo, $ownerUserId);
        $visibilityLevel = CrmSupport::normalizeVisibilityLevel(Request::str($data, 'visibility_level', 'private'));
        $now = gmdate('Y-m-d H:i:s');
        $amount = max(0.0, round((float) ($data['amount'] ?? 0), 2));
        $pipelineKey = Request::str($data, 'pipeline_key', 'default');
        $stageKey = Request::str($data, 'stage_key', 'new');
        $dealStatus = CrmSupport::normalizeStatus(Request::str($data, 'deal_status', 'open'), ['open', 'won', 'lost'], 'open');
        $extra = CrmSupport::jsonEncode($data['extra'] ?? null);
        if (array_key_exists('custom_fields', $data) && is_array($data['custom_fields'])) {
            $customFields = CrmSupport::sanitizeCustomFields($data['custom_fields']);
            $extra = CrmSupport::mergeCustomFieldsToExtra($extra, $customFields, true);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_deals
             (deal_name, company_id, contact_id, lead_id, pipeline_key, stage_key, deal_status, currency_code, amount, expected_close_date, won_at, lost_reason, source_channel, owner_user_id, owner_team_id, owner_department_id, visibility_level, extra_json, created_by, created_at, updated_at)
             VALUES
             (:deal_name, :company_id, :contact_id, :lead_id, :pipeline_key, :stage_key, :deal_status, :currency_code, :amount, :expected_close_date, :won_at, :lost_reason, :source_channel, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'deal_name' => $dealName,
            'company_id' => $companyId > 0 ? $companyId : null,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'lead_id' => $leadId > 0 ? $leadId : null,
            'pipeline_key' => $pipelineKey,
            'stage_key' => $stageKey,
            'deal_status' => $dealStatus,
            'currency_code' => CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', 'CNY')),
            'amount' => $amount,
            'expected_close_date' => CrmSupport::parseDate(Request::str($data, 'expected_close_date')),
            'won_at' => null,
            'lost_reason' => Request::str($data, 'lost_reason'),
            'source_channel' => Request::str($data, 'source_channel'),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'extra_json' => $extra,
            'created_by' => (int) $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dealId = (int) $pdo->lastInsertId();
        self::appendDealStageLog(
            $pdo,
            [
                'id' => $dealId,
                'pipeline_key' => '',
                'stage_key' => '',
                'deal_status' => '',
                'created_at' => $now,
            ],
            [
                'pipeline_key' => $pipelineKey,
                'stage_key' => $stageKey,
                'deal_status' => $dealStatus,
            ],
            (int) ($user['id'] ?? 0)
        );
        Audit::log((int) $user['id'], 'crm.deal.create', 'crm_deal', $dealId, 'Create crm deal', [
            'deal_name' => $dealName,
            'owner_user_id' => $ownerUserId,
            'amount' => $amount,
        ]);

        Response::json(['deal_id' => $dealId], 201);
    }

    public static function updateDeal(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.deals.edit');
        $data = Request::jsonBody();
        $dealId = Request::int($data, 'deal_id', 0);
        if ($dealId <= 0) {
            Response::json(['message' => 'deal_id is required'], 422);
            return;
        }

        $row = CrmSupport::findWritableRecord($pdo, 'qiling_crm_deals', $dealId, $user);
        if (!is_array($row)) {
            return;
        }

        $companyId = Request::int($data, 'company_id', (int) ($row['company_id'] ?? 0));
        $contactId = Request::int($data, 'contact_id', (int) ($row['contact_id'] ?? 0));
        $leadId = Request::int($data, 'lead_id', (int) ($row['lead_id'] ?? 0));
        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
            return;
        }
        if ($contactId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_contacts', $contactId)) {
            Response::json(['message' => 'contact not found'], 404);
            return;
        }
        if ($leadId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_leads', $leadId)) {
            Response::json(['message' => 'lead not found'], 404);
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
        $dealName = Request::str($data, 'deal_name', (string) ($row['deal_name'] ?? ''));
        if ($dealName === '') {
            Response::json(['message' => 'deal_name is required'], 422);
            return;
        }

        $dealStatus = CrmSupport::normalizeStatus(Request::str($data, 'deal_status', (string) ($row['deal_status'] ?? 'open')), ['open', 'won', 'lost'], 'open');
        $pipelineKey = Request::str($data, 'pipeline_key', (string) ($row['pipeline_key'] ?? 'default'));
        $stageKey = Request::str($data, 'stage_key', (string) ($row['stage_key'] ?? 'new'));
        $wonAt = null;
        if ($dealStatus === 'won') {
            $wonAt = array_key_exists('won_at', $data)
                ? CrmSupport::parseDateTime(Request::str($data, 'won_at'))
                : (($row['won_at'] ?? null) ?: gmdate('Y-m-d H:i:s'));
        }
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

        $stmt = $pdo->prepare(
            'UPDATE qiling_crm_deals
             SET deal_name = :deal_name,
                 company_id = :company_id,
                 contact_id = :contact_id,
                 lead_id = :lead_id,
                 pipeline_key = :pipeline_key,
                 stage_key = :stage_key,
                 deal_status = :deal_status,
                 currency_code = :currency_code,
                 amount = :amount,
                 expected_close_date = :expected_close_date,
                 won_at = :won_at,
                 lost_reason = :lost_reason,
                 source_channel = :source_channel,
                 owner_user_id = :owner_user_id,
                 owner_team_id = :owner_team_id,
                 owner_department_id = :owner_department_id,
                 visibility_level = :visibility_level,
                 extra_json = :extra_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'deal_name' => $dealName,
            'company_id' => $companyId > 0 ? $companyId : null,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'lead_id' => $leadId > 0 ? $leadId : null,
            'pipeline_key' => $pipelineKey,
            'stage_key' => $stageKey,
            'deal_status' => $dealStatus,
            'currency_code' => CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', (string) ($row['currency_code'] ?? 'CNY'))),
            'amount' => max(0.0, round((float) ($data['amount'] ?? $row['amount'] ?? 0), 2)),
            'expected_close_date' => array_key_exists('expected_close_date', $data)
                ? CrmSupport::parseDate(Request::str($data, 'expected_close_date'))
                : ($row['expected_close_date'] ?? null),
            'won_at' => $wonAt,
            'lost_reason' => Request::str($data, 'lost_reason', (string) ($row['lost_reason'] ?? '')),
            'source_channel' => Request::str($data, 'source_channel', (string) ($row['source_channel'] ?? '')),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'extra_json' => $extra,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $dealId,
        ]);

        self::appendDealStageLog(
            $pdo,
            $row,
            [
                'pipeline_key' => $pipelineKey,
                'stage_key' => $stageKey,
                'deal_status' => $dealStatus,
            ],
            (int) ($user['id'] ?? 0)
        );

        $automationSummary = [
            'matched' => 0,
            'executed' => 0,
            'success' => 0,
            'failed' => 0,
        ];
        $afterSnapshot = array_merge($row, [
            'id' => $dealId,
            'pipeline_key' => $pipelineKey,
            'stage_key' => $stageKey,
            'deal_status' => $dealStatus,
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
        ]);

        if ((string) ($row['deal_status'] ?? '') !== $dealStatus) {
            $res = CrmAutomationService::executeFieldChange(
                $pdo,
                $user,
                'deal',
                $dealId,
                'deal_status',
                (string) ($row['deal_status'] ?? ''),
                $dealStatus,
                $afterSnapshot
            );
            $automationSummary = self::mergeAutomationSummary($automationSummary, $res);
        }
        if ((string) ($row['stage_key'] ?? '') !== $stageKey) {
            $res = CrmAutomationService::executeFieldChange(
                $pdo,
                $user,
                'deal',
                $dealId,
                'stage_key',
                (string) ($row['stage_key'] ?? ''),
                $stageKey,
                $afterSnapshot
            );
            $automationSummary = self::mergeAutomationSummary($automationSummary, $res);
        }
        if ((string) ($row['pipeline_key'] ?? '') !== $pipelineKey) {
            $res = CrmAutomationService::executeFieldChange(
                $pdo,
                $user,
                'deal',
                $dealId,
                'pipeline_key',
                (string) ($row['pipeline_key'] ?? ''),
                $pipelineKey,
                $afterSnapshot
            );
            $automationSummary = self::mergeAutomationSummary($automationSummary, $res);
        }

        Audit::log((int) $user['id'], 'crm.deal.update', 'crm_deal', $dealId, 'Update crm deal', [
            'owner_user_id' => $ownerUserId,
            'deal_status' => $dealStatus,
            'pipeline_key' => $pipelineKey,
            'stage_key' => $stageKey,
            'automation_executed' => $automationSummary['executed'],
        ]);

        Response::json([
            'deal_id' => $dealId,
            'automation' => $automationSummary,
        ]);
    }

    public static function batchUpdateDeals(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.deals.edit');
        $data = Request::jsonBody();

        $dealIds = CrmSupport::positiveIdList($data['deal_ids'] ?? null, 500);
        if ($dealIds === []) {
            Response::json(['message' => 'deal_ids is required'], 422);
            return;
        }

        $hasStatus = array_key_exists('deal_status', $data);
        $hasOwner = array_key_exists('owner_user_id', $data);
        if (!$hasStatus && !$hasOwner) {
            Response::json(['message' => 'at least one update field is required'], 422);
            return;
        }

        $dealStatus = null;
        $wonAt = null;
        if ($hasStatus) {
            $dealStatus = CrmSupport::normalizeStatus(Request::str($data, 'deal_status'), ['open', 'won', 'lost'], '');
            if ($dealStatus === '') {
                Response::json(['message' => 'deal_status invalid'], 422);
                return;
            }
            $wonAt = $dealStatus === 'won'
                ? (CrmSupport::parseDateTime(Request::str($data, 'won_at')) ?? gmdate('Y-m-d H:i:s'))
                : null;
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
        foreach ($dealIds as $index => $id) {
            $key = 'id_' . $index;
            $idPlaceholders[] = ':' . $key;
            $idParams[$key] = $id;
        }

        $stmt = $pdo->prepare(
            'SELECT id, owner_user_id, created_by, pipeline_key, stage_key, deal_status, created_at
             FROM qiling_crm_deals
             WHERE id IN (' . implode(',', $idPlaceholders) . ')'
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
                'message' => 'forbidden: no writable deals',
                'summary' => [
                    'total' => count($dealIds),
                    'found' => count($foundIds),
                    'updated' => 0,
                    'affected_rows' => 0,
                    'skipped_not_found' => count($dealIds) - count($foundIds),
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
            $updateSet[] = 'deal_status = :deal_status';
            $updateSet[] = 'won_at = :won_at';
            $updateParams['deal_status'] = $dealStatus;
            $updateParams['won_at'] = $wonAt;
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
            'UPDATE qiling_crm_deals
             SET ' . implode(', ', $updateSet) . '
             WHERE id IN (' . implode(',', $updateIds) . ')'
        );
        $updateStmt->execute($updateParams);
        $affectedRows = (int) $updateStmt->rowCount();

        $automationSummary = [
            'matched' => 0,
            'executed' => 0,
            'success' => 0,
            'failed' => 0,
        ];
        if ($hasStatus && is_string($dealStatus) && $dealStatus !== '') {
            foreach ($rows as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0 || !in_array($id, $writableIds, true)) {
                    continue;
                }
                $fromStatus = (string) ($item['deal_status'] ?? '');
                if ($fromStatus === $dealStatus) {
                    continue;
                }

                self::appendDealStageLog(
                    $pdo,
                    $item,
                    [
                        'pipeline_key' => (string) ($item['pipeline_key'] ?? ''),
                        'stage_key' => (string) ($item['stage_key'] ?? ''),
                        'deal_status' => $dealStatus,
                    ],
                    (int) ($user['id'] ?? 0)
                );

                $result = CrmAutomationService::executeFieldChange(
                    $pdo,
                    $user,
                    'deal',
                    $id,
                    'deal_status',
                    $fromStatus,
                    $dealStatus,
                    array_merge($item, ['deal_status' => $dealStatus])
                );
                $automationSummary = self::mergeAutomationSummary($automationSummary, $result);
            }
        }

        Audit::log((int) $user['id'], 'crm.deal.batch_update', 'crm_deal', 0, 'Batch update crm deals', [
            'deal_ids' => $writableIds,
            'deal_status' => $dealStatus,
            'owner_user_id' => $ownerUserId,
            'requested_total' => count($dealIds),
            'automation_executed' => $automationSummary['executed'],
        ]);

        Response::json([
            'summary' => [
                'total' => count($dealIds),
                'found' => count($foundIds),
                'updated' => count($writableIds),
                'affected_rows' => $affectedRows,
                'skipped_not_found' => count($dealIds) - count($foundIds),
                'skipped_forbidden' => $forbiddenCount,
            ],
            'automation' => $automationSummary,
        ]);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function appendDealStageLog(PDO $pdo, array $before, array $after, int $userId): void
    {
        $dealId = (int) ($after['id'] ?? $before['id'] ?? 0);
        if ($dealId <= 0) {
            return;
        }

        $fromPipeline = trim((string) ($before['pipeline_key'] ?? ''));
        $toPipeline = trim((string) ($after['pipeline_key'] ?? ''));
        $pipelineKey = $toPipeline !== '' ? $toPipeline : ($fromPipeline !== '' ? $fromPipeline : 'default');

        $fromStage = trim((string) ($before['stage_key'] ?? ''));
        $toStage = trim((string) ($after['stage_key'] ?? ''));
        $fromStatus = trim((string) ($before['deal_status'] ?? ''));
        $toStatus = trim((string) ($after['deal_status'] ?? ''));

        if (
            $fromPipeline === $toPipeline
            && $fromStage === $toStage
            && $fromStatus === $toStatus
        ) {
            return;
        }

        $changedAt = gmdate('Y-m-d H:i:s');
        $startAt = self::latestDealStageChangedAt($pdo, $dealId);
        if ($startAt === null) {
            $createdAt = trim((string) ($before['created_at'] ?? ''));
            $startAt = $createdAt !== '' ? $createdAt : $changedAt;
        }

        $durationSeconds = 0;
        $changedTs = strtotime($changedAt);
        $startTs = strtotime($startAt);
        if ($changedTs !== false && $startTs !== false) {
            $durationSeconds = max(0, $changedTs - $startTs);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_deal_stage_logs
             (deal_id, pipeline_key, from_stage_key, to_stage_key, from_status, to_status, duration_seconds, changed_by, changed_at, created_at)
             VALUES
             (:deal_id, :pipeline_key, :from_stage_key, :to_stage_key, :from_status, :to_status, :duration_seconds, :changed_by, :changed_at, :created_at)'
        );
        $stmt->execute([
            'deal_id' => $dealId,
            'pipeline_key' => $pipelineKey,
            'from_stage_key' => $fromStage,
            'to_stage_key' => $toStage,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'duration_seconds' => $durationSeconds,
            'changed_by' => $userId > 0 ? $userId : null,
            'changed_at' => $changedAt,
            'created_at' => $changedAt,
        ]);
    }

    private static function latestDealStageChangedAt(PDO $pdo, int $dealId): ?string
    {
        if ($dealId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT changed_at
             FROM qiling_crm_deal_stage_logs
             WHERE deal_id = :deal_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['deal_id' => $dealId]);
        $value = $stmt->fetchColumn();
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
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
}

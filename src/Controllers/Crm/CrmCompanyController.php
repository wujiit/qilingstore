<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmCompanyController
{
    public static function companies(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.companies.view');
        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $manageAll = CrmService::canManageAll($user);
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('status');
        $companyType = CrmSupport::queryStr('company_type');
        $countryCode = strtoupper(CrmSupport::queryStr('country_code'));
        $scope = strtolower(CrmSupport::queryStr('scope'));
        if (!in_array($scope, ['', 'visible', 'mine', 'team', 'department', 'public'], true)) {
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

        $sql = 'SELECT c.*,
                       ou.username AS owner_username,
                       cu.username AS creator_username
                FROM qiling_crm_companies c
                LEFT JOIN qiling_users ou ON ou.id = c.owner_user_id
                LEFT JOIN qiling_users cu ON cu.id = c.created_by
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND c.owner_user_id = :owner_user_id';
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
                'c',
                true,
                $scope === 'mine'
            );
        }
        if (in_array($scope, ['team', 'department', 'public'], true)) {
            $sql .= ' AND c.visibility_level = :scope_visibility_level';
            $params['scope_visibility_level'] = $scope;
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND c.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND c.status = :status';
            $params['status'] = $status;
        }
        if ($companyType !== '') {
            $sql .= ' AND c.company_type = :company_type';
            $params['company_type'] = $companyType;
        }
        if ($countryCode !== '') {
            $sql .= ' AND c.country_code = :country_code';
            $params['country_code'] = $countryCode;
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_companies', 'ft_qiling_crm_companies_search')
            ) {
                $sql .= ' AND MATCH (c.company_name, c.website, c.industry) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (c.company_name LIKE :kw OR c.website LIKE :kw OR c.industry LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        if ($view === 'active') {
            $sql .= ' AND c.deleted_at IS NULL AND c.is_archived = 0';
        } elseif ($view === 'archived') {
            $sql .= ' AND c.deleted_at IS NULL AND c.is_archived = 1';
        } elseif ($view === 'recycle') {
            $sql .= ' AND c.deleted_at IS NOT NULL';
        }
        if ($cursor > 0) {
            $sql .= ' AND c.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
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

    public static function createCompany(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.companies.edit');
        $data = Request::jsonBody();

        $companyName = Request::str($data, 'company_name');
        if ($companyName === '') {
            Response::json(['message' => 'company_name is required'], 422);
            return;
        }

        $duplicate = self::findDuplicateCompanyByName($pdo, $companyName, null);
        if (is_array($duplicate) && !CrmSupport::boolValue($data['allow_duplicate'] ?? false)) {
            Response::json([
                'message' => 'duplicate company exists',
                'duplicate_company_id' => (int) ($duplicate['id'] ?? 0),
            ], 409);
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

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_companies
             (company_name, company_type, country_code, website, industry, source_channel, owner_user_id, owner_team_id, owner_department_id, visibility_level, status, tags_json, extra_json, created_by, created_at, updated_at)
             VALUES
             (:company_name, :company_type, :country_code, :website, :industry, :source_channel, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :status, :tags_json, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'company_name' => $companyName,
            'company_type' => Request::str($data, 'company_type', 'enterprise'),
            'country_code' => strtoupper(Request::str($data, 'country_code')),
            'website' => Request::str($data, 'website'),
            'industry' => Request::str($data, 'industry'),
            'source_channel' => Request::str($data, 'source_channel'),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'status' => CrmSupport::normalizeStatus(Request::str($data, 'status', 'active'), ['active', 'inactive'], 'active'),
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'extra_json' => $extra,
            'created_by' => (int) $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $companyId = (int) $pdo->lastInsertId();
        Audit::log((int) $user['id'], 'crm.company.create', 'crm_company', $companyId, 'Create crm company', [
            'company_name' => $companyName,
            'owner_user_id' => $ownerUserId,
        ]);

        Response::json(['company_id' => $companyId], 201);
    }

    public static function updateCompany(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.companies.edit');
        $data = Request::jsonBody();
        $companyId = Request::int($data, 'company_id', 0);
        if ($companyId <= 0) {
            Response::json(['message' => 'company_id is required'], 422);
            return;
        }

        $row = CrmSupport::findWritableRecord($pdo, 'qiling_crm_companies', $companyId, $user);
        if (!is_array($row)) {
            return;
        }
        if (!empty($row['deleted_at'])) {
            Response::json(['message' => 'company is in recycle bin'], 422);
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
        $companyName = Request::str($data, 'company_name', (string) ($row['company_name'] ?? ''));
        if ($companyName === '') {
            Response::json(['message' => 'company_name is required'], 422);
            return;
        }

        $duplicate = self::findDuplicateCompanyByName($pdo, $companyName, $companyId);
        if (is_array($duplicate) && !CrmSupport::boolValue($data['allow_duplicate'] ?? false)) {
            Response::json([
                'message' => 'duplicate company exists',
                'duplicate_company_id' => (int) ($duplicate['id'] ?? 0),
            ], 409);
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

        $stmt = $pdo->prepare(
            'UPDATE qiling_crm_companies
             SET company_name = :company_name,
                 company_type = :company_type,
                 country_code = :country_code,
                 website = :website,
                 industry = :industry,
                 source_channel = :source_channel,
                 owner_user_id = :owner_user_id,
                 owner_team_id = :owner_team_id,
                 owner_department_id = :owner_department_id,
                 visibility_level = :visibility_level,
                 status = :status,
                 tags_json = :tags_json,
                 extra_json = :extra_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'company_name' => $companyName,
            'company_type' => Request::str($data, 'company_type', (string) ($row['company_type'] ?? 'enterprise')),
            'country_code' => strtoupper(Request::str($data, 'country_code', (string) ($row['country_code'] ?? ''))),
            'website' => Request::str($data, 'website', (string) ($row['website'] ?? '')),
            'industry' => Request::str($data, 'industry', (string) ($row['industry'] ?? '')),
            'source_channel' => Request::str($data, 'source_channel', (string) ($row['source_channel'] ?? '')),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'status' => CrmSupport::normalizeStatus(Request::str($data, 'status', (string) ($row['status'] ?? 'active')), ['active', 'inactive'], 'active'),
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'extra_json' => $extra,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $companyId,
        ]);

        Audit::log((int) $user['id'], 'crm.company.update', 'crm_company', $companyId, 'Update crm company', [
            'owner_user_id' => $ownerUserId,
        ]);

        Response::json(['company_id' => $companyId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findDuplicateCompanyByName(\PDO $pdo, string $companyName, ?int $excludeId): ?array
    {
        $rule = CrmSupport::dedupeRule($pdo, 'company');
        if (!$rule['enabled'] || !$rule['match_company']) {
            return null;
        }

        $name = strtolower(trim($companyName));
        if ($name === '') {
            return null;
        }

        $sql = 'SELECT id, company_name, owner_user_id, created_by
                FROM qiling_crm_companies
                WHERE deleted_at IS NULL
                  AND company_name = :company_name';
        $params = ['company_name' => $name];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

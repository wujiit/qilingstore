<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Auth;
use Qiling\Core\CrmService;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmSupport
{
    /** @var array<int,string> */
    private const ALLOWED_TABLES = [
        'qiling_crm_companies',
        'qiling_crm_contacts',
        'qiling_crm_leads',
        'qiling_crm_deals',
        'qiling_crm_activities',
        'qiling_crm_departments',
        'qiling_crm_teams',
        'qiling_crm_team_members',
    ];

    /**
     * @return array{0:array<string,mixed>,1:PDO}
     */
    public static function context(): array
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $pdo = Database::pdo();
        CrmService::ensureTables($pdo);
        CrmService::requireCrmUser($user);
        return [$user, $pdo];
    }

    public static function requirePermission(array $user, string $permission): void
    {
        CrmService::requireCrmPermission($user, $permission);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function paginationParams(int $defaultLimit, int $maxLimit): array
    {
        $limit = self::queryInt('limit') ?? $defaultLimit;
        $limit = max(1, min($limit, $maxLimit));
        $queryLimit = $limit + 1;
        $cursor = self::queryInt('cursor') ?? 0;
        if ($cursor < 0) {
            $cursor = 0;
        }
        return [$limit, $queryLimit, $cursor];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0:array<int, array<string, mixed>>,1:array<string,mixed>}
     */
    public static function sliceRows(array $rows, int $limit, int $cursor): array
    {
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $tail = $rows[count($rows) - 1];
            $next = is_array($tail) ? (int) ($tail['id'] ?? 0) : 0;
            if ($next > 0) {
                $nextCursor = $next;
            }
        }

        return [
            $rows,
            [
                'limit' => $limit,
                'cursor' => $cursor > 0 ? $cursor : null,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function singleRowSummary(PDO $pdo, string $baseSql, ?int $ownerFilter, array $extraParams = []): array
    {
        $sql = $baseSql . ' WHERE 1 = 1';
        $params = $extraParams;
        if ($ownerFilter !== null) {
            $sql .= ' AND owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $ownerFilter;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findWritableRecord(PDO $pdo, string $table, int $id, array $user): ?array
    {
        if ($id <= 0 || !self::isAllowedTable($table)) {
            Response::json(['message' => 'record not found'], 404);
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            Response::json(['message' => 'record not found'], 404);
            return null;
        }

        CrmService::assertWritable(
            $user,
            (int) ($row['owner_user_id'] ?? 0),
            (int) ($row['created_by'] ?? 0)
        );
        return $row;
    }

    /**
     * @param array<string, mixed> $lead
     */
    public static function createCompanyFromLead(PDO $pdo, array $lead, int $ownerUserId, int $creatorUserId): int
    {
        $name = trim((string) ($lead['company_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($lead['lead_name'] ?? '未命名企业');
        }
        $now = gmdate('Y-m-d H:i:s');
        $ownerOrg = self::resolveOwnerOrgScope($pdo, $ownerUserId);

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_companies
             (company_name, company_type, country_code, website, industry, source_channel, owner_user_id, owner_team_id, owner_department_id, visibility_level, status, tags_json, extra_json, created_by, created_at, updated_at)
             VALUES
             (:company_name, :company_type, :country_code, :website, :industry, :source_channel, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :status, :tags_json, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'company_name' => $name,
            'company_type' => 'enterprise',
            'country_code' => (string) ($lead['country_code'] ?? ''),
            'website' => '',
            'industry' => '',
            'source_channel' => (string) ($lead['source_channel'] ?? ''),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => 'private',
            'status' => 'active',
            'tags_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'extra_json' => null,
            'created_by' => $creatorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $lead
     */
    public static function createContactFromLead(PDO $pdo, array $lead, int $companyId, int $ownerUserId, int $creatorUserId): int
    {
        $name = trim((string) ($lead['lead_name'] ?? ''));
        if ($name === '') {
            $name = '未命名联系人';
        }
        $now = gmdate('Y-m-d H:i:s');
        $ownerOrg = self::resolveOwnerOrgScope($pdo, $ownerUserId);

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_contacts
             (company_id, contact_name, mobile, email, wechat, whatsapp, title, country_code, language_code, source_channel, owner_user_id, owner_team_id, owner_department_id, visibility_level, status, tags_json, extra_json, created_by, created_at, updated_at)
             VALUES
             (:company_id, :contact_name, :mobile, :email, :wechat, :whatsapp, :title, :country_code, :language_code, :source_channel, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :status, :tags_json, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'contact_name' => $name,
            'mobile' => (string) ($lead['mobile'] ?? ''),
            'email' => (string) ($lead['email'] ?? ''),
            'wechat' => '',
            'whatsapp' => '',
            'title' => '',
            'country_code' => (string) ($lead['country_code'] ?? ''),
            'language_code' => (string) ($lead['language_code'] ?? ''),
            'source_channel' => (string) ($lead['source_channel'] ?? ''),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => 'private',
            'status' => 'active',
            'tags_json' => (string) ($lead['tags_json'] ?? json_encode([], JSON_UNESCAPED_UNICODE)),
            'extra_json' => null,
            'created_by' => $creatorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $input
     */
    public static function createDealFromLead(PDO $pdo, array $lead, int $companyId, int $contactId, int $ownerUserId, int $creatorUserId, array $input): int
    {
        $name = Request::str($input, 'deal_name');
        if ($name === '') {
            $name = trim((string) ($lead['lead_name'] ?? '')) !== ''
                ? ((string) ($lead['lead_name'] ?? '') . ' - 商机')
                : '线索转商机';
        }
        $amount = max(0.0, round((float) ($input['deal_amount'] ?? 0), 2));
        $currencyCode = self::normalizeCurrency(Request::str($input, 'currency_code', 'CNY'));
        $pipelineKey = Request::str($input, 'pipeline_key', 'default');
        $stageKey = Request::str($input, 'stage_key', 'qualified');
        $now = gmdate('Y-m-d H:i:s');
        $ownerOrg = self::resolveOwnerOrgScope($pdo, $ownerUserId);

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_deals
             (deal_name, company_id, contact_id, lead_id, pipeline_key, stage_key, deal_status, currency_code, amount, expected_close_date, won_at, lost_reason, source_channel, owner_user_id, owner_team_id, owner_department_id, visibility_level, extra_json, created_by, created_at, updated_at)
             VALUES
             (:deal_name, :company_id, :contact_id, :lead_id, :pipeline_key, :stage_key, :deal_status, :currency_code, :amount, :expected_close_date, :won_at, :lost_reason, :source_channel, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'deal_name' => $name,
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'lead_id' => (int) ($lead['id'] ?? 0),
            'pipeline_key' => $pipelineKey,
            'stage_key' => $stageKey,
            'deal_status' => 'open',
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'expected_close_date' => self::parseDate(Request::str($input, 'expected_close_date')),
            'won_at' => null,
            'lost_reason' => '',
            'source_channel' => (string) ($lead['source_channel'] ?? ''),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => 'private',
            'extra_json' => null,
            'created_by' => $creatorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function entityExists(PDO $pdo, string $entityType, int $entityId): bool
    {
        $map = [
            'lead' => 'qiling_crm_leads',
            'contact' => 'qiling_crm_contacts',
            'company' => 'qiling_crm_companies',
            'deal' => 'qiling_crm_deals',
        ];
        $table = $map[$entityType] ?? '';
        if ($table === '') {
            return false;
        }
        return self::recordExists($pdo, $table, $entityId);
    }

    public static function recordExists(PDO $pdo, string $table, int $id): bool
    {
        if ($id <= 0 || !self::isAllowedTable($table)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<int, int>
     */
    public static function positiveIdList(mixed $raw, int $maxCount = 500): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $ids = [];
        foreach ($raw as $item) {
            if (!is_numeric($item)) {
                continue;
            }

            $id = (int) $item;
            if ($id <= 0) {
                continue;
            }

            $ids[$id] = $id;
            if (count($ids) >= $maxCount) {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<int, mixed>
     */
    public static function decodeJsonArray(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJsonObject(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function jsonEncode(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (!is_array($value)) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }

    /**
     * @param array<int, string> $allowed
     */
    public static function normalizeStatus(string $value, array $allowed, string $default): string
    {
        $value = trim($value);
        if ($value !== '' && in_array($value, $allowed, true)) {
            return $value;
        }
        return $default;
    }

    public static function normalizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return 'CNY';
        }
        if (!preg_match('/^[A-Z0-9]{3,8}$/', $value)) {
            return 'CNY';
        }
        return $value;
    }

    public static function parseDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $ts = strtotime($value);
            if ($ts === false) {
                return null;
            }
            return gmdate('Y-m-d', $ts);
        }
        return $value;
    }

    public static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    public static function hasFulltextIndex(PDO $pdo, string $table, string $indexName): bool
    {
        $table = trim($table);
        $indexName = trim($indexName);
        if ($table === '' || $indexName === '') {
            return false;
        }

        /** @var array<string,bool> $cache */
        static $cache = [];
        $cacheKey = strtolower($table . '#' . $indexName);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND INDEX_NAME = :index_name
                   AND INDEX_TYPE = :index_type
                 LIMIT 1'
            );
            $stmt->execute([
                'table_name' => $table,
                'index_name' => $indexName,
                'index_type' => 'FULLTEXT',
            ]);
            $cache[$cacheKey] = (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            $cache[$cacheKey] = false;
        }

        return $cache[$cacheKey];
    }

    public static function toFulltextBooleanQuery(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }

        // Most MySQL installations do not tokenize CJK for FULLTEXT by default.
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $keyword) === 1) {
            return '';
        }

        $parts = preg_split('/[\s,，;；@._-]+/u', $keyword);
        if (!is_array($parts)) {
            $parts = [$keyword];
        }

        $terms = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }
            $token = preg_replace('/[^A-Za-z0-9]+/', '', $token);
            if (!is_string($token)) {
                continue;
            }
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (strlen($token) < 3) {
                continue;
            }
            $terms[] = '+' . $token . '*';
            if (count($terms) >= 8) {
                break;
            }
        }

        return implode(' ', $terms);
    }

    public static function normalizeVisibilityLevel(string $value, string $default = 'private'): string
    {
        $allowed = ['private', 'team', 'department', 'public'];
        $normalized = strtolower(trim($value));
        if ($normalized !== '' && in_array($normalized, $allowed, true)) {
            return $normalized;
        }
        return $default;
    }

    /**
     * @return array{owner_team_id:?int,owner_department_id:?int}
     */
    public static function resolveOwnerOrgScope(PDO $pdo, int $ownerUserId): array
    {
        if ($ownerUserId <= 0) {
            return [
                'owner_team_id' => null,
                'owner_department_id' => null,
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT tm.team_id, COALESCE(tm.department_id, t.department_id) AS department_id
             FROM qiling_crm_team_members tm
             LEFT JOIN qiling_crm_teams t ON t.id = tm.team_id
             WHERE tm.user_id = :user_id
               AND tm.status = :member_status
               AND (t.id IS NULL OR t.status = :team_status)
             ORDER BY tm.id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $ownerUserId,
            'member_status' => 'active',
            'team_status' => 'active',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'owner_team_id' => null,
                'owner_department_id' => null,
            ];
        }

        $teamId = (int) ($row['team_id'] ?? 0);
        $departmentId = (int) ($row['department_id'] ?? 0);
        return [
            'owner_team_id' => $teamId > 0 ? $teamId : null,
            'owner_department_id' => $departmentId > 0 ? $departmentId : null,
        ];
    }

    /**
     * @return array{team_ids:array<int,int>,department_ids:array<int,int>,primary_team_id:?int,primary_department_id:?int}
     */
    public static function userOrgScope(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [
                'team_ids' => [],
                'department_ids' => [],
                'primary_team_id' => null,
                'primary_department_id' => null,
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT tm.team_id, COALESCE(tm.department_id, t.department_id) AS department_id
             FROM qiling_crm_team_members tm
             LEFT JOIN qiling_crm_teams t ON t.id = tm.team_id
             WHERE tm.user_id = :user_id
               AND tm.status = :member_status
               AND (t.id IS NULL OR t.status = :team_status)
             ORDER BY tm.id ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'member_status' => 'active',
            'team_status' => 'active',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $teamIds = [];
        $departmentIds = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row['team_id'] ?? 0);
            $departmentId = (int) ($row['department_id'] ?? 0);
            if ($teamId > 0) {
                $teamIds[$teamId] = $teamId;
            }
            if ($departmentId > 0) {
                $departmentIds[$departmentId] = $departmentId;
            }
        }

        $teamList = array_values($teamIds);
        $departmentList = array_values($departmentIds);
        return [
            'team_ids' => $teamList,
            'department_ids' => $departmentList,
            'primary_team_id' => $teamList !== [] ? $teamList[0] : null,
            'primary_department_id' => $departmentList !== [] ? $departmentList[0] : null,
        ];
    }

    public static function appendVisibilityReadScope(
        string &$sql,
        array &$params,
        PDO $pdo,
        array $user,
        string $alias = '',
        bool $includePublic = true,
        bool $mineOnly = false
    ): void {
        if (CrmService::canManageAll($user)) {
            return;
        }

        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            Response::json(['message' => 'unauthorized'], 401);
            exit;
        }

        $ownerCol = self::scopedColumn($alias, 'owner_user_id');
        $createdByCol = self::scopedColumn($alias, 'created_by');
        $visibilityCol = self::scopedColumn($alias, 'visibility_level');
        $teamCol = self::scopedColumn($alias, 'owner_team_id');
        $departmentCol = self::scopedColumn($alias, 'owner_department_id');

        $conditions = [
            $ownerCol . ' = :scope_uid',
            $createdByCol . ' = :scope_uid',
        ];
        $params['scope_uid'] = $uid;

        if (!$mineOnly) {
            if ($includePublic) {
                $conditions[] = $visibilityCol . ' = :scope_visibility_public';
                $params['scope_visibility_public'] = 'public';
            }

            $org = self::userOrgScope($pdo, $uid);
            $teamIds = $org['team_ids'] ?? [];
            if (is_array($teamIds) && $teamIds !== []) {
                $teamInSql = self::buildInPlaceholders('scope_team', $teamIds, $params);
                $conditions[] = '(' . $visibilityCol . ' = :scope_visibility_team AND ' . $teamCol . ' IN (' . $teamInSql . '))';
                $params['scope_visibility_team'] = 'team';
            }

            $departmentIds = $org['department_ids'] ?? [];
            if (is_array($departmentIds) && $departmentIds !== []) {
                $departmentInSql = self::buildInPlaceholders('scope_dept', $departmentIds, $params);
                $conditions[] = '(' . $visibilityCol . ' = :scope_visibility_department AND ' . $departmentCol . ' IN (' . $departmentInSql . '))';
                $params['scope_visibility_department'] = 'department';
            }
        }

        $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
    }

    public static function applyOwnerOrgOnWrite(PDO $pdo, array $payload, int $ownerUserId): array
    {
        $org = self::resolveOwnerOrgScope($pdo, $ownerUserId);
        $payload['owner_team_id'] = $org['owner_team_id'];
        $payload['owner_department_id'] = $org['owner_department_id'];
        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function customFields(PDO $pdo, string $entityType, bool $activeOnly = true): array
    {
        $entityType = strtolower(trim($entityType));
        if (!in_array($entityType, ['company', 'contact', 'lead', 'deal'], true)) {
            return [];
        }

        $sql = 'SELECT id, entity_type, field_key, field_label, field_type, options_json, default_value, placeholder, is_required, sort_order, status
                FROM qiling_crm_custom_fields
                WHERE entity_type = :entity_type';
        $params = ['entity_type' => $entityType];
        if ($activeOnly) {
            $sql .= ' AND status = :status';
            $params['status'] = 'active';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['is_required'] = (int) ($row['is_required'] ?? 0) === 1 ? 1 : 0;
            $row['sort_order'] = (int) ($row['sort_order'] ?? 100);
            $row['options'] = self::decodeJsonArray($row['options_json'] ?? null);
            unset($row['options_json']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function sanitizeCustomFields(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            $fieldKey = strtolower(trim((string) $key));
            if ($fieldKey === '' || !preg_match('/^[a-z][a-z0-9_]{1,59}$/', $fieldKey)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if (is_string($value)) {
                $out[$fieldKey] = trim($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $out[$fieldKey] = $value;
            } else {
                $out[$fieldKey] = (string) $value;
            }
            if (count($out) >= 200) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function mergeCustomFieldsToExtra(?string $extraJson, array $input, bool $replace = true): ?string
    {
        $extra = self::decodeJsonObject($extraJson);
        if ($replace) {
            if ($input === []) {
                unset($extra['custom_fields']);
            } else {
                $extra['custom_fields'] = $input;
            }
        } elseif ($input !== []) {
            $current = [];
            if (isset($extra['custom_fields']) && is_array($extra['custom_fields'])) {
                $current = $extra['custom_fields'];
            }
            $extra['custom_fields'] = array_merge($current, $input);
        }

        if ($extra === []) {
            return null;
        }
        $json = json_encode($extra, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }

    /**
     * @return array<string,mixed>
     */
    public static function customFieldsFromExtra(mixed $extraJson): array
    {
        if (is_array($extraJson)) {
            $extra = $extraJson;
        } else {
            $extra = self::decodeJsonObject($extraJson);
        }
        $raw = $extra['custom_fields'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        return self::sanitizeCustomFields($raw);
    }

    /**
     * @return array{match_mobile:bool,match_email:bool,match_company:bool,enabled:bool}
     */
    public static function dedupeRule(PDO $pdo, string $entityType): array
    {
        $entityType = strtolower(trim($entityType));
        $defaults = [
            'lead' => ['match_mobile' => true, 'match_email' => true, 'match_company' => true, 'enabled' => true],
            'contact' => ['match_mobile' => true, 'match_email' => true, 'match_company' => true, 'enabled' => true],
            'company' => ['match_mobile' => false, 'match_email' => false, 'match_company' => true, 'enabled' => true],
        ];
        if (!isset($defaults[$entityType])) {
            return ['match_mobile' => false, 'match_email' => false, 'match_company' => false, 'enabled' => false];
        }

        /** @var array<string,array{match_mobile:bool,match_email:bool,match_company:bool,enabled:bool}> $cache */
        static $cache = [];
        if (isset($cache[$entityType])) {
            return $cache[$entityType];
        }

        $stmt = $pdo->prepare(
            'SELECT match_mobile, match_email, match_company, enabled
             FROM qiling_crm_dedupe_rules
             WHERE entity_type = :entity_type
             LIMIT 1'
        );
        $stmt->execute(['entity_type' => $entityType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $cache[$entityType] = $defaults[$entityType];
            return $cache[$entityType];
        }

        $cache[$entityType] = [
            'match_mobile' => (int) ($row['match_mobile'] ?? 0) === 1,
            'match_email' => (int) ($row['match_email'] ?? 0) === 1,
            'match_company' => (int) ($row['match_company'] ?? 0) === 1,
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
        ];
        return $cache[$entityType];
    }

    private static function scopedColumn(string $alias, string $column): string
    {
        $a = trim($alias);
        if ($a === '') {
            return $column;
        }
        return $a . '.' . $column;
    }

    /**
     * @param array<int,int> $ids
     */
    private static function buildInPlaceholders(string $prefix, array $ids, array &$params): string
    {
        $parts = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $parts[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        return $parts !== [] ? implode(',', $parts) : 'NULL';
    }

    public static function queryStr(string $key): string
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return '';
        }
        return trim($_GET[$key]);
    }

    public static function queryInt(string $key): ?int
    {
        if (!isset($_GET[$key]) || !is_numeric($_GET[$key])) {
            return null;
        }
        return (int) $_GET[$key];
    }

    private static function isAllowedTable(string $table): bool
    {
        return in_array($table, self::ALLOWED_TABLES, true);
    }
}

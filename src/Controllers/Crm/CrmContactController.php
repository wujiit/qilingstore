<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmContactController
{
    public static function contacts(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.contacts.view');
        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $manageAll = CrmService::canManageAll($user);
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('status');
        $companyId = CrmSupport::queryInt('company_id');
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

        $sql = 'SELECT ct.*,
                       cp.company_name,
                       ou.username AS owner_username,
                       cu.username AS creator_username
                FROM qiling_crm_contacts ct
                LEFT JOIN qiling_crm_companies cp ON cp.id = ct.company_id
                LEFT JOIN qiling_users ou ON ou.id = ct.owner_user_id
                LEFT JOIN qiling_users cu ON cu.id = ct.created_by
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND ct.owner_user_id = :owner_user_id';
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
                'ct',
                true,
                $scope === 'mine'
            );
        }
        if (in_array($scope, ['team', 'department', 'public'], true)) {
            $sql .= ' AND ct.visibility_level = :scope_visibility_level';
            $params['scope_visibility_level'] = $scope;
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND ct.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND ct.status = :status';
            $params['status'] = $status;
        }
        if ($companyId !== null && $companyId > 0) {
            $sql .= ' AND ct.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_contacts', 'ft_qiling_crm_contacts_search')
            ) {
                $sql .= ' AND MATCH (ct.contact_name, ct.mobile, ct.email, ct.whatsapp) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (ct.contact_name LIKE :kw OR ct.mobile LIKE :kw OR ct.email LIKE :kw OR ct.whatsapp LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        if ($view === 'active') {
            $sql .= ' AND ct.deleted_at IS NULL AND ct.is_archived = 0';
        } elseif ($view === 'archived') {
            $sql .= ' AND ct.deleted_at IS NULL AND ct.is_archived = 1';
        } elseif ($view === 'recycle') {
            $sql .= ' AND ct.deleted_at IS NOT NULL';
        }
        if ($cursor > 0) {
            $sql .= ' AND ct.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY ct.id DESC LIMIT ' . $queryLimit;

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

    public static function createContact(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.contacts.edit');
        $data = Request::jsonBody();

        $contactName = Request::str($data, 'contact_name');
        if ($contactName === '') {
            Response::json(['message' => 'contact_name is required'], 422);
            return;
        }

        $companyId = Request::int($data, 'company_id', 0);
        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
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
        $mobile = Request::str($data, 'mobile');
        $email = strtolower(Request::str($data, 'email'));
        $whatsapp = Request::str($data, 'whatsapp');

        $duplicate = self::findDuplicateContactByIdentity($pdo, $mobile, $email, $whatsapp, null, $companyId > 0 ? $companyId : null);
        if (is_array($duplicate) && !CrmSupport::boolValue($data['allow_duplicate'] ?? false)) {
            Response::json([
                'message' => 'duplicate contact exists',
                'duplicate_contact_id' => (int) ($duplicate['id'] ?? 0),
            ], 409);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_contacts
             (company_id, contact_name, mobile, email, wechat, whatsapp, title, country_code, language_code, source_channel, owner_user_id, owner_team_id, owner_department_id, visibility_level, status, tags_json, extra_json, created_by, created_at, updated_at)
             VALUES
             (:company_id, :contact_name, :mobile, :email, :wechat, :whatsapp, :title, :country_code, :language_code, :source_channel, :owner_user_id, :owner_team_id, :owner_department_id, :visibility_level, :status, :tags_json, :extra_json, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'company_id' => $companyId > 0 ? $companyId : null,
            'contact_name' => $contactName,
            'mobile' => $mobile,
            'email' => $email,
            'wechat' => Request::str($data, 'wechat'),
            'whatsapp' => $whatsapp,
            'title' => Request::str($data, 'title'),
            'country_code' => strtoupper(Request::str($data, 'country_code')),
            'language_code' => strtolower(Request::str($data, 'language_code')),
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

        $contactId = (int) $pdo->lastInsertId();
        Audit::log((int) $user['id'], 'crm.contact.create', 'crm_contact', $contactId, 'Create crm contact', [
            'contact_name' => $contactName,
            'owner_user_id' => $ownerUserId,
        ]);

        Response::json(['contact_id' => $contactId], 201);
    }

    public static function updateContact(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.contacts.edit');
        $data = Request::jsonBody();
        $contactId = Request::int($data, 'contact_id', 0);
        if ($contactId <= 0) {
            Response::json(['message' => 'contact_id is required'], 422);
            return;
        }

        $row = CrmSupport::findWritableRecord($pdo, 'qiling_crm_contacts', $contactId, $user);
        if (!is_array($row)) {
            return;
        }
        if (!empty($row['deleted_at'])) {
            Response::json(['message' => 'contact is in recycle bin'], 422);
            return;
        }

        $companyId = Request::int($data, 'company_id', (int) ($row['company_id'] ?? 0));
        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
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
        $contactName = Request::str($data, 'contact_name', (string) ($row['contact_name'] ?? ''));
        if ($contactName === '') {
            Response::json(['message' => 'contact_name is required'], 422);
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
        $mobile = Request::str($data, 'mobile', (string) ($row['mobile'] ?? ''));
        $email = strtolower(Request::str($data, 'email', (string) ($row['email'] ?? '')));
        $whatsapp = Request::str($data, 'whatsapp', (string) ($row['whatsapp'] ?? ''));

        $duplicate = self::findDuplicateContactByIdentity($pdo, $mobile, $email, $whatsapp, $contactId, $companyId > 0 ? $companyId : null);
        if (is_array($duplicate) && !CrmSupport::boolValue($data['allow_duplicate'] ?? false)) {
            Response::json([
                'message' => 'duplicate contact exists',
                'duplicate_contact_id' => (int) ($duplicate['id'] ?? 0),
            ], 409);
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE qiling_crm_contacts
             SET company_id = :company_id,
                 contact_name = :contact_name,
                 mobile = :mobile,
                 email = :email,
                 wechat = :wechat,
                 whatsapp = :whatsapp,
                 title = :title,
                 country_code = :country_code,
                 language_code = :language_code,
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
            'company_id' => $companyId > 0 ? $companyId : null,
            'contact_name' => $contactName,
            'mobile' => $mobile,
            'email' => $email,
            'wechat' => Request::str($data, 'wechat', (string) ($row['wechat'] ?? '')),
            'whatsapp' => $whatsapp,
            'title' => Request::str($data, 'title', (string) ($row['title'] ?? '')),
            'country_code' => strtoupper(Request::str($data, 'country_code', (string) ($row['country_code'] ?? ''))),
            'language_code' => strtolower(Request::str($data, 'language_code', (string) ($row['language_code'] ?? ''))),
            'source_channel' => Request::str($data, 'source_channel', (string) ($row['source_channel'] ?? '')),
            'owner_user_id' => $ownerUserId,
            'owner_team_id' => $ownerOrg['owner_team_id'],
            'owner_department_id' => $ownerOrg['owner_department_id'],
            'visibility_level' => $visibilityLevel,
            'status' => CrmSupport::normalizeStatus(Request::str($data, 'status', (string) ($row['status'] ?? 'active')), ['active', 'inactive'], 'active'),
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'extra_json' => $extra,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $contactId,
        ]);

        Audit::log((int) $user['id'], 'crm.contact.update', 'crm_contact', $contactId, 'Update crm contact', [
            'owner_user_id' => $ownerUserId,
        ]);

        Response::json(['contact_id' => $contactId]);
    }

    public static function exportContacts(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.contacts.view');

        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $manageAll = CrmService::canManageAll($user);
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('status');
        $companyId = CrmSupport::queryInt('company_id');
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
        $headerLang = strtolower(CrmSupport::queryStr('header_lang'));
        if (!in_array($headerLang, ['zh', 'en'], true)) {
            $headerLang = 'en';
        }
        $limit = CrmSupport::queryInt('limit') ?? 1000;
        $limit = max(1, min($limit, 5000));

        $sql = 'SELECT ct.id, ct.contact_name, ct.mobile, ct.email, ct.whatsapp, ct.wechat, ct.title,
                       ct.company_id, cp.company_name, ct.country_code, ct.language_code, ct.source_channel,
                       ct.status, ct.owner_user_id, ou.username AS owner_username, ct.created_at, ct.updated_at,
                       ct.extra_json
                FROM qiling_crm_contacts ct
                LEFT JOIN qiling_crm_companies cp ON cp.id = ct.company_id
                LEFT JOIN qiling_users ou ON ou.id = ct.owner_user_id
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND ct.owner_user_id = :owner_user_id';
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
                'ct',
                true,
                $scope === 'mine'
            );
        }
        if (in_array($scope, ['team', 'department', 'public'], true)) {
            $sql .= ' AND ct.visibility_level = :scope_visibility_level';
            $params['scope_visibility_level'] = $scope;
        }
        if ($visibilityLevel !== '') {
            $sql .= ' AND ct.visibility_level = :visibility_level';
            $params['visibility_level'] = $visibilityLevel;
        }
        if ($status !== '') {
            $sql .= ' AND ct.status = :status';
            $params['status'] = $status;
        }
        if ($companyId !== null && $companyId > 0) {
            $sql .= ' AND ct.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_contacts', 'ft_qiling_crm_contacts_search')
            ) {
                $sql .= ' AND MATCH (ct.contact_name, ct.mobile, ct.email, ct.whatsapp) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (ct.contact_name LIKE :kw OR ct.mobile LIKE :kw OR ct.email LIKE :kw OR ct.whatsapp LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        if ($view === 'active') {
            $sql .= ' AND ct.deleted_at IS NULL AND ct.is_archived = 0';
        } elseif ($view === 'archived') {
            $sql .= ' AND ct.deleted_at IS NULL AND ct.is_archived = 1';
        } elseif ($view === 'recycle') {
            $sql .= ' AND ct.deleted_at IS NOT NULL';
        }
        $sql .= ' ORDER BY ct.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $filename = 'crm-contacts-' . gmdate('Ymd-His') . '.csv';
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

        $customDefs = CrmSupport::customFields($pdo, 'contact', true);
        $customHeaders = array_map(
            static fn (array $field): string => (string) ($field[$headerLang === 'zh' ? 'field_label' : 'field_key'] ?? ''),
            $customDefs
        );
        $customKeys = array_map(static fn (array $field): string => (string) ($field['field_key'] ?? ''), $customDefs);

        $headers = $headerLang === 'zh'
            ? [
                'ID',
                '联系人名称',
                '手机号',
                '邮箱',
                'WhatsApp',
                '微信',
                '职位',
                '企业ID',
                '企业名称',
                '国家代码',
                '语言代码',
                '来源渠道',
                '状态',
                '负责人ID',
                '负责人账号',
                '创建时间',
                '更新时间',
            ]
            : [
                'id',
                'contact_name',
                'mobile',
                'email',
                'whatsapp',
                'wechat',
                'title',
                'company_id',
                'company_name',
                'country_code',
                'language_code',
                'source_channel',
                'status',
                'owner_user_id',
                'owner_username',
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
                (string) ($row['contact_name'] ?? ''),
                (string) ($row['mobile'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['whatsapp'] ?? ''),
                (string) ($row['wechat'] ?? ''),
                (string) ($row['title'] ?? ''),
                (string) ((int) ($row['company_id'] ?? 0)),
                (string) ($row['company_name'] ?? ''),
                (string) ($row['country_code'] ?? ''),
                (string) ($row['language_code'] ?? ''),
                (string) ($row['source_channel'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ((int) ($row['owner_user_id'] ?? 0)),
                (string) ($row['owner_username'] ?? ''),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
            ];

            if ($customKeys !== []) {
                $customValues = CrmSupport::customFieldsFromExtra($row['extra_json'] ?? null);
                foreach ($customKeys as $fieldKey) {
                    $line[] = (string) ($customValues[$fieldKey] ?? '');
                }
            }

            $line = array_map(static fn ($cell): string => CrmSupport::csvSafeCell((string) $cell), $line);
            fputcsv($out, $line);
        }
        fclose($out);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findDuplicateContactByIdentity(
        PDO $pdo,
        string $mobile,
        string $email,
        string $whatsapp,
        ?int $excludeId,
        ?int $companyId = null
    ): ?array {
        $rule = CrmSupport::dedupeRule($pdo, 'contact');
        if (!$rule['enabled']) {
            return null;
        }

        $mobile = trim($mobile);
        $email = strtolower(trim($email));
        $whatsapp = trim($whatsapp);
        $companyId = $companyId !== null && $companyId > 0 ? $companyId : null;
        if (
            (!$rule['match_mobile'] || $mobile === '')
            && (!$rule['match_email'] || $email === '')
            && (!$rule['match_company'] || $companyId === null)
            && $whatsapp === ''
        ) {
            return null;
        }

        $sql = 'SELECT id, company_id, contact_name, mobile, email, wechat, whatsapp, title, country_code, language_code,
                       source_channel, status, owner_user_id, created_by
                FROM qiling_crm_contacts
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
        if ($rule['match_company'] && $companyId !== null) {
            $or[] = '(company_id = :company_id AND company_id > 0)';
            $params['company_id'] = $companyId;
        }
        if ($whatsapp !== '') {
            $or[] = '(whatsapp = :whatsapp AND whatsapp <> \'\')';
            $params['whatsapp'] = $whatsapp;
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

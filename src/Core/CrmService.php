<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use RuntimeException;
use Qiling\Support\Response;

final class CrmService
{
    private static bool $tableReady = false;

    public static function ensureTables(PDO $pdo): void
    {
        if (self::$tableReady) {
            return;
        }

        if (!self::schemaReady($pdo)) {
            throw new RuntimeException('CRM 数据库结构未升级，请先到系统升级页面执行升级。');
        }

        self::$tableReady = true;
    }

    private static function schemaReady(PDO $pdo): bool
    {
        try {
            $required = [
                'qiling_crm_pipelines',
                'qiling_crm_companies',
                'qiling_crm_contacts',
                'qiling_crm_leads',
                'qiling_crm_deals',
                'qiling_crm_activities',
                'qiling_crm_transfer_logs',
                'qiling_crm_assignment_rules',
                'qiling_crm_departments',
                'qiling_crm_teams',
                'qiling_crm_team_members',
                'qiling_crm_dedupe_rules',
                'qiling_crm_custom_fields',
                'qiling_crm_form_configs',
                'qiling_crm_reminder_rules',
                'qiling_crm_notifications',
                'qiling_customer_crm_links',
                'qiling_crm_products',
                'qiling_crm_quotes',
                'qiling_crm_quote_items',
                'qiling_crm_contracts',
                'qiling_crm_contract_items',
                'qiling_crm_payment_plans',
                'qiling_crm_invoices',
                'qiling_crm_automation_rules',
                'qiling_crm_automation_logs',
                'qiling_crm_deal_stage_logs',
            ];
            $stmt = $pdo->query('SHOW TABLES');
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $exists = [];
            foreach ($rows as $row) {
                $name = isset($row[0]) ? (string) $row[0] : '';
                if ($name !== '') {
                    $exists[$name] = true;
                }
            }

            foreach ($required as $table) {
                if (!isset($exists[$table])) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function requireCrmUser(array $user): void
    {
        $role = (string) ($user['role_key'] ?? '');
        if ($role !== '' && in_array($role, self::allowedRoles(), true)) {
            return;
        }

        Response::json(['message' => 'forbidden: crm access denied'], 403);
        exit;
    }

    public static function canManageAll(array $user): bool
    {
        $role = (string) ($user['role_key'] ?? '');
        if ($role === 'admin') {
            return true;
        }

        return Auth::hasPermission($user, 'crm.scope.all');
    }

    public static function requireCrmPermission(array $user, string $permission): void
    {
        if (self::canManageAll($user)) {
            return;
        }

        if (Auth::hasPermission($user, $permission)) {
            return;
        }

        Response::json(['message' => 'forbidden: crm permission denied'], 403);
        exit;
    }

    public static function resolveOwnerFilter(array $user, ?int $requestedOwnerId): ?int
    {
        if (self::canManageAll($user)) {
            return ($requestedOwnerId !== null && $requestedOwnerId > 0) ? $requestedOwnerId : null;
        }

        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            Response::json(['message' => 'unauthorized'], 401);
            exit;
        }
        if ($requestedOwnerId !== null && $requestedOwnerId > 0 && $requestedOwnerId !== $uid) {
            Response::json(['message' => 'forbidden: cross-owner query denied'], 403);
            exit;
        }
        return $uid;
    }

    public static function resolveOwnerInput(PDO $pdo, array $user, int $requestedOwnerId): int
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            Response::json(['message' => 'unauthorized'], 401);
            exit;
        }

        if (self::canManageAll($user)) {
            $ownerId = $requestedOwnerId > 0 ? $requestedOwnerId : $uid;
            self::assertActiveUser($pdo, $ownerId);
            return $ownerId;
        }

        return $uid;
    }

    public static function assertWritable(array $user, int $ownerUserId, int $createdBy): void
    {
        if (self::canManageAll($user)) {
            return;
        }

        $uid = (int) ($user['id'] ?? 0);
        if ($uid > 0 && ($uid === $ownerUserId || $uid === $createdBy)) {
            return;
        }

        Response::json(['message' => 'forbidden: crm record write denied'], 403);
        exit;
    }

    private static function assertActiveUser(PDO $pdo, int $userId): void
    {
        if ($userId <= 0) {
            Response::json(['message' => 'owner_user_id is invalid'], 422);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id FROM qiling_users WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([
            'id' => $userId,
            'status' => 'active',
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        Response::json(['message' => 'owner user not found'], 422);
        exit;
    }

    /**
     * @return array<int, string>
     */
    private static function allowedRoles(): array
    {
        $raw = (string) Config::get('CRM_ALLOWED_ROLE_KEYS', 'admin,manager,consultant,reception,therapist');
        $parts = preg_split('/[\s,，]+/', strtolower(trim($raw)));
        if (!is_array($parts)) {
            return ['admin', 'manager'];
        }

        $roles = [];
        foreach ($parts as $part) {
            $role = trim((string) $part);
            if ($role !== '') {
                $roles[] = $role;
            }
        }

        $roles = array_values(array_unique($roles));
        if ($roles === []) {
            return ['admin', 'manager'];
        }
        return $roles;
    }
}

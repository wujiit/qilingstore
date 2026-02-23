<?php

declare(strict_types=1);

namespace Qiling\Core;

use Qiling\Support\Response;

final class DataScope
{
    public static function isAdmin(array $user): bool
    {
        return (string) ($user['role_key'] ?? '') === 'admin';
    }

    public static function userStoreId(array $user): int
    {
        return (int) ($user['staff_store_id'] ?? 0);
    }

    public static function resolveFilterStoreId(array $user, ?int $requestedStoreId): ?int
    {
        if (self::isAdmin($user)) {
            return ($requestedStoreId !== null && $requestedStoreId > 0) ? $requestedStoreId : null;
        }

        $userStoreId = self::requireUserStoreId($user);
        if ($requestedStoreId !== null && $requestedStoreId > 0 && $requestedStoreId !== $userStoreId) {
            self::forbidden('cross-store query is forbidden');
        }

        return $userStoreId;
    }

    public static function resolveInputStoreId(array $user, int $inputStoreId, bool $allowZeroForAdmin = false): int
    {
        if (self::isAdmin($user)) {
            if ($inputStoreId > 0) {
                return $inputStoreId;
            }

            if ($allowZeroForAdmin) {
                return 0;
            }

            Response::json(['message' => 'store_id is required'], 422);
            exit;
        }

        $userStoreId = self::requireUserStoreId($user);
        if ($inputStoreId > 0 && $inputStoreId !== $userStoreId) {
            self::forbidden('cross-store operation is forbidden');
        }

        return $userStoreId;
    }

    public static function assertStoreAccess(array $user, int $storeId): void
    {
        if (self::isAdmin($user)) {
            return;
        }

        $userStoreId = self::requireUserStoreId($user);
        if ($storeId <= 0 || $storeId !== $userStoreId) {
            self::forbidden('cross-store operation is forbidden');
        }
    }

    public static function requireAdmin(array $user): void
    {
        if (self::isAdmin($user)) {
            return;
        }

        self::forbidden('admin only');
    }

    public static function requireManager(array $user): void
    {
        $roleKey = (string) ($user['role_key'] ?? '');
        if (in_array($roleKey, ['manager', 'admin'], true)) {
            return;
        }

        self::forbidden('forbidden: manager only');
    }

    public static function assertGlobalStoreAdminOnly(array $user, int $storeId): void
    {
        if ($storeId === 0) {
            self::requireAdmin($user);
            return;
        }

        self::assertStoreAccess($user, $storeId);
    }

    private static function requireUserStoreId(array $user): int
    {
        $storeId = self::userStoreId($user);
        if ($storeId > 0) {
            return $storeId;
        }

        self::forbidden('staff store is not configured');
        return 0;
    }

    private static function forbidden(string $message): void
    {
        Response::json(['message' => $message], 403);
        exit;
    }
}

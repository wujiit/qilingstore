<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Response;

final class DashboardController
{
    public static function summary(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $pdo = Database::pdo();
        if (DataScope::isAdmin($user)) {
            $stores = (int) $pdo->query('SELECT COUNT(*) FROM qiling_stores')->fetchColumn();
            $staff = (int) $pdo->query('SELECT COUNT(*) FROM qiling_staff')->fetchColumn();
            $customers = (int) $pdo->query('SELECT COUNT(*) FROM qiling_customers')->fetchColumn();
            $wpUsers = (int) $pdo->query('SELECT COUNT(*) FROM qiling_wp_users')->fetchColumn();
        } else {
            $storeId = DataScope::resolveFilterStoreId($user, null);
            $stores = 1;

            $staffStmt = $pdo->prepare('SELECT COUNT(*) FROM qiling_staff WHERE store_id = :store_id');
            $staffStmt->execute(['store_id' => $storeId]);
            $staff = (int) $staffStmt->fetchColumn();

            $customerStmt = $pdo->prepare('SELECT COUNT(*) FROM qiling_customers WHERE store_id = :store_id');
            $customerStmt->execute(['store_id' => $storeId]);
            $customers = (int) $customerStmt->fetchColumn();

            $wpStmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT u.wp_user_id)
                 FROM qiling_users u
                 INNER JOIN qiling_staff s ON s.user_id = u.id
                 WHERE s.store_id = :store_id
                   AND u.wp_user_id IS NOT NULL'
            );
            $wpStmt->execute(['store_id' => $storeId]);
            $wpUsers = (int) $wpStmt->fetchColumn();
        }

        Response::json([
            'summary' => [
                'stores' => $stores,
                'staff' => $staff,
                'customers' => $customers,
                'wp_users_synced' => $wpUsers,
            ],
        ]);
    }
}

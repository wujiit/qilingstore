<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CommissionController
{
    public static function rules(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $storeId);
        $enabled = isset($_GET['enabled']) && is_numeric($_GET['enabled']) ? (int) $_GET['enabled'] : null;

        $sql = 'SELECT *
                FROM qiling_commission_rules
                WHERE 1 = 1';
        $params = [];

        if (DataScope::isAdmin($user)) {
            if ($storeId !== null) {
                $sql .= ' AND store_id = :store_id';
                $params['store_id'] = $storeId;
            }
        } else {
            $sql .= ' AND store_id IN (0, :store_id)';
            $params['store_id'] = $storeId;
        }

        if ($enabled !== null) {
            $sql .= ' AND enabled = :enabled';
            $params['enabled'] = $enabled === 1 ? 1 : 0;
        }

        $sql .= ' ORDER BY store_id DESC, id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function upsertRule(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $id = Request::int($data, 'id', 0);
        $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), true);
        $ruleName = Request::str($data, 'rule_name');
        $targetType = Request::str($data, 'target_type', 'all');
        $targetRefId = Request::int($data, 'target_ref_id', 0);
        $staffRoleKey = Request::str($data, 'staff_role_key');
        $ratePercent = (float) ($data['rate_percent'] ?? 0);
        $enabled = Request::int($data, 'enabled', 1) === 1 ? 1 : 0;

        if ($ruleName === '') {
            Response::json(['message' => 'rule_name is required'], 422);
            return;
        }

        if (!in_array($targetType, ['all', 'service', 'package', 'custom'], true)) {
            Response::json(['message' => 'invalid target_type'], 422);
            return;
        }

        $ratePercent = round(max(0.0, min(100.0, $ratePercent)), 2);
        $now = gmdate('Y-m-d H:i:s');
        $pdo = Database::pdo();

        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT id, store_id FROM qiling_commission_rules WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rule)) {
                Response::json(['message' => 'commission rule not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($rule['store_id'] ?? 0));

            $update = $pdo->prepare(
                'UPDATE qiling_commission_rules
                 SET store_id = :store_id,
                     rule_name = :rule_name,
                     target_type = :target_type,
                     target_ref_id = :target_ref_id,
                     staff_role_key = :staff_role_key,
                     rate_percent = :rate_percent,
                     enabled = :enabled,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'store_id' => $storeId,
                'rule_name' => $ruleName,
                'target_type' => $targetType,
                'target_ref_id' => $targetRefId > 0 ? $targetRefId : null,
                'staff_role_key' => $staffRoleKey,
                'rate_percent' => $ratePercent,
                'enabled' => $enabled,
                'updated_at' => $now,
                'id' => $id,
            ]);

            Audit::log((int) $user['id'], 'commission.rule.update', 'commission_rule', $id, 'Update commission rule', [
                'store_id' => $storeId,
                'target_type' => $targetType,
                'rate_percent' => $ratePercent,
                'enabled' => $enabled,
            ]);

            Response::json(['id' => $id, 'updated' => true]);
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO qiling_commission_rules
             (store_id, rule_name, target_type, target_ref_id, staff_role_key, rate_percent, enabled, created_at, updated_at)
             VALUES
             (:store_id, :rule_name, :target_type, :target_ref_id, :staff_role_key, :rate_percent, :enabled, :created_at, :updated_at)'
        );
        $insert->execute([
            'store_id' => $storeId,
            'rule_name' => $ruleName,
            'target_type' => $targetType,
            'target_ref_id' => $targetRefId > 0 ? $targetRefId : null,
            'staff_role_key' => $staffRoleKey,
            'rate_percent' => $ratePercent,
            'enabled' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ruleId = (int) $pdo->lastInsertId();

        Audit::log((int) $user['id'], 'commission.rule.create', 'commission_rule', $ruleId, 'Create commission rule', [
            'store_id' => $storeId,
            'target_type' => $targetType,
            'rate_percent' => $ratePercent,
            'enabled' => $enabled,
        ]);

        Response::json(['id' => $ruleId, 'updated' => false], 201);
    }

    public static function staffPerformance(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $storeId);
        $dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';

        if ($dateFrom === '') {
            $dateFrom = gmdate('Y-m-01');
        }
        if ($dateTo === '') {
            $dateTo = gmdate('Y-m-d');
        }

        $fromAt = $dateFrom . ' 00:00:00';
        $toAt = $dateTo . ' 23:59:59';

        $sql = 'SELECT oi.staff_id,
                       st.staff_no,
                       st.role_key,
                       u.username AS staff_username,
                       u.email AS staff_email,
                       COUNT(oi.id) AS item_count,
                       COUNT(DISTINCT o.id) AS order_count,
                       SUM(oi.final_amount) AS sales_amount,
                       SUM(oi.commission_amount) AS commission_amount
                FROM qiling_order_items oi
                INNER JOIN qiling_orders o ON o.id = oi.order_id
                INNER JOIN qiling_staff st ON st.id = oi.staff_id
                INNER JOIN qiling_users u ON u.id = st.user_id
                WHERE oi.staff_id IS NOT NULL
                  AND o.status = :order_status_paid
                  AND o.paid_at >= :from_at
                  AND o.paid_at <= :to_at';
        $params = [
            'order_status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];

        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' GROUP BY oi.staff_id, st.staff_no, st.role_key, u.username, u.email
                  ORDER BY commission_amount DESC, sales_amount DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'staff_count' => count($rows),
            'sales_amount' => 0.0,
            'commission_amount' => 0.0,
            'item_count' => 0,
            'order_count' => 0,
        ];

        foreach ($rows as $row) {
            $summary['sales_amount'] += (float) ($row['sales_amount'] ?? 0);
            $summary['commission_amount'] += (float) ($row['commission_amount'] ?? 0);
            $summary['item_count'] += (int) ($row['item_count'] ?? 0);
        }

        $orderCountSql = 'SELECT COUNT(*) FROM qiling_orders o
                          WHERE o.status = :order_status_paid
                            AND o.paid_at >= :from_at
                            AND o.paid_at <= :to_at';
        $orderCountParams = [
            'order_status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $orderCountSql .= ' AND o.store_id = :store_id';
            $orderCountParams['store_id'] = $storeId;
        }

        $orderCountStmt = Database::pdo()->prepare($orderCountSql);
        $orderCountStmt->execute($orderCountParams);
        $summary['order_count'] = (int) $orderCountStmt->fetchColumn();

        $summary['sales_amount'] = round($summary['sales_amount'], 2);
        $summary['commission_amount'] = round($summary['commission_amount'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }
}

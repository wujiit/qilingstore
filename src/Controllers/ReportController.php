<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Response;

final class ReportController
{
    public static function operationOverview(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        $paymentSql = 'SELECT
                SUM(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
                COUNT(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN 1 ELSE NULL END) AS paid_txn_count,
                COUNT(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN 1 ELSE NULL END) AS refund_txn_count,
                COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
                COUNT(DISTINCT CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN p.order_id ELSE NULL END) AS refund_orders
            FROM qiling_order_payments p
            INNER JOIN qiling_orders o ON o.id = p.order_id
            WHERE p.paid_at >= :from_at
              AND p.paid_at <= :to_at
              AND p.status IN (\'paid\', \'refunded\')';
        $paymentParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $paymentSql .= ' AND o.store_id = :store_id';
            $paymentParams['store_id'] = $storeId;
        }

        $paymentStmt = $pdo->prepare($paymentSql);
        $paymentStmt->execute($paymentParams);
        $paymentSummary = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($paymentSummary)) {
            $paymentSummary = [];
        }

        $activeCustomersSql = 'SELECT COUNT(DISTINCT o.customer_id)
            FROM qiling_order_payments p
            INNER JOIN qiling_orders o ON o.id = p.order_id
            WHERE p.status = \'paid\'
              AND p.amount > 0
              AND p.paid_at >= :from_at
              AND p.paid_at <= :to_at';
        $activeCustomersParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $activeCustomersSql .= ' AND o.store_id = :store_id';
            $activeCustomersParams['store_id'] = $storeId;
        }
        $activeStmt = $pdo->prepare($activeCustomersSql);
        $activeStmt->execute($activeCustomersParams);
        $activeCustomers = (int) $activeStmt->fetchColumn();

        $newCustomersSql = 'SELECT COUNT(*) FROM qiling_customers WHERE created_at >= :from_at AND created_at <= :to_at';
        $newCustomersParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $newCustomersSql .= ' AND store_id = :store_id';
            $newCustomersParams['store_id'] = $storeId;
        }
        $newCustomersStmt = $pdo->prepare($newCustomersSql);
        $newCustomersStmt->execute($newCustomersParams);
        $newCustomers = (int) $newCustomersStmt->fetchColumn();

        $repurchaseSql = 'SELECT COUNT(*)
            FROM (
                SELECT o.customer_id
                FROM qiling_order_payments p
                INNER JOIN qiling_orders o ON o.id = p.order_id
                WHERE p.status = \'paid\'
                  AND p.amount > 0
                  AND p.paid_at >= :from_at
                  AND p.paid_at <= :to_at';
        $repurchaseParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $repurchaseSql .= ' AND o.store_id = :store_id';
            $repurchaseParams['store_id'] = $storeId;
        }
        $repurchaseSql .= ' GROUP BY o.customer_id
                HAVING COUNT(DISTINCT o.id) >= 2
            ) repurchase_customers';

        $repurchaseStmt = $pdo->prepare($repurchaseSql);
        $repurchaseStmt->execute($repurchaseParams);
        $repurchaseCustomers = (int) $repurchaseStmt->fetchColumn();

        $appointmentSql = 'SELECT
                COUNT(*) AS appointments_total,
                SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS appointments_completed,
                SUM(CASE WHEN status = \'cancelled\' THEN 1 ELSE 0 END) AS appointments_cancelled,
                SUM(CASE WHEN status = \'no_show\' THEN 1 ELSE 0 END) AS appointments_no_show
            FROM qiling_appointments
            WHERE start_at >= :from_at
              AND start_at <= :to_at';
        $appointmentParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $appointmentSql .= ' AND store_id = :store_id';
            $appointmentParams['store_id'] = $storeId;
        }
        $appointmentStmt = $pdo->prepare($appointmentSql);
        $appointmentStmt->execute($appointmentParams);
        $appointmentSummary = $appointmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($appointmentSummary)) {
            $appointmentSummary = [];
        }

        $cardConsumeSql = 'SELECT COALESCE(SUM(CASE WHEN l.delta_sessions < 0 THEN ABS(l.delta_sessions) ELSE 0 END), 0)
            FROM qiling_member_card_logs l
            INNER JOIN qiling_member_cards mc ON mc.id = l.member_card_id
            WHERE l.created_at >= :from_at
              AND l.created_at <= :to_at';
        $cardConsumeParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $cardConsumeSql .= ' AND mc.store_id = :store_id';
            $cardConsumeParams['store_id'] = $storeId;
        }
        $cardConsumeStmt = $pdo->prepare($cardConsumeSql);
        $cardConsumeStmt->execute($cardConsumeParams);
        $cardConsumedSessions = (int) $cardConsumeStmt->fetchColumn();

        $paidAmount = round((float) ($paymentSummary['paid_amount'] ?? 0), 2);
        $refundAmount = round((float) ($paymentSummary['refund_amount'] ?? 0), 2);
        $netAmount = round($paidAmount - $refundAmount, 2);
        $paidOrders = (int) ($paymentSummary['paid_orders'] ?? 0);
        $avgOrderAmount = $paidOrders > 0 ? round($paidAmount / $paidOrders, 2) : 0.00;
        $repurchaseRate = $activeCustomers > 0 ? round($repurchaseCustomers * 100 / $activeCustomers, 2) : 0.00;

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'paid_amount' => $paidAmount,
                'refund_amount' => $refundAmount,
                'net_amount' => $netAmount,
                'paid_orders' => $paidOrders,
                'refund_orders' => (int) ($paymentSummary['refund_orders'] ?? 0),
                'paid_txn_count' => (int) ($paymentSummary['paid_txn_count'] ?? 0),
                'refund_txn_count' => (int) ($paymentSummary['refund_txn_count'] ?? 0),
                'avg_order_amount' => $avgOrderAmount,
                'active_customers' => $activeCustomers,
                'new_customers' => $newCustomers,
                'repurchase_customers' => $repurchaseCustomers,
                'repurchase_rate' => $repurchaseRate,
                'appointments_total' => (int) ($appointmentSummary['appointments_total'] ?? 0),
                'appointments_completed' => (int) ($appointmentSummary['appointments_completed'] ?? 0),
                'appointments_cancelled' => (int) ($appointmentSummary['appointments_cancelled'] ?? 0),
                'appointments_no_show' => (int) ($appointmentSummary['appointments_no_show'] ?? 0),
                'card_consumed_sessions' => $cardConsumedSessions,
            ],
        ]);
    }

    public static function revenueTrend(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        $trendSql = 'SELECT
                DATE(p.paid_at) AS report_date,
                SUM(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
                COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
                COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN o.customer_id ELSE NULL END) AS paid_customers
            FROM qiling_order_payments p
            INNER JOIN qiling_orders o ON o.id = p.order_id
            WHERE p.paid_at >= :from_at
              AND p.paid_at <= :to_at
              AND p.status IN (\'paid\', \'refunded\')';
        $trendParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $trendSql .= ' AND o.store_id = :store_id';
            $trendParams['store_id'] = $storeId;
        }
        $trendSql .= ' GROUP BY DATE(p.paid_at)
            ORDER BY report_date ASC';

        $trendStmt = $pdo->prepare($trendSql);
        $trendStmt->execute($trendParams);
        $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

        $newCustomerSql = 'SELECT DATE(created_at) AS report_date, COUNT(*) AS new_customers
            FROM qiling_customers
            WHERE created_at >= :from_at
              AND created_at <= :to_at';
        $newCustomerParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $newCustomerSql .= ' AND store_id = :store_id';
            $newCustomerParams['store_id'] = $storeId;
        }
        $newCustomerSql .= ' GROUP BY DATE(created_at)';

        $newCustomerStmt = $pdo->prepare($newCustomerSql);
        $newCustomerStmt->execute($newCustomerParams);
        $newCustomerRows = $newCustomerStmt->fetchAll(PDO::FETCH_ASSOC);

        $trendMap = [];
        foreach ($trendRows as $row) {
            $dateKey = (string) ($row['report_date'] ?? '');
            if ($dateKey === '') {
                continue;
            }
            $trendMap[$dateKey] = [
                'paid_amount' => round((float) ($row['paid_amount'] ?? 0), 2),
                'refund_amount' => round((float) ($row['refund_amount'] ?? 0), 2),
                'paid_orders' => (int) ($row['paid_orders'] ?? 0),
                'paid_customers' => (int) ($row['paid_customers'] ?? 0),
            ];
        }

        $newCustomerMap = [];
        foreach ($newCustomerRows as $row) {
            $dateKey = (string) ($row['report_date'] ?? '');
            if ($dateKey === '') {
                continue;
            }
            $newCustomerMap[$dateKey] = (int) ($row['new_customers'] ?? 0);
        }

        $data = [];
        $summary = [
            'days' => 0,
            'paid_amount' => 0.0,
            'refund_amount' => 0.0,
            'net_amount' => 0.0,
            'paid_orders' => 0,
            'paid_customers' => 0,
            'new_customers' => 0,
        ];

        $cursor = new \DateTimeImmutable($dateFrom);
        $end = new \DateTimeImmutable($dateTo);

        while ($cursor->getTimestamp() <= $end->getTimestamp()) {
            $dateKey = $cursor->format('Y-m-d');
            $row = $trendMap[$dateKey] ?? [
                'paid_amount' => 0.0,
                'refund_amount' => 0.0,
                'paid_orders' => 0,
                'paid_customers' => 0,
            ];

            $paidAmount = (float) $row['paid_amount'];
            $refundAmount = (float) $row['refund_amount'];
            $netAmount = round($paidAmount - $refundAmount, 2);
            $newCustomers = (int) ($newCustomerMap[$dateKey] ?? 0);

            $data[] = [
                'report_date' => $dateKey,
                'paid_amount' => $paidAmount,
                'refund_amount' => $refundAmount,
                'net_amount' => $netAmount,
                'paid_orders' => (int) $row['paid_orders'],
                'paid_customers' => (int) $row['paid_customers'],
                'new_customers' => $newCustomers,
            ];

            $summary['days']++;
            $summary['paid_amount'] += $paidAmount;
            $summary['refund_amount'] += $refundAmount;
            $summary['paid_orders'] += (int) $row['paid_orders'];
            $summary['paid_customers'] += (int) $row['paid_customers'];
            $summary['new_customers'] += $newCustomers;

            $cursor = $cursor->modify('+1 day');
        }

        $summary['paid_amount'] = round($summary['paid_amount'], 2);
        $summary['refund_amount'] = round($summary['refund_amount'], 2);
        $summary['net_amount'] = round($summary['paid_amount'] - $summary['refund_amount'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'data' => $data,
        ]);
    }

    public static function channelStats(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        $channelNewSql = 'SELECT
                COALESCE(NULLIF(TRIM(source_channel), \'\'), \'未标记\') AS source_channel,
                COUNT(*) AS new_customers
            FROM qiling_customers
            WHERE created_at >= :from_at
              AND created_at <= :to_at';
        $channelNewParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $channelNewSql .= ' AND store_id = :store_id';
            $channelNewParams['store_id'] = $storeId;
        }
        $channelNewSql .= ' GROUP BY COALESCE(NULLIF(TRIM(source_channel), \'\'), \'未标记\')';

        $channelNewStmt = $pdo->prepare($channelNewSql);
        $channelNewStmt->execute($channelNewParams);
        $channelNewRows = $channelNewStmt->fetchAll(PDO::FETCH_ASSOC);

        $channelSalesSql = 'SELECT
                COALESCE(NULLIF(TRIM(c.source_channel), \'\'), \'未标记\') AS source_channel,
                COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
                COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN o.customer_id ELSE NULL END) AS paid_customers,
                SUM(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount
            FROM qiling_order_payments p
            INNER JOIN qiling_orders o ON o.id = p.order_id
            INNER JOIN qiling_customers c ON c.id = o.customer_id
            WHERE p.paid_at >= :from_at
              AND p.paid_at <= :to_at
              AND p.status IN (\'paid\', \'refunded\')';
        $channelSalesParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $channelSalesSql .= ' AND o.store_id = :store_id';
            $channelSalesParams['store_id'] = $storeId;
        }
        $channelSalesSql .= ' GROUP BY COALESCE(NULLIF(TRIM(c.source_channel), \'\'), \'未标记\')';

        $channelSalesStmt = $pdo->prepare($channelSalesSql);
        $channelSalesStmt->execute($channelSalesParams);
        $channelSalesRows = $channelSalesStmt->fetchAll(PDO::FETCH_ASSOC);

        $merged = [];

        foreach ($channelNewRows as $row) {
            $channel = (string) ($row['source_channel'] ?? self::UNKNOWN_CHANNEL);
            $merged[$channel] = [
                'source_channel' => $channel,
                'new_customers' => (int) ($row['new_customers'] ?? 0),
                'paid_customers' => 0,
                'paid_orders' => 0,
                'paid_amount' => 0.0,
                'refund_amount' => 0.0,
                'net_amount' => 0.0,
                'avg_order_amount' => 0.0,
                'conversion_rate' => 0.0,
            ];
        }

        foreach ($channelSalesRows as $row) {
            $channel = (string) ($row['source_channel'] ?? self::UNKNOWN_CHANNEL);
            if (!isset($merged[$channel])) {
                $merged[$channel] = [
                    'source_channel' => $channel,
                    'new_customers' => 0,
                    'paid_customers' => 0,
                    'paid_orders' => 0,
                    'paid_amount' => 0.0,
                    'refund_amount' => 0.0,
                    'net_amount' => 0.0,
                    'avg_order_amount' => 0.0,
                    'conversion_rate' => 0.0,
                ];
            }

            $paidAmount = round((float) ($row['paid_amount'] ?? 0), 2);
            $refundAmount = round((float) ($row['refund_amount'] ?? 0), 2);
            $paidOrders = (int) ($row['paid_orders'] ?? 0);
            $paidCustomers = (int) ($row['paid_customers'] ?? 0);

            $merged[$channel]['paid_orders'] = $paidOrders;
            $merged[$channel]['paid_customers'] = $paidCustomers;
            $merged[$channel]['paid_amount'] = $paidAmount;
            $merged[$channel]['refund_amount'] = $refundAmount;
            $merged[$channel]['net_amount'] = round($paidAmount - $refundAmount, 2);
            $merged[$channel]['avg_order_amount'] = $paidOrders > 0 ? round($paidAmount / $paidOrders, 2) : 0.0;
        }

        $summary = [
            'channels' => 0,
            'new_customers' => 0,
            'paid_customers' => 0,
            'paid_orders' => 0,
            'paid_amount' => 0.0,
            'refund_amount' => 0.0,
            'net_amount' => 0.0,
        ];

        $rows = array_values($merged);
        foreach ($rows as &$row) {
            $newCustomers = (int) ($row['new_customers'] ?? 0);
            $paidCustomers = (int) ($row['paid_customers'] ?? 0);
            $row['conversion_rate'] = $newCustomers > 0 ? round($paidCustomers * 100 / $newCustomers, 2) : 0.0;

            $summary['new_customers'] += $newCustomers;
            $summary['paid_customers'] += $paidCustomers;
            $summary['paid_orders'] += (int) ($row['paid_orders'] ?? 0);
            $summary['paid_amount'] += (float) ($row['paid_amount'] ?? 0);
            $summary['refund_amount'] += (float) ($row['refund_amount'] ?? 0);
        }

        usort($rows, static function (array $a, array $b): int {
            $aNet = (float) ($a['net_amount'] ?? 0);
            $bNet = (float) ($b['net_amount'] ?? 0);
            if ($aNet === $bNet) {
                return strcmp((string) ($a['source_channel'] ?? ''), (string) ($b['source_channel'] ?? ''));
            }
            return $aNet > $bNet ? -1 : 1;
        });

        $summary['channels'] = count($rows);
        $summary['paid_amount'] = round($summary['paid_amount'], 2);
        $summary['refund_amount'] = round($summary['refund_amount'], 2);
        $summary['net_amount'] = round($summary['paid_amount'] - $summary['refund_amount'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }

    public static function serviceTop(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);

        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $limit = max(1, min(100, $limit));

        $sql = 'SELECT
                oi.item_type,
                COALESCE(oi.item_ref_id, 0) AS item_ref_id,
                oi.item_name,
                SUM(oi.qty) AS total_qty,
                COUNT(oi.id) AS item_lines,
                COUNT(DISTINCT oi.order_id) AS order_count,
                SUM(oi.final_amount) AS sales_amount,
                SUM(oi.commission_amount) AS commission_amount
            FROM qiling_order_items oi
            INNER JOIN (
                SELECT DISTINCT p.order_id
                FROM qiling_order_payments p
                INNER JOIN qiling_orders oo ON oo.id = p.order_id
                WHERE p.status = \'paid\'
                  AND p.amount > 0
                  AND p.paid_at >= :from_at
                  AND p.paid_at <= :to_at';
        $params = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND oo.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' ) paid_orders ON paid_orders.order_id = oi.order_id
            GROUP BY oi.item_type, COALESCE(oi.item_ref_id, 0), oi.item_name
            ORDER BY sales_amount DESC, total_qty DESC
            LIMIT ' . $limit;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'items' => count($rows),
            'sales_amount' => 0.0,
            'commission_amount' => 0.0,
            'order_count' => 0,
            'total_qty' => 0,
        ];

        foreach ($rows as &$row) {
            $row['sales_amount'] = round((float) ($row['sales_amount'] ?? 0), 2);
            $row['commission_amount'] = round((float) ($row['commission_amount'] ?? 0), 2);
            $row['total_qty'] = (int) ($row['total_qty'] ?? 0);
            $row['item_lines'] = (int) ($row['item_lines'] ?? 0);
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['avg_order_amount'] = (int) $row['order_count'] > 0
                ? round((float) $row['sales_amount'] / (int) $row['order_count'], 2)
                : 0.00;

            $summary['sales_amount'] += (float) $row['sales_amount'];
            $summary['commission_amount'] += (float) $row['commission_amount'];
            $summary['order_count'] += (int) $row['order_count'];
            $summary['total_qty'] += (int) $row['total_qty'];
        }

        $summary['sales_amount'] = round($summary['sales_amount'], 2);
        $summary['commission_amount'] = round($summary['commission_amount'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $limit,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }

    public static function paymentMethods(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);

        $sql = 'SELECT
                p.pay_method,
                COUNT(*) AS txn_count,
                COUNT(DISTINCT p.order_id) AS order_count,
                SUM(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount
            FROM qiling_order_payments p
            INNER JOIN qiling_orders o ON o.id = p.order_id
            WHERE p.paid_at >= :from_at
              AND p.paid_at <= :to_at
              AND p.status IN (\'paid\', \'refunded\')';
        $params = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY p.pay_method';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'methods' => count($rows),
            'paid_amount' => 0.0,
            'refund_amount' => 0.0,
            'net_amount' => 0.0,
            'txn_count' => 0,
            'order_count' => 0,
        ];

        foreach ($rows as &$row) {
            $paidAmount = round((float) ($row['paid_amount'] ?? 0), 2);
            $refundAmount = round((float) ($row['refund_amount'] ?? 0), 2);
            $row['txn_count'] = (int) ($row['txn_count'] ?? 0);
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['paid_amount'] = $paidAmount;
            $row['refund_amount'] = $refundAmount;
            $row['net_amount'] = round($paidAmount - $refundAmount, 2);

            $summary['paid_amount'] += $paidAmount;
            $summary['refund_amount'] += $refundAmount;
            $summary['txn_count'] += $row['txn_count'];
            $summary['order_count'] += $row['order_count'];
        }

        $summary['paid_amount'] = round($summary['paid_amount'], 2);
        $summary['refund_amount'] = round($summary['refund_amount'], 2);
        $summary['net_amount'] = round($summary['paid_amount'] - $summary['refund_amount'], 2);

        foreach ($rows as &$row) {
            $row['amount_share'] = $summary['paid_amount'] > 0
                ? round(((float) ($row['paid_amount'] ?? 0) * 100) / $summary['paid_amount'], 2)
                : 0.00;
        }

        usort($rows, static function (array $a, array $b): int {
            $aNet = (float) ($a['net_amount'] ?? 0);
            $bNet = (float) ($b['net_amount'] ?? 0);
            if ($aNet === $bNet) {
                return strcmp((string) ($a['pay_method'] ?? ''), (string) ($b['pay_method'] ?? ''));
            }
            return $aNet > $bNet ? -1 : 1;
        });

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }

    public static function storeDaily(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(6);

        $sql = 'SELECT o.store_id,
                       DATE(o.paid_at) AS report_date,
                       s.store_name,
                       COUNT(o.id) AS paid_orders,
                       SUM(o.payable_amount) AS paid_amount,
                       COUNT(DISTINCT o.customer_id) AS paid_customers
                FROM qiling_orders o
                LEFT JOIN qiling_stores s ON s.id = o.store_id
                WHERE o.status = :status_paid
                  AND o.paid_at >= :from_at
                  AND o.paid_at <= :to_at';
        $params = [
            'status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];

        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' GROUP BY o.store_id, DATE(o.paid_at), s.store_name
                  ORDER BY report_date DESC, o.store_id ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $newCustomersMap = self::newCustomersMap(Database::pdo(), $fromAt, $toAt, $storeId);

        $summary = [
            'days' => count($rows),
            'paid_orders' => 0,
            'paid_amount' => 0.0,
            'paid_customers' => 0,
            'new_customers' => 0,
        ];

        foreach ($rows as &$row) {
            $key = (string) ($row['store_id'] ?? 0) . '#' . (string) ($row['report_date'] ?? '');
            $row['new_customers'] = (int) ($newCustomersMap[$key] ?? 0);
            $row['paid_amount'] = round((float) ($row['paid_amount'] ?? 0), 2);
            $row['avg_order_amount'] = (int) ($row['paid_orders'] ?? 0) > 0
                ? round((float) $row['paid_amount'] / (int) $row['paid_orders'], 2)
                : 0.00;

            $summary['paid_orders'] += (int) ($row['paid_orders'] ?? 0);
            $summary['paid_amount'] += (float) ($row['paid_amount'] ?? 0);
            $summary['new_customers'] += (int) ($row['new_customers'] ?? 0);
        }

        $summary['paid_customers'] = self::uniquePaidCustomers(Database::pdo(), $fromAt, $toAt, $storeId);
        $summary['paid_amount'] = round($summary['paid_amount'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }

    public static function customerRepurchase(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = self::resolveStoreId($user);
        $minOrders = isset($_GET['min_orders']) && is_numeric($_GET['min_orders']) ? (int) $_GET['min_orders'] : 2;
        $minOrders = max(1, $minOrders);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(365);

        $sql = 'SELECT c.id AS customer_id,
                       c.customer_no,
                       c.name AS customer_name,
                       c.mobile AS customer_mobile,
                       c.store_id,
                       s.store_name,
                       COUNT(o.id) AS paid_orders,
                       SUM(o.payable_amount) AS total_spent,
                       MIN(o.paid_at) AS first_paid_at,
                       MAX(o.paid_at) AS last_paid_at
                FROM qiling_orders o
                INNER JOIN qiling_customers c ON c.id = o.customer_id
                LEFT JOIN qiling_stores s ON s.id = c.store_id
                WHERE o.status = :status_paid
                  AND o.paid_at >= :from_at
                  AND o.paid_at <= :to_at';
        $params = [
            'status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];

        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' GROUP BY c.id, c.customer_no, c.name, c.mobile, c.store_id, s.store_name
                  HAVING COUNT(o.id) >= :min_orders
                  ORDER BY paid_orders DESC, total_spent DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge($params, ['min_orders' => $minOrders]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'customers' => count($rows),
            'total_spent' => 0.0,
            'total_paid_orders' => 0,
        ];

        foreach ($rows as &$row) {
            $row['total_spent'] = round((float) ($row['total_spent'] ?? 0), 2);
            $summary['total_spent'] += (float) $row['total_spent'];
            $summary['total_paid_orders'] += (int) ($row['paid_orders'] ?? 0);
        }

        $summary['total_spent'] = round($summary['total_spent'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'min_orders' => $minOrders,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private static function newCustomersMap(PDO $pdo, string $fromAt, string $toAt, ?int $storeId): array
    {
        $sql = 'SELECT store_id, DATE(created_at) AS created_date, COUNT(id) AS total
                FROM qiling_customers
                WHERE created_at >= :from_at
                  AND created_at <= :to_at';
        $params = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];

        if ($storeId !== null) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' GROUP BY store_id, DATE(created_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $key = (string) ($row['store_id'] ?? 0) . '#' . (string) ($row['created_date'] ?? '');
            $map[$key] = (int) ($row['total'] ?? 0);
        }

        return $map;
    }

    private static function uniquePaidCustomers(PDO $pdo, string $fromAt, string $toAt, ?int $storeId): int
    {
        $sql = 'SELECT COUNT(DISTINCT o.customer_id)
                FROM qiling_orders o
                WHERE o.status = :status_paid
                  AND o.paid_at >= :from_at
                  AND o.paid_at <= :to_at';
        $params = [
            'status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];

        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private static function resolveStoreId(array $user): ?int
    {
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        return DataScope::resolveFilterStoreId($user, $storeId);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function resolveDateRange(int $defaultDays = 29, int $maxRangeDays = 366): array
    {
        $dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';

        $defaultDays = max(1, $defaultDays);
        $maxRangeDays = max($defaultDays, $maxRangeDays);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = gmdate('Y-m-d', strtotime('-' . $defaultDays . ' days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = gmdate('Y-m-d');
        }

        $fromTs = strtotime($dateFrom);
        $toTs = strtotime($dateTo);
        if ($fromTs === false || $toTs === false) {
            $dateTo = gmdate('Y-m-d');
            $dateFrom = gmdate('Y-m-d', strtotime('-' . $defaultDays . ' days'));
            $fromTs = strtotime($dateFrom);
            $toTs = strtotime($dateTo);
        }

        if ($fromTs > $toTs) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }

        $maxSpanSeconds = $maxRangeDays * 86400;
        if (($toTs - $fromTs) > $maxSpanSeconds) {
            $fromTs = $toTs - $maxSpanSeconds;
            $dateFrom = gmdate('Y-m-d', $fromTs);
        }

        return [
            $dateFrom,
            $dateTo,
            $dateFrom . ' 00:00:00',
            $dateTo . ' 23:59:59',
        ];
    }

    private const UNKNOWN_CHANNEL = '未标记';
}

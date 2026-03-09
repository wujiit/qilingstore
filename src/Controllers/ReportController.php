<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\ReportAggregateService;
use Qiling\Support\Response;

final class ReportController
{
    public static function operationOverview(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        self::ensureAggregates($pdo, $dateFrom, $dateTo, $storeId);

        $storeSummarySql = 'SELECT
                COALESCE(SUM(paid_amount), 0) AS paid_amount,
                COALESCE(SUM(refund_amount), 0) AS refund_amount,
                COALESCE(SUM(paid_txn_count), 0) AS paid_txn_count,
                COALESCE(SUM(refund_txn_count), 0) AS refund_txn_count,
                COALESCE(SUM(paid_orders), 0) AS paid_orders,
                COALESCE(SUM(refund_orders), 0) AS refund_orders,
                COALESCE(SUM(new_customers), 0) AS new_customers,
                COALESCE(SUM(appointments_total), 0) AS appointments_total,
                COALESCE(SUM(appointments_completed), 0) AS appointments_completed,
                COALESCE(SUM(appointments_cancelled), 0) AS appointments_cancelled,
                COALESCE(SUM(appointments_no_show), 0) AS appointments_no_show,
                COALESCE(SUM(card_consumed_sessions), 0) AS card_consumed_sessions
            FROM qiling_report_daily_store
            WHERE report_date >= :date_from
              AND report_date <= :date_to';
        $storeSummaryParams = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
        if ($storeId !== null) {
            $storeSummarySql .= ' AND store_id = :store_id';
            $storeSummaryParams['store_id'] = $storeId;
        }

        $storeSummaryStmt = $pdo->prepare($storeSummarySql);
        $storeSummaryStmt->execute($storeSummaryParams);
        $storeSummary = $storeSummaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($storeSummary)) {
            $storeSummary = [];
        }

        $customerStatsSql = 'SELECT
                COUNT(*) AS active_customers,
                SUM(CASE WHEN order_count >= 2 THEN 1 ELSE 0 END) AS repurchase_customers
            FROM (
                SELECT o.customer_id, COUNT(o.id) AS order_count
                FROM qiling_orders o
                WHERE o.status = :status_paid
                  AND o.paid_at >= :from_at
                  AND o.paid_at <= :to_at';
        $customerStatsParams = [
            'status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $customerStatsSql .= ' AND o.store_id = :store_id';
            $customerStatsParams['store_id'] = $storeId;
        }
        $customerStatsSql .= ' GROUP BY o.customer_id
            ) customer_orders';
        $customerStatsStmt = $pdo->prepare($customerStatsSql);
        $customerStatsStmt->execute($customerStatsParams);
        $customerStats = $customerStatsStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($customerStats)) {
            $customerStats = [];
        }
        $activeCustomers = (int) ($customerStats['active_customers'] ?? 0);
        $repurchaseCustomers = (int) ($customerStats['repurchase_customers'] ?? 0);

        $paidAmount = round((float) ($storeSummary['paid_amount'] ?? 0), 2);
        $refundAmount = round((float) ($storeSummary['refund_amount'] ?? 0), 2);
        $netAmount = round($paidAmount - $refundAmount, 2);
        $paidOrders = (int) ($storeSummary['paid_orders'] ?? 0);
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
                'refund_orders' => (int) ($storeSummary['refund_orders'] ?? 0),
                'paid_txn_count' => (int) ($storeSummary['paid_txn_count'] ?? 0),
                'refund_txn_count' => (int) ($storeSummary['refund_txn_count'] ?? 0),
                'avg_order_amount' => $avgOrderAmount,
                'active_customers' => $activeCustomers,
                'new_customers' => (int) ($storeSummary['new_customers'] ?? 0),
                'repurchase_customers' => $repurchaseCustomers,
                'repurchase_rate' => $repurchaseRate,
                'appointments_total' => (int) ($storeSummary['appointments_total'] ?? 0),
                'appointments_completed' => (int) ($storeSummary['appointments_completed'] ?? 0),
                'appointments_cancelled' => (int) ($storeSummary['appointments_cancelled'] ?? 0),
                'appointments_no_show' => (int) ($storeSummary['appointments_no_show'] ?? 0),
                'card_consumed_sessions' => (int) ($storeSummary['card_consumed_sessions'] ?? 0),
            ],
        ]);
    }

    public static function revenueTrend(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        self::ensureAggregates($pdo, $dateFrom, $dateTo, $storeId);

        $trendSql = 'SELECT
                report_date,
                COALESCE(SUM(paid_amount), 0) AS paid_amount,
                COALESCE(SUM(refund_amount), 0) AS refund_amount,
                COALESCE(SUM(paid_orders), 0) AS paid_orders,
                COALESCE(SUM(paid_customers), 0) AS paid_customers,
                COALESCE(SUM(new_customers), 0) AS new_customers
            FROM qiling_report_daily_store
            WHERE report_date >= :date_from
              AND report_date <= :date_to';
        $trendParams = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
        if ($storeId !== null) {
            $trendSql .= ' AND store_id = :store_id';
            $trendParams['store_id'] = $storeId;
        }
        $trendSql .= ' GROUP BY report_date
            ORDER BY report_date ASC';

        $trendStmt = $pdo->prepare($trendSql);
        $trendStmt->execute($trendParams);
        $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

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
                'new_customers' => (int) ($row['new_customers'] ?? 0),
            ];
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
                'new_customers' => 0,
            ];

            $paidAmount = (float) $row['paid_amount'];
            $refundAmount = (float) $row['refund_amount'];
            $netAmount = round($paidAmount - $refundAmount, 2);
            $newCustomers = (int) ($row['new_customers'] ?? 0);

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
        [$dateFrom, $dateTo] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        self::ensureAggregates($pdo, $dateFrom, $dateTo, $storeId);

        $sql = 'SELECT
                source_channel,
                COALESCE(SUM(new_customers), 0) AS new_customers,
                COALESCE(SUM(paid_customers), 0) AS paid_customers,
                COALESCE(SUM(paid_orders), 0) AS paid_orders,
                COALESCE(SUM(paid_amount), 0) AS paid_amount,
                COALESCE(SUM(refund_amount), 0) AS refund_amount
            FROM qiling_report_daily_channel
            WHERE report_date >= :date_from
              AND report_date <= :date_to';
        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
        if ($storeId !== null) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY source_channel';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'channels' => 0,
            'new_customers' => 0,
            'paid_customers' => 0,
            'paid_orders' => 0,
            'paid_amount' => 0.0,
            'refund_amount' => 0.0,
            'net_amount' => 0.0,
        ];

        foreach ($rows as &$row) {
            $row['source_channel'] = trim((string) ($row['source_channel'] ?? '')) ?: self::UNKNOWN_CHANNEL;
            $newCustomers = (int) ($row['new_customers'] ?? 0);
            $paidCustomers = (int) ($row['paid_customers'] ?? 0);
            $paidOrders = (int) ($row['paid_orders'] ?? 0);
            $paidAmount = round((float) ($row['paid_amount'] ?? 0), 2);
            $refundAmount = round((float) ($row['refund_amount'] ?? 0), 2);

            $row['new_customers'] = $newCustomers;
            $row['paid_customers'] = $paidCustomers;
            $row['paid_orders'] = $paidOrders;
            $row['paid_amount'] = $paidAmount;
            $row['refund_amount'] = $refundAmount;
            $row['net_amount'] = round($paidAmount - $refundAmount, 2);
            $row['avg_order_amount'] = $paidOrders > 0 ? round($paidAmount / $paidOrders, 2) : 0.0;
            $row['conversion_rate'] = $newCustomers > 0 ? round($paidCustomers * 100 / $newCustomers, 2) : 0.0;

            $summary['new_customers'] += $newCustomers;
            $summary['paid_customers'] += $paidCustomers;
            $summary['paid_orders'] += $paidOrders;
            $summary['paid_amount'] += $paidAmount;
            $summary['refund_amount'] += $refundAmount;
        }
        unset($row);

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
        [$dateFrom, $dateTo] = self::resolveDateRange(29);
        $pdo = Database::pdo();

        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $limit = max(1, min(100, $limit));

        self::ensureAggregates($pdo, $dateFrom, $dateTo, $storeId);

        $sql = 'SELECT
                item_type,
                item_ref_id,
                item_name,
                COALESCE(SUM(total_qty), 0) AS total_qty,
                COALESCE(SUM(item_lines), 0) AS item_lines,
                COALESCE(SUM(order_count), 0) AS order_count,
                COALESCE(SUM(sales_amount), 0) AS sales_amount,
                COALESCE(SUM(commission_amount), 0) AS commission_amount
            FROM qiling_report_daily_service
            WHERE report_date >= :date_from
              AND report_date <= :date_to';
        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
        if ($storeId !== null) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' GROUP BY item_type, item_ref_id, item_name
            ORDER BY sales_amount DESC, total_qty DESC
            LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
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
        $pdo = Database::pdo();

        self::ensureAggregates($pdo, $dateFrom, $dateTo, $storeId);

        $sql = 'SELECT ds.store_id,
                       ds.report_date,
                       s.store_name,
                       ds.paid_orders,
                       ds.paid_amount,
                       ds.paid_customers,
                       ds.new_customers
                FROM qiling_report_daily_store ds
                LEFT JOIN qiling_stores s ON s.id = ds.store_id
                WHERE ds.report_date >= :date_from
                  AND ds.report_date <= :date_to';
        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if ($storeId !== null) {
            $sql .= ' AND ds.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' ORDER BY ds.report_date DESC, ds.store_id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'days' => count($rows),
            'paid_orders' => 0,
            'paid_amount' => 0.0,
            'paid_customers' => 0,
            'new_customers' => 0,
        ];

        foreach ($rows as &$row) {
            $row['paid_orders'] = (int) ($row['paid_orders'] ?? 0);
            $row['paid_amount'] = round((float) ($row['paid_amount'] ?? 0), 2);
            $row['paid_customers'] = (int) ($row['paid_customers'] ?? 0);
            $row['new_customers'] = (int) ($row['new_customers'] ?? 0);
            $row['avg_order_amount'] = (int) ($row['paid_orders'] ?? 0) > 0
                ? round((float) $row['paid_amount'] / (int) $row['paid_orders'], 2)
                : 0.00;

            $summary['paid_orders'] += (int) ($row['paid_orders'] ?? 0);
            $summary['paid_amount'] += (float) ($row['paid_amount'] ?? 0);
            $summary['new_customers'] += (int) ($row['new_customers'] ?? 0);
        }
        unset($row);

        $summary['paid_customers'] = self::uniquePaidCustomers($pdo, $fromAt, $toAt, $storeId);
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

    private static function ensureAggregates(PDO $pdo, string $dateFrom, string $dateTo, ?int $storeId): void
    {
        $ttlSeconds = isset($_GET['ttl_seconds']) && is_numeric($_GET['ttl_seconds'])
            ? (int) $_GET['ttl_seconds']
            : 300;
        $ttlSeconds = max(30, min(86400, $ttlSeconds));

        if (self::queryBool('force')) {
            ReportAggregateService::syncRange($pdo, $dateFrom, $dateTo, $storeId);
            return;
        }

        ReportAggregateService::ensureFreshRange($pdo, $dateFrom, $dateTo, $storeId, $ttlSeconds);
    }

    private static function queryBool(string $key): bool
    {
        if (!array_key_exists($key, $_GET)) {
            return false;
        }
        $raw = $_GET[$key];

        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
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

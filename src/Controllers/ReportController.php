<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\ReportAggregateService;
use Qiling\Support\Request;
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

    public static function cockpit(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo, $fromAt, $toAt] = self::resolveDateRange(29);
        $topN = isset($_GET['top_n']) && is_numeric($_GET['top_n']) ? (int) $_GET['top_n'] : 20;
        $topN = max(5, min(100, $topN));

        $pdo = Database::pdo();
        self::ensureAggregates($pdo, $dateFrom, $dateTo, $storeId);

        $storeProfit = self::queryStoreProfit($pdo, $fromAt, $toAt, $storeId, $topN);
        $staffProfit = self::queryStaffProfit($pdo, $fromAt, $toAt, $storeId, $topN);
        $serviceProfit = self::queryServiceProfit($pdo, $fromAt, $toAt, $storeId, $topN);
        $channelRoi = self::queryChannelRoi($pdo, $dateFrom, $dateTo, $storeId, $topN);
        $repurchaseCycle = self::queryRepurchaseCycles($pdo, $fromAt, $toAt, $storeId, $topN);

        $storeSummary = is_array($storeProfit['summary'] ?? null) ? $storeProfit['summary'] : [];
        $channelSummary = is_array($channelRoi['summary'] ?? null) ? $channelRoi['summary'] : [];
        $repurchaseOverall = is_array($repurchaseCycle['overall'] ?? null) ? $repurchaseCycle['overall'] : [];

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'top_n' => $topN,
            'summary' => [
                'sales_amount' => round((float) ($storeSummary['sales_amount'] ?? 0), 2),
                'commission_amount' => round((float) ($storeSummary['commission_amount'] ?? 0), 2),
                'material_cost' => round((float) ($storeSummary['material_cost'] ?? 0), 2),
                'gross_profit' => round((float) ($storeSummary['gross_profit'] ?? 0), 2),
                'gross_margin_rate' => round((float) ($storeSummary['gross_margin_rate'] ?? 0), 2),
                'channel_cost_amount' => round((float) ($channelSummary['cost_amount'] ?? 0), 2),
                'channel_profit_after_acq' => round((float) ($channelSummary['profit_after_acq'] ?? 0), 2),
                'repurchase_orders' => (int) ($repurchaseOverall['repeat_orders'] ?? 0),
                'avg_repurchase_cycle_days' => round((float) ($repurchaseOverall['avg_cycle_days'] ?? 0), 2),
            ],
            'store_profit' => $storeProfit,
            'staff_profit' => $staffProfit,
            'service_profit' => $serviceProfit,
            'channel_roi' => $channelRoi,
            'repurchase_cycle' => $repurchaseCycle,
        ]);
    }

    public static function channelCosts(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = self::resolveStoreId($user);
        [$dateFrom, $dateTo] = self::resolveDateRange(29);

        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'qiling_report_daily_channel_cost')) {
            Response::json([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'summary' => [
                    'rows' => 0,
                    'cost_amount' => 0.0,
                ],
                'data' => [],
            ]);
            return;
        }

        $sql = 'SELECT report_date, store_id, source_channel, cost_amount, note, updated_at
                FROM qiling_report_daily_channel_cost
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
        $sql .= ' ORDER BY report_date DESC, store_id ASC, source_channel ASC
                  LIMIT 2000';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'rows' => 0,
            'cost_amount' => 0.0,
        ];
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['store_id'] = (int) ($row['store_id'] ?? 0);
            $row['source_channel'] = trim((string) ($row['source_channel'] ?? '')) ?: self::UNKNOWN_CHANNEL;
            $row['cost_amount'] = round((float) ($row['cost_amount'] ?? 0), 2);
            $summary['cost_amount'] += (float) $row['cost_amount'];
        }
        unset($row);
        $summary['rows'] = count($rows);
        $summary['cost_amount'] = round($summary['cost_amount'], 2);

        Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => $summary,
            'data' => $rows,
        ]);
    }

    public static function upsertChannelCosts(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            Response::json(['message' => 'items are required'], 422);
            return;
        }
        if (count($items) > 1000) {
            Response::json(['message' => 'items too many'], 422);
            return;
        }

        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'qiling_report_daily_channel_cost')) {
            Response::json(['message' => 'channel cost table is not ready, please run system upgrade'], 422);
            return;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $reportDate = trim((string) ($item['report_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
                Response::json(['message' => 'report_date is invalid'], 422);
                return;
            }

            $sourceChannel = trim((string) ($item['source_channel'] ?? ''));
            if ($sourceChannel === '') {
                $sourceChannel = self::UNKNOWN_CHANNEL;
            }

            $rawCostAmount = $item['cost_amount'] ?? null;
            if (!is_numeric($rawCostAmount)) {
                Response::json(['message' => 'cost_amount is invalid'], 422);
                return;
            }
            $costAmount = round((float) $rawCostAmount, 2);
            if ($costAmount < 0) {
                Response::json(['message' => 'cost_amount must be >= 0'], 422);
                return;
            }

            $inputStoreId = is_numeric($item['store_id'] ?? null) ? (int) $item['store_id'] : 0;
            $storeId = DataScope::resolveInputStoreId($user, $inputStoreId);
            $note = trim((string) ($item['note'] ?? ''));

            $normalized[] = [
                'report_date' => $reportDate,
                'store_id' => $storeId,
                'source_channel' => $sourceChannel,
                'cost_amount' => $costAmount,
                'note' => $note,
            ];
        }

        if ($normalized === []) {
            Response::json(['message' => 'items are required'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_report_daily_channel_cost
             (report_date, store_id, source_channel, cost_amount, note, created_at, updated_at)
             VALUES
             (:report_date, :store_id, :source_channel, :cost_amount, :note, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                cost_amount = VALUES(cost_amount),
                note = VALUES(note),
                updated_at = VALUES(updated_at)'
        );

        try {
            $pdo->beginTransaction();
            $saved = 0;
            foreach ($normalized as $row) {
                $stmt->execute([
                    'report_date' => (string) $row['report_date'],
                    'store_id' => (int) $row['store_id'],
                    'source_channel' => (string) $row['source_channel'],
                    'cost_amount' => (float) $row['cost_amount'],
                    'note' => (string) $row['note'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $saved++;
            }
            $pdo->commit();

            Response::json([
                'saved' => $saved,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('upsert channel costs failed', $e);
        }
    }

    /**
     * @return array{summary: array<string, int|float>, data: array<int, array<string, mixed>>}
     */
    private static function queryStoreProfit(PDO $pdo, string $fromAt, string $toAt, ?int $storeId, int $topN): array
    {
        $sql = 'SELECT
                    o.store_id,
                    COALESCE(st.store_name, \'\') AS store_name,
                    COUNT(DISTINCT o.id) AS order_count,
                    COUNT(DISTINCT o.customer_id) AS customer_count,
                    COALESCE(SUM(COALESCE(o.paid_amount, o.payable_amount)), 0) AS sales_amount,
                    COALESCE(SUM(COALESCE(oc.commission_amount, 0)), 0) AS commission_amount,
                    COALESCE(SUM(COALESCE(mc.material_cost, 0)), 0) AS material_cost
                FROM qiling_orders o
                LEFT JOIN qiling_stores st ON st.id = o.store_id
                LEFT JOIN (
                    SELECT order_id, COALESCE(SUM(commission_amount), 0) AS commission_amount
                    FROM qiling_order_items
                    GROUP BY order_id
                ) oc ON oc.order_id = o.id
                LEFT JOIN (
                    SELECT reference_id AS order_id, COALESCE(SUM(ABS(total_cost)), 0) AS material_cost
                    FROM qiling_inventory_stock_movements
                    WHERE movement_type = \'consume\'
                      AND reference_type = \'order_paid_consume\'
                    GROUP BY reference_id
                ) mc ON mc.order_id = o.id
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
        $sql .= ' GROUP BY o.store_id, st.store_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['store_id'] = (int) ($row['store_id'] ?? 0);
            $name = trim((string) ($row['store_name'] ?? ''));
            $row['store_name'] = $name !== '' ? $name : '未命名门店';
            $row['store_label'] = (string) $row['store_name'] . ' (#' . (int) $row['store_id'] . ')';
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['customer_count'] = (int) ($row['customer_count'] ?? 0);
        }
        unset($row);

        return self::finalizeProfitRows($rows, $topN);
    }

    /**
     * @return array{summary: array<string, int|float>, data: array<int, array<string, mixed>>}
     */
    private static function queryStaffProfit(PDO $pdo, string $fromAt, string $toAt, ?int $storeId, int $topN): array
    {
        $sql = 'SELECT
                    oi.staff_id,
                    st.staff_no,
                    st.role_key,
                    u.username AS staff_username,
                    u.email AS staff_email,
                    COUNT(oi.id) AS item_count,
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(oi.final_amount), 0) AS sales_amount,
                    COALESCE(SUM(oi.commission_amount), 0) AS commission_amount,
                    COALESCE(SUM(COALESCE(mc.material_cost, 0) * CASE WHEN ot.order_total > 0 THEN oi.final_amount / ot.order_total ELSE 0 END), 0) AS material_cost
                FROM qiling_order_items oi
                INNER JOIN qiling_orders o ON o.id = oi.order_id
                INNER JOIN qiling_staff st ON st.id = oi.staff_id
                INNER JOIN qiling_users u ON u.id = st.user_id
                LEFT JOIN (
                    SELECT order_id, COALESCE(SUM(final_amount), 0) AS order_total
                    FROM qiling_order_items
                    GROUP BY order_id
                ) ot ON ot.order_id = oi.order_id
                LEFT JOIN (
                    SELECT reference_id AS order_id, COALESCE(SUM(ABS(total_cost)), 0) AS material_cost
                    FROM qiling_inventory_stock_movements
                    WHERE movement_type = \'consume\'
                      AND reference_type = \'order_paid_consume\'
                    GROUP BY reference_id
                ) mc ON mc.order_id = oi.order_id
                WHERE oi.staff_id IS NOT NULL
                  AND o.status = :status_paid
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
        $sql .= ' GROUP BY oi.staff_id, st.staff_no, st.role_key, u.username, u.email';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['staff_id'] = (int) ($row['staff_id'] ?? 0);
            $row['staff_no'] = trim((string) ($row['staff_no'] ?? ''));
            $row['role_key'] = trim((string) ($row['role_key'] ?? ''));
            $row['staff_username'] = trim((string) ($row['staff_username'] ?? ''));
            $row['staff_email'] = trim((string) ($row['staff_email'] ?? ''));
            $row['item_count'] = (int) ($row['item_count'] ?? 0);
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['staff_label'] = ($row['staff_username'] !== '' ? (string) $row['staff_username'] : '未命名员工')
                . ' (' . ($row['staff_no'] !== '' ? (string) $row['staff_no'] : '-') . ')';
        }
        unset($row);

        return self::finalizeProfitRows($rows, $topN);
    }

    /**
     * @return array{summary: array<string, int|float>, data: array<int, array<string, mixed>>}
     */
    private static function queryServiceProfit(PDO $pdo, string $fromAt, string $toAt, ?int $storeId, int $topN): array
    {
        $sql = 'SELECT
                    oi.item_type,
                    COALESCE(oi.item_ref_id, 0) AS item_ref_id,
                    COALESCE(oi.item_name, \'\') AS item_name,
                    COUNT(oi.id) AS item_lines,
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(oi.qty), 0) AS total_qty,
                    COALESCE(SUM(oi.final_amount), 0) AS sales_amount,
                    COALESCE(SUM(oi.commission_amount), 0) AS commission_amount,
                    COALESCE(SUM(COALESCE(mc.material_cost, 0) * CASE WHEN ot.order_total > 0 THEN oi.final_amount / ot.order_total ELSE 0 END), 0) AS material_cost
                FROM qiling_order_items oi
                INNER JOIN qiling_orders o ON o.id = oi.order_id
                LEFT JOIN (
                    SELECT order_id, COALESCE(SUM(final_amount), 0) AS order_total
                    FROM qiling_order_items
                    GROUP BY order_id
                ) ot ON ot.order_id = oi.order_id
                LEFT JOIN (
                    SELECT reference_id AS order_id, COALESCE(SUM(ABS(total_cost)), 0) AS material_cost
                    FROM qiling_inventory_stock_movements
                    WHERE movement_type = \'consume\'
                      AND reference_type = \'order_paid_consume\'
                    GROUP BY reference_id
                ) mc ON mc.order_id = oi.order_id
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
        $sql .= ' GROUP BY oi.item_type, oi.item_ref_id, oi.item_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['item_type'] = trim((string) ($row['item_type'] ?? ''));
            $row['item_ref_id'] = (int) ($row['item_ref_id'] ?? 0);
            $row['item_name'] = trim((string) ($row['item_name'] ?? ''));
            $row['item_lines'] = (int) ($row['item_lines'] ?? 0);
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['total_qty'] = round((float) ($row['total_qty'] ?? 0), 2);
            $row['item_key'] = self::serviceItemKey($row);
        }
        unset($row);

        return self::finalizeProfitRows($rows, $topN);
    }

    /**
     * @return array{summary: array<string, int|float>, data: array<int, array<string, mixed>>}
     */
    private static function queryChannelRoi(PDO $pdo, string $dateFrom, string $dateTo, ?int $storeId, int $topN): array
    {
        $hasCostTable = self::tableExists($pdo, 'qiling_report_daily_channel_cost');
        $sql = 'SELECT
                    c.source_channel,
                    COALESCE(SUM(c.new_customers), 0) AS new_customers,
                    COALESCE(SUM(c.paid_customers), 0) AS paid_customers,
                    COALESCE(SUM(c.paid_orders), 0) AS paid_orders,
                    COALESCE(SUM(c.paid_amount), 0) AS paid_amount,
                    COALESCE(SUM(c.refund_amount), 0) AS refund_amount';
        if ($hasCostTable) {
            $sql .= ',
                    COALESCE(SUM(k.cost_amount), 0) AS cost_amount';
        } else {
            $sql .= ',
                    0 AS cost_amount';
        }
        $sql .= '
                FROM qiling_report_daily_channel c';
        if ($hasCostTable) {
            $sql .= '
                LEFT JOIN qiling_report_daily_channel_cost k
                  ON k.report_date = c.report_date
                 AND k.store_id = c.store_id
                 AND k.source_channel = c.source_channel';
        }
        $sql .= '
                WHERE c.report_date >= :date_from
                  AND c.report_date <= :date_to';

        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
        if ($storeId !== null) {
            $sql .= ' AND c.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= '
                GROUP BY c.source_channel';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $summary = [
            'channels' => 0,
            'new_customers' => 0,
            'paid_customers' => 0,
            'paid_orders' => 0,
            'paid_amount' => 0.0,
            'refund_amount' => 0.0,
            'net_amount' => 0.0,
            'cost_amount' => 0.0,
            'profit_after_acq' => 0.0,
            'roi_percent' => 0.0,
            'cost_coverage_rate' => 0.0,
        ];

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['source_channel'] = trim((string) ($row['source_channel'] ?? '')) ?: self::UNKNOWN_CHANNEL;
            $row['new_customers'] = (int) ($row['new_customers'] ?? 0);
            $row['paid_customers'] = (int) ($row['paid_customers'] ?? 0);
            $row['paid_orders'] = (int) ($row['paid_orders'] ?? 0);
            $row['paid_amount'] = round((float) ($row['paid_amount'] ?? 0), 2);
            $row['refund_amount'] = round((float) ($row['refund_amount'] ?? 0), 2);
            $row['cost_amount'] = round((float) ($row['cost_amount'] ?? 0), 2);
            $row['net_amount'] = round((float) $row['paid_amount'] - (float) $row['refund_amount'], 2);
            $row['profit_after_acq'] = round((float) $row['net_amount'] - (float) $row['cost_amount'], 2);
            $row['conversion_rate'] = (int) $row['new_customers'] > 0
                ? round(((int) $row['paid_customers'] * 100) / (int) $row['new_customers'], 2)
                : 0.0;
            $row['cac_amount'] = (int) $row['new_customers'] > 0
                ? round((float) $row['cost_amount'] / (int) $row['new_customers'], 2)
                : 0.0;
            $row['roi_percent'] = (float) $row['cost_amount'] > 0
                ? round((((float) $row['net_amount'] - (float) $row['cost_amount']) * 100) / (float) $row['cost_amount'], 2)
                : 0.0;
            $row['cost_coverage_rate'] = (float) $row['cost_amount'] > 0
                ? round(((float) $row['net_amount'] * 100) / (float) $row['cost_amount'], 2)
                : 0.0;

            $summary['new_customers'] += (int) $row['new_customers'];
            $summary['paid_customers'] += (int) $row['paid_customers'];
            $summary['paid_orders'] += (int) $row['paid_orders'];
            $summary['paid_amount'] += (float) $row['paid_amount'];
            $summary['refund_amount'] += (float) $row['refund_amount'];
            $summary['net_amount'] += (float) $row['net_amount'];
            $summary['cost_amount'] += (float) $row['cost_amount'];
            $summary['profit_after_acq'] += (float) $row['profit_after_acq'];
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $aValue = (float) ($a['profit_after_acq'] ?? 0);
            $bValue = (float) ($b['profit_after_acq'] ?? 0);
            if ($aValue === $bValue) {
                return strcmp((string) ($a['source_channel'] ?? ''), (string) ($b['source_channel'] ?? ''));
            }
            return $aValue > $bValue ? -1 : 1;
        });

        $summary['channels'] = count($rows);
        $summary['paid_amount'] = round($summary['paid_amount'], 2);
        $summary['refund_amount'] = round($summary['refund_amount'], 2);
        $summary['net_amount'] = round($summary['net_amount'], 2);
        $summary['cost_amount'] = round($summary['cost_amount'], 2);
        $summary['profit_after_acq'] = round($summary['profit_after_acq'], 2);
        $summary['roi_percent'] = $summary['cost_amount'] > 0
            ? round(($summary['profit_after_acq'] * 100) / $summary['cost_amount'], 2)
            : 0.0;
        $summary['cost_coverage_rate'] = $summary['cost_amount'] > 0
            ? round(($summary['net_amount'] * 100) / $summary['cost_amount'], 2)
            : 0.0;

        return [
            'summary' => $summary,
            'data' => array_slice($rows, 0, $topN),
        ];
    }

    /**
     * @return array{
     *   overall: array<string, int|float>,
     *   by_store: array<int, array<string, mixed>>,
     *   by_staff: array<int, array<string, mixed>>,
     *   by_service: array<int, array<string, mixed>>
     * }
     */
    private static function queryRepurchaseCycles(PDO $pdo, string $fromAt, string $toAt, ?int $storeId, int $topN): array
    {
        $sql = 'SELECT
                    o.id AS order_id,
                    o.customer_id,
                    o.store_id,
                    COALESCE(st.store_name, \'\') AS store_name,
                    o.paid_at,
                    COALESCE(primary_item.primary_staff_id, 0) AS primary_staff_id,
                    COALESCE(primary_item.primary_staff_username, \'\') AS primary_staff_username,
                    COALESCE(primary_item.primary_staff_no, \'\') AS primary_staff_no,
                    COALESCE(primary_item.primary_item_type, \'\') AS primary_item_type,
                    COALESCE(primary_item.primary_item_ref_id, 0) AS primary_item_ref_id,
                    COALESCE(primary_item.primary_item_name, \'\') AS primary_item_name
                FROM qiling_orders o
                LEFT JOIN qiling_stores st ON st.id = o.store_id
                LEFT JOIN (
                    SELECT
                        oi.order_id,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(oi.staff_id, 0) ORDER BY oi.final_amount DESC, oi.id ASC), \',\', 1) AS primary_staff_id,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(u.username, \'\') ORDER BY oi.final_amount DESC, oi.id ASC), \',\', 1) AS primary_staff_username,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(s.staff_no, \'\') ORDER BY oi.final_amount DESC, oi.id ASC), \',\', 1) AS primary_staff_no,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(oi.item_type, \'\') ORDER BY oi.final_amount DESC, oi.id ASC), \',\', 1) AS primary_item_type,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(oi.item_ref_id, 0) ORDER BY oi.final_amount DESC, oi.id ASC), \',\', 1) AS primary_item_ref_id,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(REPLACE(oi.item_name, \',\', \' \'), \'\') ORDER BY oi.final_amount DESC, oi.id ASC), \',\', 1) AS primary_item_name
                    FROM qiling_order_items oi
                    LEFT JOIN qiling_staff s ON s.id = oi.staff_id
                    LEFT JOIN qiling_users u ON u.id = s.user_id
                    GROUP BY oi.order_id
                ) primary_item ON primary_item.order_id = o.id
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
        $sql .= ' ORDER BY o.customer_id ASC, o.paid_at ASC, o.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $lastByCustomer = [];
        $overallIntervals = [];
        $storeBuckets = [];
        $staffBuckets = [];
        $serviceBuckets = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($customerId <= 0) {
                continue;
            }

            $paidAt = trim((string) ($row['paid_at'] ?? ''));
            $ts = strtotime($paidAt);
            if ($ts === false) {
                continue;
            }

            $lastTs = $lastByCustomer[$customerId] ?? null;
            if (is_int($lastTs) && $lastTs > 0) {
                $deltaDays = max(0.0, ($ts - $lastTs) / 86400);
                $overallIntervals[] = $deltaDays;

                $bucketStoreId = (int) ($row['store_id'] ?? 0);
                $bucketStoreName = trim((string) ($row['store_name'] ?? ''));
                $storeLabel = ($bucketStoreName !== '' ? $bucketStoreName : '未命名门店') . ' (#' . $bucketStoreId . ')';
                self::pushCycleInterval(
                    $storeBuckets,
                    (string) $bucketStoreId,
                    $storeLabel,
                    $deltaDays,
                    [
                        'store_id' => $bucketStoreId,
                        'store_name' => $bucketStoreName !== '' ? $bucketStoreName : '未命名门店',
                    ]
                );

                $primaryStaffId = (int) ($row['primary_staff_id'] ?? 0);
                $primaryStaffName = trim((string) ($row['primary_staff_username'] ?? ''));
                $primaryStaffNo = trim((string) ($row['primary_staff_no'] ?? ''));
                if ($primaryStaffId > 0) {
                    $staffLabel = ($primaryStaffName !== '' ? $primaryStaffName : '未命名员工')
                        . ' (' . ($primaryStaffNo !== '' ? $primaryStaffNo : '-') . ')';
                    self::pushCycleInterval(
                        $staffBuckets,
                        (string) $primaryStaffId,
                        $staffLabel,
                        $deltaDays,
                        [
                            'staff_id' => $primaryStaffId,
                            'staff_username' => $primaryStaffName,
                            'staff_no' => $primaryStaffNo,
                        ]
                    );
                }

                $primaryItemType = trim((string) ($row['primary_item_type'] ?? ''));
                $primaryItemRefId = (int) ($row['primary_item_ref_id'] ?? 0);
                $primaryItemName = trim((string) ($row['primary_item_name'] ?? ''));
                if ($primaryItemType !== '' || $primaryItemName !== '') {
                    $serviceKey = self::serviceItemKey([
                        'item_type' => $primaryItemType,
                        'item_ref_id' => $primaryItemRefId,
                        'item_name' => $primaryItemName,
                    ]);
                    $serviceLabel = $primaryItemName !== ''
                        ? ($primaryItemName . '（' . ($primaryItemType !== '' ? $primaryItemType : 'item') . '）')
                        : (($primaryItemType !== '' ? $primaryItemType : 'item') . ' #' . $primaryItemRefId);
                    self::pushCycleInterval(
                        $serviceBuckets,
                        $serviceKey,
                        $serviceLabel,
                        $deltaDays,
                        [
                            'item_key' => $serviceKey,
                            'item_type' => $primaryItemType,
                            'item_ref_id' => $primaryItemRefId,
                            'item_name' => $primaryItemName,
                        ]
                    );
                }
            }

            $lastByCustomer[$customerId] = $ts;
        }

        return [
            'overall' => self::buildCycleSummary($overallIntervals),
            'by_store' => self::finalizeCycleBuckets($storeBuckets, $topN, 'store_label'),
            'by_staff' => self::finalizeCycleBuckets($staffBuckets, $topN, 'staff_label'),
            'by_service' => self::finalizeCycleBuckets($serviceBuckets, $topN, 'item_label'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{summary: array<string, int|float>, data: array<int, array<string, mixed>>}
     */
    private static function finalizeProfitRows(array $rows, int $topN): array
    {
        $summary = [
            'rows' => 0,
            'order_count' => 0,
            'sales_amount' => 0.0,
            'commission_amount' => 0.0,
            'material_cost' => 0.0,
            'gross_profit' => 0.0,
            'gross_margin_rate' => 0.0,
        ];

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $salesAmount = round((float) ($row['sales_amount'] ?? 0), 2);
            $commissionAmount = round((float) ($row['commission_amount'] ?? 0), 2);
            $materialCost = round((float) ($row['material_cost'] ?? 0), 2);
            $grossProfit = round($salesAmount - $commissionAmount - $materialCost, 2);
            $grossMarginRate = $salesAmount > 0 ? round(($grossProfit * 100) / $salesAmount, 2) : 0.0;

            $row['sales_amount'] = $salesAmount;
            $row['commission_amount'] = $commissionAmount;
            $row['material_cost'] = $materialCost;
            $row['gross_profit'] = $grossProfit;
            $row['gross_margin_rate'] = $grossMarginRate;
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['contribution_rate'] = 0.0;

            $summary['order_count'] += (int) $row['order_count'];
            $summary['sales_amount'] += $salesAmount;
            $summary['commission_amount'] += $commissionAmount;
            $summary['material_cost'] += $materialCost;
            $summary['gross_profit'] += $grossProfit;
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $aGross = (float) ($a['gross_profit'] ?? 0);
            $bGross = (float) ($b['gross_profit'] ?? 0);
            if ($aGross === $bGross) {
                $aSales = (float) ($a['sales_amount'] ?? 0);
                $bSales = (float) ($b['sales_amount'] ?? 0);
                if ($aSales === $bSales) {
                    return 0;
                }
                return $aSales > $bSales ? -1 : 1;
            }
            return $aGross > $bGross ? -1 : 1;
        });

        $totalGross = (float) $summary['gross_profit'];
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $gross = (float) ($row['gross_profit'] ?? 0);
            $row['contribution_rate'] = abs($totalGross) > 0.00001
                ? round(($gross * 100) / $totalGross, 2)
                : 0.0;
        }
        unset($row);

        $summary['rows'] = count($rows);
        $summary['sales_amount'] = round((float) $summary['sales_amount'], 2);
        $summary['commission_amount'] = round((float) $summary['commission_amount'], 2);
        $summary['material_cost'] = round((float) $summary['material_cost'], 2);
        $summary['gross_profit'] = round((float) $summary['gross_profit'], 2);
        $summary['gross_margin_rate'] = (float) $summary['sales_amount'] > 0
            ? round((((float) $summary['gross_profit']) * 100) / ((float) $summary['sales_amount']), 2)
            : 0.0;

        return [
            'summary' => $summary,
            'data' => array_slice($rows, 0, $topN),
        ];
    }

    /**
     * @param array<string, array{label:string, intervals:array<int, float|int>, extra:array<string, mixed>}> $buckets
     * @return array<int, array<string, mixed>>
     */
    private static function finalizeCycleBuckets(array $buckets, int $topN, string $labelField): array
    {
        $rows = [];
        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $intervals = $bucket['intervals'] ?? [];
            if (!is_array($intervals)) {
                $intervals = [];
            }
            $summary = self::buildCycleSummary($intervals);
            if ((int) ($summary['repeat_orders'] ?? 0) <= 0) {
                continue;
            }
            $row = $bucket['extra'] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            $row[$labelField] = (string) ($bucket['label'] ?? '');
            foreach ($summary as $k => $v) {
                $row[$k] = $v;
            }
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            $aRepeat = (int) ($a['repeat_orders'] ?? 0);
            $bRepeat = (int) ($b['repeat_orders'] ?? 0);
            if ($aRepeat === $bRepeat) {
                $aAvg = (float) ($a['avg_cycle_days'] ?? 0);
                $bAvg = (float) ($b['avg_cycle_days'] ?? 0);
                if ($aAvg === $bAvg) {
                    return 0;
                }
                return $aAvg < $bAvg ? -1 : 1;
            }
            return $aRepeat > $bRepeat ? -1 : 1;
        });

        return array_slice($rows, 0, $topN);
    }

    /**
     * @param array<int, float|int> $intervals
     * @return array<string, int|float>
     */
    private static function buildCycleSummary(array $intervals): array
    {
        if ($intervals === []) {
            return [
                'repeat_orders' => 0,
                'avg_cycle_days' => 0.0,
                'p50_cycle_days' => 0.0,
                'p90_cycle_days' => 0.0,
                'min_cycle_days' => 0.0,
                'max_cycle_days' => 0.0,
            ];
        }

        $normalized = [];
        foreach ($intervals as $value) {
            $normalized[] = max(0.0, (float) $value);
        }
        sort($normalized, SORT_NUMERIC);
        $count = count($normalized);
        $sum = array_sum($normalized);

        return [
            'repeat_orders' => $count,
            'avg_cycle_days' => round($sum / $count, 2),
            'p50_cycle_days' => self::percentile($normalized, 50),
            'p90_cycle_days' => self::percentile($normalized, 90),
            'min_cycle_days' => round((float) $normalized[0], 2),
            'max_cycle_days' => round((float) $normalized[$count - 1], 2),
        ];
    }

    /**
     * @param array<int, float|int> $sortedValues
     */
    private static function percentile(array $sortedValues, int $percentile): float
    {
        if ($sortedValues === []) {
            return 0.0;
        }

        $p = max(0, min(100, $percentile));
        $rank = ($p / 100) * (count($sortedValues) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return round((float) $sortedValues[$low], 2);
        }

        $weight = $rank - $low;
        return round(
            (((float) $sortedValues[$low]) * (1 - $weight)) + (((float) $sortedValues[$high]) * $weight),
            2
        );
    }

    /**
     * @param array<string, array{label:string, intervals:array<int, float|int>, extra:array<string, mixed>}> $buckets
     * @param array<string, mixed> $extra
     */
    private static function pushCycleInterval(array &$buckets, string $key, string $label, float $days, array $extra = []): void
    {
        if ($key === '') {
            return;
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'label' => $label,
                'intervals' => [],
                'extra' => $extra,
            ];
        }
        $buckets[$key]['intervals'][] = max(0.0, $days);
        if (($buckets[$key]['label'] ?? '') === '' && $label !== '') {
            $buckets[$key]['label'] = $label;
        }
        $savedExtra = $buckets[$key]['extra'] ?? [];
        if (!is_array($savedExtra)) {
            $savedExtra = [];
        }
        foreach ($extra as $k => $v) {
            if (!array_key_exists($k, $savedExtra)) {
                $savedExtra[$k] = $v;
            }
        }
        $buckets[$key]['extra'] = $savedExtra;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function serviceItemKey(array $row): string
    {
        $itemType = trim((string) ($row['item_type'] ?? ''));
        $itemRefId = (int) ($row['item_ref_id'] ?? 0);
        $itemName = trim((string) ($row['item_name'] ?? ''));
        $safeName = str_replace('|', '/', $itemName);

        return $itemType . '|' . $itemRefId . '|' . $safeName;
    }

    private static function tableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $stmt->execute([
            'table_name' => $tableName,
        ]);

        return (int) $stmt->fetchColumn() > 0;
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
        DataScope::requireManager($user);
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

<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;
use RuntimeException;

final class ReportAggregateService
{
    private static bool $tableReady = false;

    public static function ensureTables(PDO $pdo): void
    {
        if (self::$tableReady) {
            return;
        }

        if (!self::schemaReady($pdo)) {
            throw new RuntimeException('报表数据库结构未升级，请先到系统升级页面执行升级。');
        }

        self::$tableReady = true;
    }

    private static function schemaReady(PDO $pdo): bool
    {
        try {
            $required = [
                'qiling_report_daily_store',
                'qiling_report_daily_channel',
                'qiling_report_daily_service',
                'qiling_report_aggregate_marks',
            ];
            $stmt = $pdo->query("SHOW TABLES LIKE 'qiling_report_%'");
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

            $columnStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $columnStmt->execute([
                'table_name' => 'qiling_report_daily_channel',
                'column_name' => 'paid_customers',
            ]);
            return (int) $columnStmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function ensureFreshRange(
        PDO $pdo,
        string $dateFrom,
        string $dateTo,
        ?int $storeId,
        int $ttlSeconds = 300
    ): void {
        self::ensureTables($pdo);
        [$fromDate, $toDate] = self::normalizeDateRange($dateFrom, $dateTo);

        $ttlSeconds = max(30, min($ttlSeconds, 86400));
        $storeMarkId = ($storeId !== null && $storeId > 0) ? $storeId : 0;

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS marks_count, MIN(aggregated_at) AS min_aggregated_at
             FROM qiling_report_aggregate_marks
             WHERE report_date >= :from_date
               AND report_date <= :to_date
               AND store_id = :store_id'
        );
        $stmt->execute([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'store_id' => $storeMarkId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $marksCount = is_array($row) ? (int) ($row['marks_count'] ?? 0) : 0;
        $minAggregatedAt = is_array($row) ? (string) ($row['min_aggregated_at'] ?? '') : '';

        $expectedDays = self::daysBetweenInclusive($fromDate, $toDate);
        $stale = $marksCount < $expectedDays;
        if (!$stale) {
            $minTs = strtotime($minAggregatedAt);
            $staleAt = time() - $ttlSeconds;
            if ($minTs === false || $minTs < $staleAt) {
                $stale = true;
            }
        }

        if ($stale) {
            self::syncRange($pdo, $fromDate, $toDate, $storeId);
        }
    }

    /**
     * @return array<string, int|string>
     */
    public static function syncRange(PDO $pdo, string $dateFrom, string $dateTo, ?int $storeId): array
    {
        self::ensureTables($pdo);
        [$fromDate, $toDate] = self::normalizeDateRange($dateFrom, $dateTo);
        $fromAt = $fromDate . ' 00:00:00';
        $toAt = $toDate . ' 23:59:59';
        $aggregatedAt = gmdate('Y-m-d H:i:s');
        $scopeStoreId = ($storeId !== null && $storeId > 0) ? $storeId : null;

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            self::clearRange($pdo, $fromDate, $toDate, $scopeStoreId);

            $storeDailyRows = 0;
            $storeDailyRows += self::syncStoreDailyFromPayments($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);
            $storeDailyRows += self::syncStoreDailyNewCustomers($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);
            $storeDailyRows += self::syncStoreDailyAppointments($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);
            $storeDailyRows += self::syncStoreDailyCardConsumes($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);

            $channelRows = 0;
            $channelRows += self::syncChannelDailyNewCustomers($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);
            $channelRows += self::syncChannelDailySales($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);

            $serviceRows = self::syncServiceDaily($pdo, $fromAt, $toAt, $aggregatedAt, $scopeStoreId);
            $markRows = self::upsertMarks($pdo, $fromDate, $toDate, $scopeStoreId, $aggregatedAt);

            if ($ownTransaction) {
                $pdo->commit();
            }

            return [
                'date_from' => $fromDate,
                'date_to' => $toDate,
                'store_id' => $scopeStoreId ?? 0,
                'store_daily_rows' => $storeDailyRows,
                'channel_rows' => $channelRows,
                'service_rows' => $serviceRows,
                'mark_rows' => $markRows,
            ];
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function clearRange(PDO $pdo, string $fromDate, string $toDate, ?int $storeId): void
    {
        $deleteStore = 'DELETE FROM qiling_report_daily_store
            WHERE report_date >= :from_date
              AND report_date <= :to_date';
        $deleteChannel = 'DELETE FROM qiling_report_daily_channel
            WHERE report_date >= :from_date
              AND report_date <= :to_date';
        $deleteService = 'DELETE FROM qiling_report_daily_service
            WHERE report_date >= :from_date
              AND report_date <= :to_date';

        $params = [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];

        if ($storeId !== null) {
            $deleteStore .= ' AND store_id = :store_id';
            $deleteChannel .= ' AND store_id = :store_id';
            $deleteService .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $stmt = $pdo->prepare($deleteStore);
        $stmt->execute($params);
        $stmt = $pdo->prepare($deleteChannel);
        $stmt->execute($params);
        $stmt = $pdo->prepare($deleteService);
        $stmt->execute($params);
    }

    private static function syncStoreDailyFromPayments(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_store
                (report_date, store_id, paid_amount, refund_amount, paid_orders, refund_orders, paid_txn_count, refund_txn_count, paid_customers, aggregated_at)
                SELECT
                    DATE(p.paid_at) AS report_date,
                    o.store_id,
                    SUM(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
                    SUM(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
                    COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
                    COUNT(DISTINCT CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN p.order_id ELSE NULL END) AS refund_orders,
                    COUNT(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN 1 ELSE NULL END) AS paid_txn_count,
                    COUNT(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN 1 ELSE NULL END) AS refund_txn_count,
                    COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN o.customer_id ELSE NULL END) AS paid_customers,
                    :aggregated_at
                FROM qiling_order_payments p
                INNER JOIN qiling_orders o ON o.id = p.order_id
                WHERE p.paid_at >= :from_at
                  AND p.paid_at <= :to_at
                  AND p.status IN (\'paid\', \'refunded\')';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(p.paid_at), o.store_id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function syncStoreDailyNewCustomers(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_store
                (report_date, store_id, new_customers, aggregated_at)
                SELECT
                    DATE(c.created_at) AS report_date,
                    c.store_id,
                    COUNT(*) AS new_customers,
                    :aggregated_at
                FROM qiling_customers c
                WHERE c.created_at >= :from_at
                  AND c.created_at <= :to_at';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND c.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(c.created_at), c.store_id
                  ON DUPLICATE KEY UPDATE
                    new_customers = VALUES(new_customers),
                    aggregated_at = VALUES(aggregated_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function syncStoreDailyAppointments(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_store
                (report_date, store_id, appointments_total, appointments_completed, appointments_cancelled, appointments_no_show, aggregated_at)
                SELECT
                    DATE(a.start_at) AS report_date,
                    a.store_id,
                    COUNT(*) AS appointments_total,
                    SUM(CASE WHEN a.status = \'completed\' THEN 1 ELSE 0 END) AS appointments_completed,
                    SUM(CASE WHEN a.status = \'cancelled\' THEN 1 ELSE 0 END) AS appointments_cancelled,
                    SUM(CASE WHEN a.status = \'no_show\' THEN 1 ELSE 0 END) AS appointments_no_show,
                    :aggregated_at
                FROM qiling_appointments a
                WHERE a.start_at >= :from_at
                  AND a.start_at <= :to_at';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND a.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(a.start_at), a.store_id
                  ON DUPLICATE KEY UPDATE
                    appointments_total = VALUES(appointments_total),
                    appointments_completed = VALUES(appointments_completed),
                    appointments_cancelled = VALUES(appointments_cancelled),
                    appointments_no_show = VALUES(appointments_no_show),
                    aggregated_at = VALUES(aggregated_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function syncStoreDailyCardConsumes(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_store
                (report_date, store_id, card_consumed_sessions, aggregated_at)
                SELECT
                    DATE(l.created_at) AS report_date,
                    mc.store_id,
                    COALESCE(SUM(CASE WHEN l.delta_sessions < 0 THEN ABS(l.delta_sessions) ELSE 0 END), 0) AS card_consumed_sessions,
                    :aggregated_at
                FROM qiling_member_card_logs l
                INNER JOIN qiling_member_cards mc ON mc.id = l.member_card_id
                WHERE l.created_at >= :from_at
                  AND l.created_at <= :to_at';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND mc.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(l.created_at), mc.store_id
                  ON DUPLICATE KEY UPDATE
                    card_consumed_sessions = VALUES(card_consumed_sessions),
                    aggregated_at = VALUES(aggregated_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function syncChannelDailyNewCustomers(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_channel
                (report_date, store_id, source_channel, new_customers, aggregated_at)
                SELECT
                    DATE(c.created_at) AS report_date,
                    c.store_id,
                    COALESCE(NULLIF(TRIM(c.source_channel), \'\'), \'未标记\') AS source_channel,
                    COUNT(*) AS new_customers,
                    :aggregated_at
                FROM qiling_customers c
                WHERE c.created_at >= :from_at
                  AND c.created_at <= :to_at';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND c.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(c.created_at), c.store_id, COALESCE(NULLIF(TRIM(c.source_channel), \'\'), \'未标记\')
                  ON DUPLICATE KEY UPDATE
                    new_customers = VALUES(new_customers),
                    aggregated_at = VALUES(aggregated_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function syncChannelDailySales(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_channel
                (report_date, store_id, source_channel, paid_customers, paid_orders, paid_amount, refund_amount, aggregated_at)
                SELECT
                    DATE(p.paid_at) AS report_date,
                    o.store_id,
                    COALESCE(NULLIF(TRIM(c.source_channel), \'\'), \'未标记\') AS source_channel,
                    COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN o.customer_id ELSE NULL END) AS paid_customers,
                    COUNT(DISTINCT CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
                    SUM(CASE WHEN p.status = \'paid\' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
                    SUM(CASE WHEN p.status = \'refunded\' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
                    :aggregated_at
                FROM qiling_order_payments p
                INNER JOIN qiling_orders o ON o.id = p.order_id
                INNER JOIN qiling_customers c ON c.id = o.customer_id
                WHERE p.paid_at >= :from_at
                  AND p.paid_at <= :to_at
                  AND p.status IN (\'paid\', \'refunded\')';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(p.paid_at), o.store_id, COALESCE(NULLIF(TRIM(c.source_channel), \'\'), \'未标记\')
                  ON DUPLICATE KEY UPDATE
                    paid_customers = VALUES(paid_customers),
                    paid_orders = VALUES(paid_orders),
                    paid_amount = VALUES(paid_amount),
                    refund_amount = VALUES(refund_amount),
                    aggregated_at = VALUES(aggregated_at)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function syncServiceDaily(PDO $pdo, string $fromAt, string $toAt, string $aggregatedAt, ?int $storeId): int
    {
        $sql = 'INSERT INTO qiling_report_daily_service
                (report_date, store_id, item_type, item_ref_id, item_name, total_qty, item_lines, order_count, sales_amount, commission_amount, aggregated_at)
                SELECT
                    DATE(o.paid_at) AS report_date,
                    o.store_id,
                    oi.item_type,
                    COALESCE(oi.item_ref_id, 0) AS item_ref_id,
                    oi.item_name,
                    SUM(oi.qty) AS total_qty,
                    COUNT(oi.id) AS item_lines,
                    COUNT(DISTINCT oi.order_id) AS order_count,
                    SUM(oi.final_amount) AS sales_amount,
                    SUM(oi.commission_amount) AS commission_amount,
                    :aggregated_at
                FROM qiling_orders o
                INNER JOIN qiling_order_items oi ON oi.order_id = o.id
                WHERE o.status = :status_paid
                  AND o.paid_at >= :from_at
                  AND o.paid_at <= :to_at';
        $params = [
            'aggregated_at' => $aggregatedAt,
            'status_paid' => 'paid',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY DATE(o.paid_at), o.store_id, oi.item_type, COALESCE(oi.item_ref_id, 0), oi.item_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function upsertMarks(PDO $pdo, string $fromDate, string $toDate, ?int $storeId, string $aggregatedAt): int
    {
        $storeMarkId = $storeId ?? 0;
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_report_aggregate_marks (report_date, store_id, aggregated_at)
             VALUES (:report_date, :store_id, :aggregated_at)
             ON DUPLICATE KEY UPDATE aggregated_at = VALUES(aggregated_at)'
        );

        $days = self::iterateDays($fromDate, $toDate);
        $affected = 0;
        foreach ($days as $reportDate) {
            $stmt->execute([
                'report_date' => $reportDate,
                'store_id' => $storeMarkId,
                'aggregated_at' => $aggregatedAt,
            ]);
            $affected += $stmt->rowCount();
        }

        return $affected;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function normalizeDateRange(string $dateFrom, string $dateTo): array
    {
        $from = trim($dateFrom);
        $to = trim($dateTo);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new \RuntimeException('invalid date range');
        }

        $fromTs = strtotime($from . ' 00:00:00');
        $toTs = strtotime($to . ' 00:00:00');
        if ($fromTs === false || $toTs === false) {
            throw new \RuntimeException('invalid date range');
        }
        if ($fromTs > $toTs) {
            return [$to, $from];
        }
        return [$from, $to];
    }

    private static function daysBetweenInclusive(string $fromDate, string $toDate): int
    {
        $fromTs = strtotime($fromDate . ' 00:00:00');
        $toTs = strtotime($toDate . ' 00:00:00');
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return 0;
        }
        return (int) floor(($toTs - $fromTs) / 86400) + 1;
    }

    /**
     * @return array<int, string>
     */
    private static function iterateDays(string $fromDate, string $toDate): array
    {
        $days = [];
        $cursor = new \DateTimeImmutable($fromDate);
        $end = new \DateTimeImmutable($toDate);
        while ($cursor->getTimestamp() <= $end->getTimestamp()) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $days;
    }
}

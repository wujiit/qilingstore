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

final class FinanceReconciliationController
{
    public static function overview(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $requestedStoreId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $storeId = $storeId !== null ? $storeId : 0;

        $settlementDate = self::resolveSettlementDate($_GET['date'] ?? '');
        [$fromAt, $toAt] = self::settlementDateRange($settlementDate);
        $pdo = Database::pdo();

        $summary = self::calculateSummary($pdo, $storeId, $fromAt, $toAt);
        $channels = self::calculateChannels($pdo, $settlementDate, $storeId, $fromAt, $toAt);
        $exceptions = self::exceptionSnapshot($pdo, $settlementDate, $storeId, 120);
        $settlement = self::findSettlement($pdo, $settlementDate, $storeId);

        Response::json([
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
            'settlement' => $settlement,
            'summary' => $summary,
            'channels' => $channels,
            'exceptions' => $exceptions,
        ]);
    }

    public static function closeDay(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $settlementDate = self::resolveSettlementDate($data['date'] ?? '');
        $requestedStoreId = Request::int($data, 'store_id', 0);
        $storeId = DataScope::resolveInputStoreId($user, $requestedStoreId, true);
        $note = Request::str($data, 'note');
        [$fromAt, $toAt] = self::settlementDateRange($settlementDate);
        $pdo = Database::pdo();
        $actorUserId = (int) ($user['id'] ?? 0);
        $now = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $summary = self::calculateSummary($pdo, $storeId, $fromAt, $toAt);
            $channels = self::calculateChannels($pdo, $settlementDate, $storeId, $fromAt, $toAt);
            $generated = self::regenerateChannelDiffExceptions($pdo, $settlementDate, $storeId, $channels, $actorUserId, $now);
            $exceptions = self::exceptionSnapshot($pdo, $settlementDate, $storeId, 120);
            $openExceptionCountMap = self::fetchOpenExceptionCountByChannel($pdo, $settlementDate, $storeId);
            foreach ($channels as &$channelRow) {
                $channelKey = self::normalizeChannel((string) ($channelRow['channel'] ?? ''));
                $channelRow['exception_count'] = (int) ($openExceptionCountMap[$channelKey] ?? 0);
            }
            unset($channelRow);

            $summary['channel_diff_amount'] = round(array_reduce($channels, static function (float $carry, array $row): float {
                return $carry + abs((float) ($row['diff_amount'] ?? 0));
            }, 0.0), 2);
            $summary['exception_count'] = (int) ($exceptions['summary']['open_count'] ?? 0);

            self::persistSettlement($pdo, $settlementDate, $storeId, $summary, $note, $actorUserId, $now);
            self::persistChannelItems($pdo, $settlementDate, $storeId, $channels, $now);

            Audit::log($actorUserId, 'finance.reconciliation.close_day', 'finance_settlement', 0, 'Close finance settlement day', [
                'settlement_date' => $settlementDate,
                'store_id' => $storeId,
                'summary' => $summary,
                'generated_diff_exceptions' => $generated,
            ]);

            $pdo->commit();
            Response::json([
                'settlement_date' => $settlementDate,
                'store_id' => $storeId,
                'summary' => $summary,
                'channels' => $channels,
                'exceptions' => $exceptions,
                'generated_diff_exceptions' => $generated,
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('close settlement day failed', $e);
        }
    }

    public static function createException(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $settlementDate = self::resolveSettlementDate($data['date'] ?? '');
        $requestedStoreId = Request::int($data, 'store_id', 0);
        $storeId = DataScope::resolveInputStoreId($user, $requestedStoreId, true);
        $channel = self::normalizeChannel(Request::str($data, 'channel'));
        $detail = Request::str($data, 'detail');
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $orderId = Request::int($data, 'order_id', 0);
        $exceptionType = Request::str($data, 'exception_type', 'manual');
        $exceptionType = trim($exceptionType) !== '' ? trim($exceptionType) : 'manual';
        if ($detail === '') {
            Response::json(['message' => 'detail is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_finance_exceptions
             (settlement_date, store_id, channel, exception_type, order_id, amount, detail, status, created_at, updated_at)
             VALUES
             (:settlement_date, :store_id, :channel, :exception_type, :order_id, :amount, :detail, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
            'channel' => $channel,
            'exception_type' => $exceptionType,
            'order_id' => $orderId > 0 ? $orderId : null,
            'amount' => $amount,
            'detail' => $detail,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log((int) ($user['id'] ?? 0), 'finance.exception.create', 'finance_exception', (int) $pdo->lastInsertId(), 'Create finance exception', [
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
            'channel' => $channel,
            'amount' => $amount,
            'exception_type' => $exceptionType,
        ]);

        Response::json([
            'exception_id' => (int) $pdo->lastInsertId(),
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
            'status' => 'open',
        ], 201);
    }

    public static function resolveException(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();
        $exceptionId = Request::int($data, 'exception_id', 0);
        if ($exceptionId <= 0) {
            Response::json(['message' => 'exception_id is required'], 422);
            return;
        }

        $status = Request::str($data, 'status', 'resolved');
        if (!in_array($status, ['open', 'resolved', 'ignored'], true)) {
            Response::json(['message' => 'invalid status'], 422);
            return;
        }
        $resolutionNote = Request::str($data, 'resolution_note');

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, store_id, status
                 FROM qiling_finance_exceptions
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $exceptionId]);
            $exception = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($exception)) {
                $pdo->rollBack();
                Response::json(['message' => 'exception not found'], 404);
                return;
            }

            DataScope::assertGlobalStoreAdminOnly($user, (int) ($exception['store_id'] ?? 0));
            $now = gmdate('Y-m-d H:i:s');
            $resolvedBy = in_array($status, ['resolved', 'ignored'], true) ? (int) ($user['id'] ?? 0) : null;
            $resolvedAt = in_array($status, ['resolved', 'ignored'], true) ? $now : null;

            $updateStmt = $pdo->prepare(
                'UPDATE qiling_finance_exceptions
                 SET status = :status,
                     resolved_by = :resolved_by,
                     resolved_at = :resolved_at,
                     resolution_note = :resolution_note,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'status' => $status,
                'resolved_by' => $resolvedBy,
                'resolved_at' => $resolvedAt,
                'resolution_note' => $resolutionNote,
                'updated_at' => $now,
                'id' => $exceptionId,
            ]);

            Audit::log((int) ($user['id'] ?? 0), 'finance.exception.resolve', 'finance_exception', $exceptionId, 'Update finance exception status', [
                'status' => $status,
                'resolution_note' => $resolutionNote,
            ]);

            $pdo->commit();
            Response::json([
                'exception_id' => $exceptionId,
                'status' => $status,
                'resolved_by' => $resolvedBy,
                'resolved_at' => $resolvedAt,
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('resolve finance exception failed', $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function calculateSummary(PDO $pdo, int $storeId, string $fromAt, string $toAt): array
    {
        $sql = 'SELECT
                    COALESCE(SUM(CASE WHEN p.amount > 0 THEN p.amount ELSE 0 END), 0) AS cash_in_amount,
                    COALESCE(SUM(CASE WHEN p.amount < 0 THEN ABS(p.amount) ELSE 0 END), 0) AS refund_out_amount,
                    COALESCE(SUM(CASE WHEN p.pay_method IN (\'wechat\', \'alipay\') AND p.amount > 0 THEN p.amount ELSE 0 END), 0) AS online_in_amount,
                    COALESCE(SUM(CASE WHEN p.pay_method NOT IN (\'wechat\', \'alipay\') AND p.amount > 0 THEN p.amount ELSE 0 END), 0) AS offline_in_amount,
                    COALESCE(SUM(CASE WHEN p.amount > 0 THEN 1 ELSE 0 END), 0) AS payment_count,
                    COALESCE(SUM(CASE WHEN p.amount < 0 THEN 1 ELSE 0 END), 0) AS refund_count
                FROM qiling_order_payments p
                INNER JOIN qiling_orders o ON o.id = p.order_id
                WHERE p.paid_at >= :from_at
                  AND p.paid_at < :to_at
                  AND p.status IN (\'paid\', \'refunded\')';
        $params = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId > 0) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $row = [];
        }

        $cashInAmount = round((float) ($row['cash_in_amount'] ?? 0), 2);
        $refundOutAmount = round((float) ($row['refund_out_amount'] ?? 0), 2);
        $onlineInAmount = round((float) ($row['online_in_amount'] ?? 0), 2);
        $offlineInAmount = round((float) ($row['offline_in_amount'] ?? 0), 2);

        return [
            'cash_in_amount' => $cashInAmount,
            'refund_out_amount' => $refundOutAmount,
            'net_amount' => round($cashInAmount - $refundOutAmount, 2),
            'online_in_amount' => $onlineInAmount,
            'offline_in_amount' => $offlineInAmount,
            'channel_diff_amount' => 0.0,
            'payment_count' => (int) ($row['payment_count'] ?? 0),
            'refund_count' => (int) ($row['refund_count'] ?? 0),
            'exception_count' => 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function calculateChannels(PDO $pdo, string $settlementDate, int $storeId, string $fromAt, string $toAt): array
    {
        $ledgerMap = [];
        $ledgerSql = 'SELECT
                        p.pay_method AS channel,
                        COALESCE(SUM(p.amount), 0) AS ledger_amount,
                        COALESCE(SUM(CASE WHEN p.amount > 0 THEN 1 ELSE 0 END), 0) AS payment_count,
                        COALESCE(SUM(CASE WHEN p.amount < 0 THEN 1 ELSE 0 END), 0) AS refund_count
                      FROM qiling_order_payments p
                      INNER JOIN qiling_orders o ON o.id = p.order_id
                      WHERE p.paid_at >= :from_at
                        AND p.paid_at < :to_at
                        AND p.status IN (\'paid\', \'refunded\')';
        $ledgerParams = [
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId > 0) {
            $ledgerSql .= ' AND o.store_id = :store_id';
            $ledgerParams['store_id'] = $storeId;
        }
        $ledgerSql .= ' GROUP BY p.pay_method';

        $ledgerStmt = $pdo->prepare($ledgerSql);
        $ledgerStmt->execute($ledgerParams);
        $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($ledgerRows)) {
            foreach ($ledgerRows as $row) {
                $channel = self::normalizeChannel((string) ($row['channel'] ?? ''));
                if ($channel === '') {
                    continue;
                }
                $ledgerMap[$channel] = [
                    'ledger_amount' => round((float) ($row['ledger_amount'] ?? 0), 2),
                    'payment_count' => (int) ($row['payment_count'] ?? 0),
                    'refund_count' => (int) ($row['refund_count'] ?? 0),
                ];
            }
        }

        $gatewayPayMap = self::fetchGatewayPayMap($pdo, $storeId, $fromAt, $toAt);
        $gatewayRefundMap = self::fetchGatewayRefundMap($pdo, $storeId, $fromAt, $toAt);
        $exceptionCountMap = self::fetchOpenExceptionCountByChannel($pdo, $settlementDate, $storeId);

        $channelSet = [];
        foreach (array_keys($ledgerMap) as $channel) {
            $channelSet[$channel] = true;
        }
        foreach (array_keys($gatewayPayMap) as $channel) {
            $channelSet[$channel] = true;
        }
        foreach (array_keys($gatewayRefundMap) as $channel) {
            $channelSet[$channel] = true;
        }

        if (empty($channelSet)) {
            $channelSet['cash'] = true;
            $channelSet['wechat'] = true;
            $channelSet['alipay'] = true;
        }

        $rows = [];
        foreach (array_keys($channelSet) as $channel) {
            $ledgerAmount = (float) ($ledgerMap[$channel]['ledger_amount'] ?? 0);
            $gatewayPaid = (float) ($gatewayPayMap[$channel]['paid_amount'] ?? 0);
            $gatewayRefund = (float) ($gatewayRefundMap[$channel]['refund_amount'] ?? 0);
            $gatewayAmount = round($gatewayPaid - $gatewayRefund, 2);
            $diffAmount = round($ledgerAmount - $gatewayAmount, 2);

            $rows[] = [
                'channel' => $channel,
                'ledger_amount' => round($ledgerAmount, 2),
                'gateway_amount' => $gatewayAmount,
                'diff_amount' => $diffAmount,
                'payment_count' => (int) ($ledgerMap[$channel]['payment_count'] ?? 0),
                'refund_count' => (int) ($ledgerMap[$channel]['refund_count'] ?? 0),
                'exception_count' => (int) ($exceptionCountMap[$channel] ?? 0),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aAbs = abs((float) ($a['diff_amount'] ?? 0));
            $bAbs = abs((float) ($b['diff_amount'] ?? 0));
            if ($aAbs === $bAbs) {
                return strcmp((string) ($a['channel'] ?? ''), (string) ($b['channel'] ?? ''));
            }
            return $aAbs > $bAbs ? -1 : 1;
        });

        return $rows;
    }

    /**
     * @return array<string, array{paid_amount:float}>
     */
    private static function fetchGatewayPayMap(PDO $pdo, int $storeId, string $fromAt, string $toAt): array
    {
        $sql = 'SELECT
                    op.channel,
                    COALESCE(SUM(op.amount), 0) AS paid_amount
                FROM qiling_online_payments op
                INNER JOIN qiling_orders o ON o.id = op.order_id
                WHERE op.status = :status
                  AND op.paid_at >= :from_at
                  AND op.paid_at < :to_at';
        $params = [
            'status' => 'success',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId > 0) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY op.channel';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $channel = self::normalizeChannel((string) ($row['channel'] ?? ''));
                if ($channel === '') {
                    continue;
                }
                $map[$channel] = [
                    'paid_amount' => round((float) ($row['paid_amount'] ?? 0), 2),
                ];
            }
        }
        return $map;
    }

    /**
     * @return array<string, array{refund_amount:float}>
     */
    private static function fetchGatewayRefundMap(PDO $pdo, int $storeId, string $fromAt, string $toAt): array
    {
        $sql = 'SELECT
                    r.channel,
                    COALESCE(SUM(r.refund_amount), 0) AS refund_amount
                FROM qiling_online_payment_refunds r
                INNER JOIN qiling_orders o ON o.id = r.order_id
                WHERE r.status = :status
                  AND r.refunded_at >= :from_at
                  AND r.refunded_at < :to_at';
        $params = [
            'status' => 'success',
            'from_at' => $fromAt,
            'to_at' => $toAt,
        ];
        if ($storeId > 0) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY r.channel';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $channel = self::normalizeChannel((string) ($row['channel'] ?? ''));
                if ($channel === '') {
                    continue;
                }
                $map[$channel] = [
                    'refund_amount' => round((float) ($row['refund_amount'] ?? 0), 2),
                ];
            }
        }
        return $map;
    }

    /**
     * @return array<string, int>
     */
    private static function fetchOpenExceptionCountByChannel(PDO $pdo, string $settlementDate, int $storeId): array
    {
        $sql = 'SELECT channel, COUNT(*) AS cnt
                FROM qiling_finance_exceptions
                WHERE settlement_date = :settlement_date
                  AND status = :status';
        $params = [
            'settlement_date' => $settlementDate,
            'status' => 'open',
        ];
        if ($storeId > 0) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        $sql .= ' GROUP BY channel';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $channel = self::normalizeChannel((string) ($row['channel'] ?? ''));
                if ($channel === '') {
                    continue;
                }
                $map[$channel] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private static function exceptionSnapshot(PDO $pdo, string $settlementDate, int $storeId, int $limit): array
    {
        $limit = max(1, min($limit, 500));
        $summarySql = 'SELECT
                        COALESCE(SUM(CASE WHEN status = \'open\' THEN 1 ELSE 0 END), 0) AS open_count,
                        COALESCE(SUM(CASE WHEN status = \'resolved\' THEN 1 ELSE 0 END), 0) AS resolved_count,
                        COALESCE(SUM(CASE WHEN status = \'ignored\' THEN 1 ELSE 0 END), 0) AS ignored_count,
                        COALESCE(SUM(CASE WHEN status = \'open\' THEN amount ELSE 0 END), 0) AS open_amount
                       FROM qiling_finance_exceptions
                       WHERE settlement_date = :settlement_date';
        $summaryParams = [
            'settlement_date' => $settlementDate,
        ];
        if ($storeId > 0) {
            $summarySql .= ' AND store_id = :store_id';
            $summaryParams['store_id'] = $storeId;
        }
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($summaryParams);
        $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($summaryRow)) {
            $summaryRow = [];
        }

        $listSql = 'SELECT e.*, o.order_no
                    FROM qiling_finance_exceptions e
                    LEFT JOIN qiling_orders o ON o.id = e.order_id
                    WHERE e.settlement_date = :settlement_date';
        $listParams = [
            'settlement_date' => $settlementDate,
        ];
        if ($storeId > 0) {
            $listSql .= ' AND e.store_id = :store_id';
            $listParams['store_id'] = $storeId;
        }
        $listSql .= ' ORDER BY CASE WHEN e.status = \'open\' THEN 0 WHEN e.status = \'resolved\' THEN 1 ELSE 2 END ASC, e.id DESC
                      LIMIT ' . $limit;
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($listParams);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        return [
            'summary' => [
                'open_count' => (int) ($summaryRow['open_count'] ?? 0),
                'resolved_count' => (int) ($summaryRow['resolved_count'] ?? 0),
                'ignored_count' => (int) ($summaryRow['ignored_count'] ?? 0),
                'open_amount' => round((float) ($summaryRow['open_amount'] ?? 0), 2),
            ],
            'items' => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     */
    private static function regenerateChannelDiffExceptions(PDO $pdo, string $settlementDate, int $storeId, array $channels, int $actorUserId, string $now): int
    {
        $resolveStmt = $pdo->prepare(
            'UPDATE qiling_finance_exceptions
             SET status = :status,
                 resolved_by = :resolved_by,
                 resolved_at = :resolved_at,
                 resolution_note = :resolution_note,
                 updated_at = :updated_at
             WHERE settlement_date = :settlement_date
               AND store_id = :store_id
               AND exception_type = :exception_type
               AND status = :current_status'
        );
        $resolveStmt->execute([
            'status' => 'resolved',
            'resolved_by' => $actorUserId,
            'resolved_at' => $now,
            'resolution_note' => '系统复核自动关闭',
            'updated_at' => $now,
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
            'exception_type' => 'channel_diff',
            'current_status' => 'open',
        ]);

        $upsertStmt = $pdo->prepare(
            'INSERT INTO qiling_finance_exceptions
             (settlement_date, store_id, channel, exception_type, order_id, amount, detail, status, resolved_by, resolved_at, resolution_note, created_at, updated_at)
             VALUES
             (:settlement_date, :store_id, :channel, :exception_type, :order_id, :amount, :detail, :status, NULL, NULL, \'\', :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                amount = VALUES(amount),
                detail = VALUES(detail),
                status = VALUES(status),
                resolved_by = NULL,
                resolved_at = NULL,
                resolution_note = \'\',
                updated_at = VALUES(updated_at)'
        );

        $count = 0;
        foreach ($channels as $channelRow) {
            $channel = self::normalizeChannel((string) ($channelRow['channel'] ?? ''));
            $diffAmount = round((float) ($channelRow['diff_amount'] ?? 0), 2);
            if ($channel === '' || abs($diffAmount) < 0.01) {
                continue;
            }

            $upsertStmt->execute([
                'settlement_date' => $settlementDate,
                'store_id' => $storeId,
                'channel' => $channel,
                'exception_type' => 'channel_diff',
                'order_id' => 0,
                'amount' => $diffAmount,
                'detail' => sprintf(
                    '渠道差异：账务 %.2f，网关 %.2f，差额 %.2f',
                    (float) ($channelRow['ledger_amount'] ?? 0),
                    (float) ($channelRow['gateway_amount'] ?? 0),
                    $diffAmount
                ),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function persistSettlement(PDO $pdo, string $settlementDate, int $storeId, array $summary, string $note, int $actorUserId, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_finance_daily_settlements
             (settlement_date, store_id, cash_in_amount, refund_out_amount, net_amount, online_in_amount, offline_in_amount, channel_diff_amount, payment_count, refund_count, exception_count, status, note, closed_by, closed_at, created_at, updated_at)
             VALUES
             (:settlement_date, :store_id, :cash_in_amount, :refund_out_amount, :net_amount, :online_in_amount, :offline_in_amount, :channel_diff_amount, :payment_count, :refund_count, :exception_count, :status, :note, :closed_by, :closed_at, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                cash_in_amount = VALUES(cash_in_amount),
                refund_out_amount = VALUES(refund_out_amount),
                net_amount = VALUES(net_amount),
                online_in_amount = VALUES(online_in_amount),
                offline_in_amount = VALUES(offline_in_amount),
                channel_diff_amount = VALUES(channel_diff_amount),
                payment_count = VALUES(payment_count),
                refund_count = VALUES(refund_count),
                exception_count = VALUES(exception_count),
                status = VALUES(status),
                note = VALUES(note),
                closed_by = VALUES(closed_by),
                closed_at = VALUES(closed_at),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
            'cash_in_amount' => round((float) ($summary['cash_in_amount'] ?? 0), 2),
            'refund_out_amount' => round((float) ($summary['refund_out_amount'] ?? 0), 2),
            'net_amount' => round((float) ($summary['net_amount'] ?? 0), 2),
            'online_in_amount' => round((float) ($summary['online_in_amount'] ?? 0), 2),
            'offline_in_amount' => round((float) ($summary['offline_in_amount'] ?? 0), 2),
            'channel_diff_amount' => round((float) ($summary['channel_diff_amount'] ?? 0), 2),
            'payment_count' => (int) ($summary['payment_count'] ?? 0),
            'refund_count' => (int) ($summary['refund_count'] ?? 0),
            'exception_count' => (int) ($summary['exception_count'] ?? 0),
            'status' => 'closed',
            'note' => $note,
            'closed_by' => $actorUserId,
            'closed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     */
    private static function persistChannelItems(PDO $pdo, string $settlementDate, int $storeId, array $channels, string $now): void
    {
        $deleteStmt = $pdo->prepare(
            'DELETE FROM qiling_finance_reconciliation_items
             WHERE settlement_date = :settlement_date
               AND store_id = :store_id'
        );
        $deleteStmt->execute([
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
        ]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO qiling_finance_reconciliation_items
             (settlement_date, store_id, channel, ledger_amount, gateway_amount, diff_amount, payment_count, refund_count, exception_count, created_at, updated_at)
             VALUES
             (:settlement_date, :store_id, :channel, :ledger_amount, :gateway_amount, :diff_amount, :payment_count, :refund_count, :exception_count, :created_at, :updated_at)'
        );
        foreach ($channels as $row) {
            $channel = self::normalizeChannel((string) ($row['channel'] ?? ''));
            if ($channel === '') {
                continue;
            }
            $insertStmt->execute([
                'settlement_date' => $settlementDate,
                'store_id' => $storeId,
                'channel' => $channel,
                'ledger_amount' => round((float) ($row['ledger_amount'] ?? 0), 2),
                'gateway_amount' => round((float) ($row['gateway_amount'] ?? 0), 2),
                'diff_amount' => round((float) ($row['diff_amount'] ?? 0), 2),
                'payment_count' => (int) ($row['payment_count'] ?? 0),
                'refund_count' => (int) ($row['refund_count'] ?? 0),
                'exception_count' => (int) ($row['exception_count'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findSettlement(PDO $pdo, string $settlementDate, int $storeId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_finance_daily_settlements
             WHERE settlement_date = :settlement_date
               AND store_id = :store_id
             LIMIT 1'
        );
        $stmt->execute([
            'settlement_date' => $settlementDate,
            'store_id' => $storeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function settlementDateRange(string $settlementDate): array
    {
        $start = $settlementDate . ' 00:00:00';
        $end = (new \DateTimeImmutable($settlementDate . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
        return [$start, $end];
    }

    private static function resolveSettlementDate(mixed $value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
                return $trimmed;
            }
        }
        return gmdate('Y-m-d');
    }

    private static function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }
        if (in_array($channel, ['wx', 'wechatpay'], true)) {
            return 'wechat';
        }
        if (in_array($channel, ['ali', 'alipaypay'], true)) {
            return 'alipay';
        }
        if (in_array($channel, ['cash', 'wechat', 'alipay', 'card', 'bank', 'other'], true)) {
            return $channel;
        }
        return 'other';
    }
}

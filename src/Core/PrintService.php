<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class PrintService
{
    /**
     * @return array<string, mixed>|null
     */
    public static function createOrderReceiptJob(
        PDO $pdo,
        int $orderId,
        int $storeId,
        int $operatorUserId,
        ?int $printerId = null
    ): ?array {
        if ($orderId <= 0 || $storeId <= 0) {
            return null;
        }

        $printer = self::resolvePrinter($pdo, $storeId, $printerId);
        if (!is_array($printer)) {
            return null;
        }

        $content = self::buildOrderReceiptContent($pdo, $orderId);
        if ($content === '') {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');
        $jobNo = self::generateJobNo($pdo);

        $insert = $pdo->prepare(
            'INSERT INTO qiling_print_jobs
             (store_id, printer_id, job_no, business_type, business_id, content, status, response_body, error_message, operator_user_id, created_at, updated_at)
             VALUES
             (:store_id, :printer_id, :job_no, :business_type, :business_id, :content, :status, :response_body, :error_message, :operator_user_id, :created_at, :updated_at)'
        );
        $insert->execute([
            'store_id' => $storeId,
            'printer_id' => (int) ($printer['id'] ?? 0),
            'job_no' => $jobNo,
            'business_type' => 'order_receipt',
            'business_id' => $orderId,
            'content' => $content,
            'status' => 'pending',
            'response_body' => null,
            'error_message' => '',
            'operator_user_id' => $operatorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'print_job_id' => (int) $pdo->lastInsertId(),
            'job_no' => $jobNo,
            'printer_id' => (int) ($printer['id'] ?? 0),
            'store_id' => $storeId,
            'business_type' => 'order_receipt',
            'business_id' => $orderId,
            'status' => 'pending',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function createManualJob(
        PDO $pdo,
        int $storeId,
        int $operatorUserId,
        string $content,
        string $businessType = 'manual',
        int $businessId = 0,
        ?int $printerId = null
    ): array {
        if ($storeId <= 0) {
            throw new \RuntimeException('store_id is required');
        }

        $content = trim($content);
        if ($content === '') {
            throw new \RuntimeException('content is required for manual print job');
        }

        $resolvedPrinterId = null;
        if ($printerId !== null && $printerId > 0) {
            $printerStmt = $pdo->prepare(
                'SELECT id, store_id
                 FROM qiling_printers
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $printerStmt->execute(['id' => $printerId]);
            $printer = $printerStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($printer)) {
                throw new \RuntimeException('printer not found');
            }

            $printerStoreId = (int) ($printer['store_id'] ?? 0);
            if ($printerStoreId > 0 && $printerStoreId !== $storeId) {
                throw new \RuntimeException('printer store mismatch');
            }
            $resolvedPrinterId = (int) ($printer['id'] ?? 0);
        }

        $now = gmdate('Y-m-d H:i:s');
        $jobNo = self::generateJobNo($pdo);

        $insert = $pdo->prepare(
            'INSERT INTO qiling_print_jobs
             (store_id, printer_id, job_no, business_type, business_id, content, status, response_body, error_message, operator_user_id, created_at, updated_at)
             VALUES
             (:store_id, :printer_id, :job_no, :business_type, :business_id, :content, :status, :response_body, :error_message, :operator_user_id, :created_at, :updated_at)'
        );
        $insert->execute([
            'store_id' => $storeId,
            'printer_id' => $resolvedPrinterId,
            'job_no' => $jobNo,
            'business_type' => $businessType,
            'business_id' => $businessId > 0 ? $businessId : 0,
            'content' => $content,
            'status' => 'pending',
            'response_body' => null,
            'error_message' => '',
            'operator_user_id' => $operatorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'print_job_id' => (int) $pdo->lastInsertId(),
            'job_no' => $jobNo,
            'store_id' => $storeId,
            'printer_id' => $resolvedPrinterId,
            'status' => 'pending',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dispatchPending(
        PDO $pdo,
        int $operatorUserId,
        int $limit = 20,
        ?int $storeId = null,
        ?int $printerId = null
    ): array {
        $limit = max(1, min($limit, 200));

        $sql = 'SELECT id, store_id, printer_id, job_no, business_type, business_id, content
                FROM qiling_print_jobs
                WHERE status = :status';
        $params = ['status' => 'pending'];

        if ($storeId !== null && $storeId > 0) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }
        if ($printerId !== null && $printerId > 0) {
            $sql .= ' AND printer_id = :printer_id';
            $params['printer_id'] = $printerId;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $success = 0;
        $failed = 0;
        $details = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $jobId = (int) ($job['id'] ?? 0);
            $jobPrinterId = (int) ($job['printer_id'] ?? 0);

            $printerStmt = $pdo->prepare(
                'SELECT id, provider, endpoint, api_key, enabled
                 FROM qiling_printers
                 WHERE id = :id
                 LIMIT 1'
            );
            $printerStmt->execute(['id' => $jobPrinterId]);
            $printer = $printerStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($printer) || (int) ($printer['enabled'] ?? 0) !== 1) {
                self::markJobFailed($pdo, $jobId, 'printer unavailable', $operatorUserId, $now);
                $failed++;
                $details[] = [
                    'print_job_id' => $jobId,
                    'job_no' => (string) ($job['job_no'] ?? ''),
                    'status' => 'failed',
                    'error' => 'printer unavailable',
                ];
                continue;
            }

            $provider = trim((string) ($printer['provider'] ?? 'manual'));
            $endpoint = trim((string) ($printer['endpoint'] ?? ''));
            if ($provider === 'manual' || $endpoint === '') {
                self::markJobSuccess($pdo, $jobId, 'manual_print', $operatorUserId, $now);
                $success++;
                $details[] = [
                    'print_job_id' => $jobId,
                    'job_no' => (string) ($job['job_no'] ?? ''),
                    'status' => 'success',
                    'response' => 'manual_print',
                ];
                continue;
            }

            $payload = [
                'job_no' => (string) ($job['job_no'] ?? ''),
                'business_type' => (string) ($job['business_type'] ?? ''),
                'business_id' => (int) ($job['business_id'] ?? 0),
                'content' => (string) ($job['content'] ?? ''),
            ];
            $headers = [];
            $apiKey = trim((string) ($printer['api_key'] ?? ''));
            if ($apiKey !== '') {
                $headers['X-QILING-PRINTER-KEY'] = $apiKey;
            }

            $resp = HttpClient::postJson($endpoint, $payload, $headers, 10);
            $statusCode = (int) ($resp['status_code'] ?? 0);
            $body = (string) ($resp['body'] ?? '');

            if ($statusCode >= 200 && $statusCode < 300) {
                self::markJobSuccess($pdo, $jobId, $body, $operatorUserId, $now);
                $success++;
                $details[] = [
                    'print_job_id' => $jobId,
                    'job_no' => (string) ($job['job_no'] ?? ''),
                    'status' => 'success',
                    'status_code' => $statusCode,
                ];
                continue;
            }

            $err = 'http_' . $statusCode;
            if ($body !== '') {
                $err .= ': ' . substr($body, 0, 300);
            }
            self::markJobFailed($pdo, $jobId, $err, $operatorUserId, $now, $body);
            $failed++;
            $details[] = [
                'print_job_id' => $jobId,
                'job_no' => (string) ($job['job_no'] ?? ''),
                'status' => 'failed',
                'status_code' => $statusCode,
                'error' => $err,
            ];
        }

        return [
            'total' => count($jobs),
            'success' => $success,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolvePrinter(PDO $pdo, int $storeId, ?int $printerId): ?array
    {
        if ($printerId !== null && $printerId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, store_id, provider, endpoint, api_key, enabled
                 FROM qiling_printers
                 WHERE id = :id
                   AND enabled = 1
                 LIMIT 1'
            );
            $stmt->execute(['id' => $printerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT id, store_id, provider, endpoint, api_key, enabled
             FROM qiling_printers
             WHERE enabled = 1
               AND store_id IN (0, :store_id)
             ORDER BY CASE WHEN store_id = :store_id2 THEN 0 ELSE 1 END, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'store_id' => $storeId,
            'store_id2' => $storeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function buildOrderReceiptContent(PDO $pdo, int $orderId): string
    {
        $orderStmt = $pdo->prepare(
            'SELECT o.order_no, o.subtotal_amount, o.discount_amount, o.coupon_amount, o.payable_amount, o.paid_amount, o.paid_at,
                    c.name AS customer_name, c.mobile AS customer_mobile, s.store_name
             FROM qiling_orders o
             INNER JOIN qiling_customers c ON c.id = o.customer_id
             LEFT JOIN qiling_stores s ON s.id = o.store_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $orderStmt->execute(['id' => $orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            return '';
        }

        $itemsStmt = $pdo->prepare(
            'SELECT item_name, qty, unit_price, final_amount
             FROM qiling_order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $itemsStmt->execute(['order_id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentsStmt = $pdo->prepare(
            'SELECT pay_method, amount, paid_at
             FROM qiling_order_payments
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $paymentsStmt->execute(['order_id' => $orderId]);
        $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $lines = [];
        $lines[] = '启灵医美养生门店系统';
        $lines[] = '门店: ' . (string) ($order['store_name'] ?? '');
        $lines[] = '订单: ' . (string) ($order['order_no'] ?? '');
        $lines[] = '客户: ' . (string) ($order['customer_name'] ?? '') . ' ' . (string) ($order['customer_mobile'] ?? '');
        $lines[] = '--------------------------------';
        $lines[] = '项目:';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = sprintf(
                '%s x%d  ¥%.2f',
                (string) ($item['item_name'] ?? ''),
                (int) ($item['qty'] ?? 0),
                (float) ($item['final_amount'] ?? 0)
            );
        }
        $lines[] = '--------------------------------';
        $lines[] = sprintf('小计: ¥%.2f', (float) ($order['subtotal_amount'] ?? 0));
        $lines[] = sprintf('优惠: ¥%.2f', (float) ($order['discount_amount'] ?? 0) + (float) ($order['coupon_amount'] ?? 0));
        $lines[] = sprintf('应收: ¥%.2f', (float) ($order['payable_amount'] ?? 0));
        $lines[] = sprintf('实收: ¥%.2f', (float) ($order['paid_amount'] ?? 0));
        if (!empty($payments)) {
            $lines[] = '支付明细:';
            foreach ($payments as $pay) {
                if (!is_array($pay)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- %s ¥%.2f %s',
                    (string) ($pay['pay_method'] ?? ''),
                    (float) ($pay['amount'] ?? 0),
                    (string) ($pay['paid_at'] ?? '')
                );
            }
        }
        $lines[] = '时间: ' . (string) ($order['paid_at'] ?? gmdate('Y-m-d H:i:s'));

        return implode("\n", $lines);
    }

    private static function markJobSuccess(PDO $pdo, int $jobId, string $responseBody, int $operatorUserId, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE qiling_print_jobs
             SET status = :status,
                 response_body = :response_body,
                 error_message = :error_message,
                 operator_user_id = :operator_user_id,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'success',
            'response_body' => $responseBody,
            'error_message' => '',
            'operator_user_id' => $operatorUserId,
            'updated_at' => $now,
            'id' => $jobId,
        ]);
    }

    private static function markJobFailed(
        PDO $pdo,
        int $jobId,
        string $errorMessage,
        int $operatorUserId,
        string $now,
        ?string $responseBody = null
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE qiling_print_jobs
             SET status = :status,
                 response_body = :response_body,
                 error_message = :error_message,
                 operator_user_id = :operator_user_id,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'failed',
            'response_body' => $responseBody,
            'error_message' => substr($errorMessage, 0, 900),
            'operator_user_id' => $operatorUserId,
            'updated_at' => $now,
            'id' => $jobId,
        ]);
    }

    private static function generateJobNo(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $jobNo = 'QLPJ' . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM qiling_print_jobs WHERE job_no = :job_no LIMIT 1');
            $stmt->execute(['job_no' => $jobNo]);
            if (!$stmt->fetchColumn()) {
                return $jobNo;
            }
        }

        throw new \RuntimeException('failed to generate unique print job no');
    }
}

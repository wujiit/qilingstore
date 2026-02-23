<?php

declare(strict_types=1);

namespace Qiling\Core\Payment;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Config;
use Qiling\Core\OpenGiftService;
use Qiling\Core\PointsService;
use Qiling\Core\PrintService;

final class OnlinePaymentService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, int $actorUserId, array $payload): array
    {
        $orderId = self::toInt($payload['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \RuntimeException('order_id is required');
        }

        $channel = strtolower(trim((string) ($payload['channel'] ?? '')));
        if (!in_array($channel, ['alipay', 'wechat'], true)) {
            throw new \RuntimeException('channel must be alipay or wechat');
        }

        $sceneInput = strtolower(trim((string) ($payload['scene'] ?? '')));
        if ($sceneInput === '') {
            $sceneInput = $channel === 'alipay' ? 'auto' : 'native';
        }
        $scene = $sceneInput;

        $order = self::lockOrder($pdo, $orderId);
        if (!is_array($order)) {
            throw new \RuntimeException('order not found');
        }

        if (in_array((string) $order['status'], ['cancelled', 'refunded'], true)) {
            throw new \RuntimeException('order status does not allow online payment');
        }

        $payableAmount = round((float) $order['payable_amount'], 2);
        $paidAmount = round((float) $order['paid_amount'], 2);
        $outstanding = round(max(0.0, $payableAmount - $paidAmount), 2);
        if ($outstanding <= 0.0) {
            throw new \RuntimeException('order already fully paid');
        }

        $now = gmdate('Y-m-d H:i:s');
        $paymentNo = self::generatePaymentNo($pdo);
        $outTradeNo = $paymentNo;
        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject === '') {
            $subject = '门店订单 ' . (string) ($order['order_no'] ?? $orderId);
        }

        $payCfg = PaymentConfigService::runtime($pdo);
        $notifyBase = rtrim((string) Config::get('APP_URL', ''), '/');
        $alipayNotifyUrl = trim((string) ($payCfg['alipay_notify_url'] ?? ''));
        if ($alipayNotifyUrl === '' && $notifyBase !== '') {
            $alipayNotifyUrl = $notifyBase . '/api/v1/payments/alipay/notify';
        }
        $wechatNotifyUrl = trim((string) ($payCfg['wechat_notify_url'] ?? ''));
        if ($wechatNotifyUrl === '' && $notifyBase !== '') {
            $wechatNotifyUrl = $notifyBase . '/api/v1/payments/wechat/notify';
        }

        $notifyUrl = $channel === 'alipay' ? $alipayNotifyUrl : $wechatNotifyUrl;
        $returnUrl = trim((string) ($payCfg['alipay_return_url'] ?? ''));
        if ($notifyUrl === '') {
            throw new \RuntimeException('payment notify url is required');
        }

        $openid = trim((string) ($payload['openid'] ?? ''));
        $clientIp = trim((string) ($payload['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')));
        if ($clientIp === '') {
            $clientIp = '127.0.0.1';
        }

        $insert = $pdo->prepare(
            'INSERT INTO qiling_online_payments
             (order_id, payment_no, out_trade_no, channel, scene, amount, currency, status, openid, client_ip, created_by, created_at, updated_at)
             VALUES
             (:order_id, :payment_no, :out_trade_no, :channel, :scene, :amount, :currency, :status, :openid, :client_ip, :created_by, :created_at, :updated_at)'
        );
        $insert->execute([
            'order_id' => $orderId,
            'payment_no' => $paymentNo,
            'out_trade_no' => $outTradeNo,
            'channel' => $channel,
            'scene' => $scene,
            'amount' => $outstanding,
            'currency' => 'CNY',
            'status' => 'pending',
            'openid' => $openid,
            'client_ip' => $clientIp,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $onlinePaymentId = (int) $pdo->lastInsertId();

        $gatewayResult = [];
        $sceneUsed = $scene;
        $sceneRequested = $sceneInput;
        $sceneFallbackUsed = 0;
        if ($channel === 'alipay') {
            $client = new AlipayClient($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('alipay not enabled');
            }
            $client->assertConfigured();
            $sceneCandidates = self::alipaySceneCandidates($sceneInput);
            $errors = [];
            foreach ($sceneCandidates as $candidate) {
                $candidateResult = $client->create($candidate, $outTradeNo, $outstanding, $subject, $notifyUrl, $returnUrl);
                if (!empty($candidateResult['ok'])) {
                    $gatewayResult = $candidateResult;
                    $sceneUsed = $candidate;
                    $sceneFallbackUsed = $candidate !== $sceneCandidates[0] ? 1 : 0;
                    break;
                }
                $error = trim((string) ($candidateResult['error'] ?? 'gateway create failed'));
                if ($error !== '') {
                    $errors[] = $error;
                }
            }
            if (empty($gatewayResult['ok'])) {
                $errorText = implode(' | ', $errors);
                throw new \RuntimeException($errorText !== '' ? $errorText : 'gateway create failed');
            }
        } else {
            $client = new WechatPayV2Client($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('wechat not enabled');
            }
            $client->assertConfigured();
            $gatewayResult = $client->create($scene, $outTradeNo, (int) round($outstanding * 100), $subject, $notifyUrl, $clientIp, $openid);
            $sceneUsed = (string) ($gatewayResult['scene'] ?? $scene);
            $sceneRequested = $sceneUsed;
        }

        if (empty($gatewayResult['ok'])) {
            $error = trim((string) ($gatewayResult['error'] ?? 'gateway create failed'));
            $updateFailed = $pdo->prepare(
                'UPDATE qiling_online_payments
                 SET status = :status,
                     gateway_response_json = :gateway_response_json,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateFailed->execute([
                'status' => 'failed',
                'gateway_response_json' => json_encode($gatewayResult['gateway_response'] ?? $gatewayResult, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'id' => $onlinePaymentId,
            ]);
            throw new \RuntimeException($error !== '' ? $error : 'gateway create failed');
        }

        $update = $pdo->prepare(
            'UPDATE qiling_online_payments
             SET scene = :scene,
                 qr_code = :qr_code,
                 pay_url = :pay_url,
                 prepay_id = :prepay_id,
                 gateway_response_json = :gateway_response_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'scene' => $sceneUsed,
            'qr_code' => (string) ($gatewayResult['qr_code'] ?? ''),
            'pay_url' => (string) ($gatewayResult['pay_url'] ?? ''),
            'prepay_id' => (string) ($gatewayResult['prepay_id'] ?? ''),
            'gateway_response_json' => json_encode($gatewayResult['gateway_response'] ?? null, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
            'id' => $onlinePaymentId,
        ]);

        Audit::log($actorUserId, 'online_payment.create', 'online_payment', $onlinePaymentId, 'Create online payment', [
            'order_id' => $orderId,
            'payment_no' => $paymentNo,
            'channel' => $channel,
            'scene_requested' => $sceneRequested,
            'scene_used' => $sceneUsed,
            'scene_fallback_used' => $sceneFallbackUsed,
            'amount' => $outstanding,
        ]);

        return [
            'online_payment_id' => $onlinePaymentId,
            'order_id' => $orderId,
            'order_no' => (string) $order['order_no'],
            'payment_no' => $paymentNo,
            'out_trade_no' => $outTradeNo,
            'channel' => $channel,
            'scene' => $sceneUsed,
            'scene_requested' => $sceneRequested,
            'scene_fallback_used' => $sceneFallbackUsed,
            'amount' => $outstanding,
            'status' => 'pending',
            'pay_url' => (string) ($gatewayResult['pay_url'] ?? ''),
            'qr_code' => (string) ($gatewayResult['qr_code'] ?? ''),
            'prepay_id' => (string) ($gatewayResult['prepay_id'] ?? ''),
            'jsapi' => $gatewayResult['jsapi'] ?? null,
            'app_order_string' => (string) ($gatewayResult['app_order_string'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $notifyPayload
     * @return array<string, mixed>
     */
    public static function markSuccess(
        PDO $pdo,
        string $channel,
        string $outTradeNo,
        string $gatewayTradeNo,
        float $paidAmount,
        array $notifyPayload
    ): array {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_online_payments
             WHERE out_trade_no = :out_trade_no
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['out_trade_no' => $outTradeNo]);
        $online = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($online)) {
            throw new \RuntimeException('online payment not found');
        }

        if ((string) $online['channel'] !== $channel) {
            throw new \RuntimeException('payment channel mismatch');
        }

        $onlineId = (int) $online['id'];
        if ((string) $online['status'] === 'success') {
            return [
                'online_payment_id' => $onlineId,
                'order_id' => (int) $online['order_id'],
                'status' => 'success',
                'idempotent' => true,
            ];
        }

        $expectedAmount = round((float) $online['amount'], 2);
        $paidAmount = round($paidAmount, 2);
        if (abs($expectedAmount - $paidAmount) > 0.01) {
            throw new \RuntimeException('paid amount mismatch');
        }

        $now = gmdate('Y-m-d H:i:s');

        $updateOnline = $pdo->prepare(
            'UPDATE qiling_online_payments
             SET status = :status,
                 gateway_trade_no = :gateway_trade_no,
                 notify_payload_json = :notify_payload_json,
                 paid_at = :paid_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateOnline->execute([
            'status' => 'success',
            'gateway_trade_no' => $gatewayTradeNo,
            'notify_payload_json' => json_encode($notifyPayload, JSON_UNESCAPED_UNICODE),
            'paid_at' => $now,
            'updated_at' => $now,
            'id' => $onlineId,
        ]);

        $orderId = (int) $online['order_id'];
        $order = self::lockOrder($pdo, $orderId);
        if (!is_array($order)) {
            throw new \RuntimeException('order not found');
        }

        $payableAmount = round((float) $order['payable_amount'], 2);
        $paidAmountBefore = round((float) $order['paid_amount'], 2);
        $outstandingBefore = round(max(0.0, $payableAmount - $paidAmountBefore), 2);
        $appliedAmount = round(min($paidAmount, $outstandingBefore), 2);
        $paidAmountAfter = round($paidAmountBefore + $appliedAmount, 2);
        if ($paidAmountAfter > $payableAmount) {
            $paidAmountAfter = $payableAmount;
        }

        $newStatus = $paidAmountAfter >= $payableAmount ? 'paid' : 'partially_paid';
        $sideEffectWarnings = [];
        if ($appliedAmount < $paidAmount) {
            $sideEffectWarnings[] = sprintf(
                'overpayment_ignored: received %.2f, applied %.2f',
                $paidAmount,
                $appliedAmount
            );
        }

        if ($appliedAmount > 0) {
            $insertPayment = $pdo->prepare(
                'INSERT INTO qiling_order_payments
                 (order_id, payment_no, pay_method, amount, status, paid_at, operator_user_id, note, created_at)
                 VALUES
                 (:order_id, :payment_no, :pay_method, :amount, :status, :paid_at, :operator_user_id, :note, :created_at)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), paid_at = VALUES(paid_at), note = VALUES(note)'
            );
            $insertPayment->execute([
                'order_id' => $orderId,
                'payment_no' => (string) $online['payment_no'],
                'pay_method' => $channel,
                'amount' => $appliedAmount,
                'status' => 'paid',
                'paid_at' => $now,
                'operator_user_id' => 0,
                'note' => 'online notify: ' . $outTradeNo,
                'created_at' => $now,
            ]);
        }

        $updateOrder = $pdo->prepare(
            'UPDATE qiling_orders
             SET status = :status,
                 paid_amount = :paid_amount,
                 paid_at = :paid_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateOrder->execute([
            'status' => $newStatus,
            'paid_amount' => $paidAmountAfter,
            'paid_at' => $newStatus === 'paid' ? $now : null,
            'updated_at' => $now,
            'id' => $orderId,
        ]);

        $pointsResult = null;
        $openGiftResult = null;
        $printJob = null;

        if ((string) $order['status'] !== 'paid' && $newStatus === 'paid') {
            $updateCustomer = $pdo->prepare(
                'UPDATE qiling_customers
                 SET total_spent = total_spent + :total_spent,
                     visit_count = visit_count + 1,
                     last_visit_at = :last_visit_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCustomer->execute([
                'total_spent' => $payableAmount,
                'last_visit_at' => $now,
                'updated_at' => $now,
                'id' => (int) $order['customer_id'],
            ]);

            $pointsToGive = (int) floor($payableAmount);
            if ($pointsToGive > 0) {
                try {
                    $pointsResult = PointsService::change(
                        $pdo,
                        0,
                        (int) $order['customer_id'],
                        (int) $order['store_id'],
                        $pointsToGive,
                        'order_paid',
                        '在线支付完成赠送积分',
                        'order',
                        $orderId
                    );
                } catch (\Throwable $t) {
                    $sideEffectWarnings[] = 'points_award_failed: ' . $t->getMessage();
                }
            }

            try {
                $openGiftResult = OpenGiftService::trigger(
                    $pdo,
                    'first_paid',
                    0,
                    (int) $order['customer_id'],
                    (int) $order['store_id'],
                    'order',
                    $orderId
                );
            } catch (\Throwable $t) {
                $sideEffectWarnings[] = 'open_gift_failed: ' . $t->getMessage();
            }

            try {
                $printJob = PrintService::createOrderReceiptJob(
                    $pdo,
                    $orderId,
                    (int) $order['store_id'],
                    0
                );
                if (!is_array($printJob)) {
                    $sideEffectWarnings[] = 'print_job_skipped: no available printer';
                }
            } catch (\Throwable $t) {
                $sideEffectWarnings[] = 'print_job_failed: ' . $t->getMessage();
            }
        }

        if ($newStatus === 'paid') {
            $closeWarnings = self::closeSiblingPendingPayments($pdo, $onlineId, $orderId);
            foreach ($closeWarnings as $warning) {
                $sideEffectWarnings[] = $warning;
            }
        }

        Audit::log(0, 'online_payment.notify_success', 'online_payment', $onlineId, 'Online payment notify success', [
            'order_id' => $orderId,
            'out_trade_no' => $outTradeNo,
            'gateway_trade_no' => $gatewayTradeNo,
            'paid_amount_received' => $paidAmount,
            'paid_amount_applied' => $appliedAmount,
            'order_status' => $newStatus,
            'points_awarded' => is_array($pointsResult) ? (int) ($pointsResult['delta_points'] ?? 0) : 0,
            'open_gift_triggered' => is_array($openGiftResult) && (($openGiftResult['triggered'] ?? false) === true) ? 1 : 0,
            'print_job_id' => is_array($printJob) ? (int) ($printJob['print_job_id'] ?? 0) : 0,
            'warnings' => $sideEffectWarnings,
        ]);

        return [
            'online_payment_id' => $onlineId,
            'order_id' => $orderId,
            'status' => 'success',
            'idempotent' => false,
            'order_status' => $newStatus,
            'paid_amount' => $paidAmountAfter,
            'paid_amount_received' => $paidAmount,
            'paid_amount_applied' => $appliedAmount,
            'outstanding_amount' => round(max(0.0, $payableAmount - $paidAmountAfter), 2),
            'points' => $pointsResult,
            'open_gift' => $openGiftResult,
            'print_job' => $printJob,
            'warnings' => $sideEffectWarnings,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByPaymentNo(PDO $pdo, string $paymentNo): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT op.*, o.order_no, o.store_id, o.customer_id, o.status AS order_status, o.payable_amount, o.paid_amount
             FROM qiling_online_payments op
             INNER JOIN qiling_orders o ON o.id = op.order_id
             WHERE op.payment_no = :payment_no
             LIMIT 1'
        );
        $stmt->execute(['payment_no' => $paymentNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function syncStatus(PDO $pdo, int $actorUserId, string $paymentNo): array
    {
        $online = self::lockOnlinePaymentByPaymentNo($pdo, $paymentNo);
        if (!is_array($online)) {
            throw new \RuntimeException('payment not found');
        }

        $channel = (string) $online['channel'];
        $outTradeNo = (string) $online['out_trade_no'];
        $onlineStatus = (string) $online['status'];
        $queryResult = [];
        $payCfg = PaymentConfigService::runtime($pdo);

        if ($channel === 'alipay') {
            $client = new AlipayClient($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('alipay not enabled');
            }
            $client->assertConfigured();
            $queryResult = $client->query($outTradeNo);
        } elseif ($channel === 'wechat') {
            $client = new WechatPayV2Client($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('wechat not enabled');
            }
            $client->assertConfigured();
            $queryResult = $client->query($outTradeNo);
        } else {
            throw new \RuntimeException('unsupported payment channel');
        }

        $now = gmdate('Y-m-d H:i:s');
        $update = $pdo->prepare(
            'UPDATE qiling_online_payments
             SET gateway_response_json = :gateway_response_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'gateway_response_json' => json_encode($queryResult['gateway_response'] ?? $queryResult, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
            'id' => (int) $online['id'],
        ]);

        if (!empty($queryResult['ok']) && !empty($queryResult['is_paid'])) {
            $paidAmount = round((float) ($queryResult['total_amount'] ?? 0), 2);
            $gatewayTradeNo = (string) ($queryResult['trade_no'] ?? $queryResult['transaction_id'] ?? '');
            $markResult = self::markSuccess($pdo, $channel, $outTradeNo, $gatewayTradeNo, $paidAmount, [
                'query_sync' => 1,
                'gateway' => $queryResult['gateway_response'] ?? null,
            ]);

            Audit::log($actorUserId, 'online_payment.query_sync', 'online_payment', (int) $online['id'], 'Sync online payment status', [
                'payment_no' => $paymentNo,
                'result' => 'paid',
            ]);

            return [
                'payment_no' => $paymentNo,
                'status' => 'success',
                'query' => $queryResult,
                'mark' => $markResult,
            ];
        }

        if (!empty($queryResult['ok']) && !empty($queryResult['is_closed']) && $onlineStatus === 'pending') {
            $closeUpdate = $pdo->prepare(
                'UPDATE qiling_online_payments
                 SET status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $closeUpdate->execute([
                'status' => 'closed',
                'updated_at' => $now,
                'id' => (int) $online['id'],
            ]);
            $onlineStatus = 'closed';
        }

        Audit::log($actorUserId, 'online_payment.query_sync', 'online_payment', (int) $online['id'], 'Sync online payment status', [
            'payment_no' => $paymentNo,
            'result' => $onlineStatus,
        ]);

        return [
            'payment_no' => $paymentNo,
            'status' => $onlineStatus,
            'query' => $queryResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function closePayment(PDO $pdo, int $actorUserId, string $paymentNo): array
    {
        $online = self::lockOnlinePaymentByPaymentNo($pdo, $paymentNo);
        if (!is_array($online)) {
            throw new \RuntimeException('payment not found');
        }

        $status = (string) $online['status'];
        if ($status === 'success') {
            throw new \RuntimeException('paid payment cannot be closed');
        }
        if ($status === 'closed') {
            return [
                'payment_no' => $paymentNo,
                'status' => 'closed',
                'idempotent' => true,
            ];
        }

        $channel = (string) $online['channel'];
        $outTradeNo = (string) $online['out_trade_no'];
        $closeResult = [];
        $payCfg = PaymentConfigService::runtime($pdo);

        if ($channel === 'alipay') {
            $client = new AlipayClient($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('alipay not enabled');
            }
            $client->assertConfigured();
            $closeResult = $client->close($outTradeNo);
        } elseif ($channel === 'wechat') {
            $client = new WechatPayV2Client($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('wechat not enabled');
            }
            $client->assertConfigured();
            $closeResult = $client->close($outTradeNo);
        } else {
            throw new \RuntimeException('unsupported payment channel');
        }

        if (empty($closeResult['ok'])) {
            throw new \RuntimeException((string) ($closeResult['error'] ?? 'close payment failed'));
        }

        $now = gmdate('Y-m-d H:i:s');
        $update = $pdo->prepare(
            'UPDATE qiling_online_payments
             SET status = :status,
                 gateway_response_json = :gateway_response_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'status' => 'closed',
            'gateway_response_json' => json_encode($closeResult['gateway_response'] ?? $closeResult, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
            'id' => (int) $online['id'],
        ]);

        Audit::log($actorUserId, 'online_payment.close', 'online_payment', (int) $online['id'], 'Close online payment', [
            'payment_no' => $paymentNo,
            'channel' => $channel,
        ]);

        return [
            'payment_no' => $paymentNo,
            'status' => 'closed',
            'channel' => $channel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refundPayment(PDO $pdo, int $actorUserId, string $paymentNo, float $refundAmountInput, string $reason): array
    {
        $online = self::lockOnlinePaymentByPaymentNo($pdo, $paymentNo);
        if (!is_array($online)) {
            throw new \RuntimeException('payment not found');
        }

        if ((string) $online['status'] !== 'success') {
            throw new \RuntimeException('only successful payment can be refunded');
        }

        $onlinePaymentId = (int) $online['id'];
        $orderId = (int) $online['order_id'];
        $channel = (string) $online['channel'];
        $outTradeNo = (string) $online['out_trade_no'];
        $onlineAmount = round((float) $online['amount'], 2);
        $refundedAmount = self::sumRefundedAmount($pdo, $onlinePaymentId);
        $refundableAmount = round(max(0.0, $onlineAmount - $refundedAmount), 2);
        if ($refundableAmount <= 0.0) {
            throw new \RuntimeException('no refundable amount');
        }

        $refundAmount = $refundAmountInput > 0 ? round($refundAmountInput, 2) : $refundableAmount;
        if ($refundAmount <= 0) {
            throw new \RuntimeException('refund amount must be positive');
        }
        if ($refundAmount > $refundableAmount) {
            throw new \RuntimeException('refund amount exceeds refundable amount');
        }

        $now = gmdate('Y-m-d H:i:s');
        $refundNo = self::generateRefundNo($pdo);
        $outRefundNo = $refundNo;

        $insertRefund = $pdo->prepare(
            'INSERT INTO qiling_online_payment_refunds
             (online_payment_id, order_id, refund_no, out_refund_no, channel, refund_amount, status, reason, created_by, created_at, updated_at)
             VALUES
             (:online_payment_id, :order_id, :refund_no, :out_refund_no, :channel, :refund_amount, :status, :reason, :created_by, :created_at, :updated_at)'
        );
        $insertRefund->execute([
            'online_payment_id' => $onlinePaymentId,
            'order_id' => $orderId,
            'refund_no' => $refundNo,
            'out_refund_no' => $outRefundNo,
            'channel' => $channel,
            'refund_amount' => $refundAmount,
            'status' => 'pending',
            'reason' => $reason,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $refundId = (int) $pdo->lastInsertId();

        $gatewayResult = [];
        $payCfg = PaymentConfigService::runtime($pdo);
        if ($channel === 'alipay') {
            $client = new AlipayClient($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('alipay not enabled');
            }
            $client->assertConfigured();
            $gatewayResult = $client->refund($outTradeNo, $outRefundNo, $refundAmount, $reason);
        } elseif ($channel === 'wechat') {
            $client = new WechatPayV2Client($payCfg);
            if (!$client->isEnabled()) {
                throw new \RuntimeException('wechat not enabled');
            }
            $client->assertConfigured();
            $gatewayResult = $client->refund(
                $outTradeNo,
                $outRefundNo,
                (int) round($onlineAmount * 100),
                (int) round($refundAmount * 100)
            );
        } else {
            throw new \RuntimeException('unsupported payment channel');
        }

        if (empty($gatewayResult['ok'])) {
            $updateFailed = $pdo->prepare(
                'UPDATE qiling_online_payment_refunds
                 SET status = :status,
                     gateway_response_json = :gateway_response_json,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateFailed->execute([
                'status' => 'failed',
                'gateway_response_json' => json_encode($gatewayResult['gateway_response'] ?? $gatewayResult, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'id' => $refundId,
            ]);
            throw new \RuntimeException((string) ($gatewayResult['error'] ?? 'refund failed'));
        }

        $gatewayRefundNo = (string) ($gatewayResult['refund_id'] ?? $gatewayResult['trade_no'] ?? '');
        $updateRefund = $pdo->prepare(
            'UPDATE qiling_online_payment_refunds
             SET status = :status,
                 gateway_refund_no = :gateway_refund_no,
                 gateway_response_json = :gateway_response_json,
                 refunded_at = :refunded_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateRefund->execute([
            'status' => 'success',
            'gateway_refund_no' => $gatewayRefundNo,
            'gateway_response_json' => json_encode($gatewayResult['gateway_response'] ?? $gatewayResult, JSON_UNESCAPED_UNICODE),
            'refunded_at' => $now,
            'updated_at' => $now,
            'id' => $refundId,
        ]);

        $order = self::lockOrder($pdo, $orderId);
        if (!is_array($order)) {
            throw new \RuntimeException('order not found');
        }

        $payableAmount = round((float) $order['payable_amount'], 2);
        $paidAmountBefore = round((float) $order['paid_amount'], 2);
        $paidAmountAfter = round(max(0.0, $paidAmountBefore - $refundAmount), 2);
        $orderStatus = 'paid';
        if ($paidAmountAfter <= 0.0) {
            $orderStatus = 'refunded';
        } elseif ($paidAmountAfter < $payableAmount) {
            $orderStatus = 'partially_paid';
        }

        $updateOrder = $pdo->prepare(
            'UPDATE qiling_orders
             SET status = :status,
                 paid_amount = :paid_amount,
                 paid_at = :paid_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateOrder->execute([
            'status' => $orderStatus,
            'paid_amount' => $paidAmountAfter,
            'paid_at' => $orderStatus === 'paid' ? ($order['paid_at'] ?: $now) : null,
            'updated_at' => $now,
            'id' => $orderId,
        ]);

        $updateCustomer = $pdo->prepare(
            'UPDATE qiling_customers
             SET total_spent = GREATEST(total_spent - :refund_amount, 0),
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateCustomer->execute([
            'refund_amount' => $refundAmount,
            'updated_at' => $now,
            'id' => (int) $order['customer_id'],
        ]);

        $ledgerPaymentNo = self::generateRefundPaymentNo($pdo);
        $insertLedger = $pdo->prepare(
            'INSERT INTO qiling_order_payments
             (order_id, payment_no, pay_method, amount, status, paid_at, operator_user_id, note, created_at)
             VALUES
             (:order_id, :payment_no, :pay_method, :amount, :status, :paid_at, :operator_user_id, :note, :created_at)'
        );
        $insertLedger->execute([
            'order_id' => $orderId,
            'payment_no' => $ledgerPaymentNo,
            'pay_method' => $channel,
            'amount' => -$refundAmount,
            'status' => 'refunded',
            'paid_at' => $now,
            'operator_user_id' => $actorUserId,
            'note' => 'online refund: ' . $refundNo,
            'created_at' => $now,
        ]);

        Audit::log($actorUserId, 'online_payment.refund', 'online_payment_refund', $refundId, 'Refund online payment', [
            'payment_no' => $paymentNo,
            'refund_no' => $refundNo,
            'channel' => $channel,
            'refund_amount' => $refundAmount,
            'order_status' => $orderStatus,
        ]);

        return [
            'refund_id' => $refundId,
            'refund_no' => $refundNo,
            'payment_no' => $paymentNo,
            'channel' => $channel,
            'refund_amount' => $refundAmount,
            'order_id' => $orderId,
            'order_status' => $orderStatus,
            'paid_amount' => $paidAmountAfter,
            'outstanding_amount' => $orderStatus === 'refunded' ? 0.0 : round(max(0.0, $payableAmount - $paidAmountAfter), 2),
            'gateway_refund_no' => $gatewayRefundNo,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listRefunds(PDO $pdo, string $paymentNo): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.*
             FROM qiling_online_payment_refunds r
             INNER JOIN qiling_online_payments p ON p.id = r.online_payment_id
             WHERE p.payment_no = :payment_no
             ORDER BY r.id DESC'
        );
        $stmt->execute([
            'payment_no' => $paymentNo,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, string>
     */
    private static function closeSiblingPendingPayments(PDO $pdo, int $currentOnlineId, int $orderId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, payment_no, out_trade_no, channel
             FROM qiling_online_payments
             WHERE order_id = :order_id
               AND id <> :id
               AND status = :status'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'id' => $currentOnlineId,
            'status' => 'pending',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $warnings = [];
        $payCfg = PaymentConfigService::runtime($pdo);
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $channel = (string) ($row['channel'] ?? '');
            $outTradeNo = (string) ($row['out_trade_no'] ?? '');
            $paymentNo = (string) ($row['payment_no'] ?? '');
            if ($id <= 0 || $outTradeNo === '' || $channel === '') {
                continue;
            }

            try {
                if ($channel === 'alipay') {
                    $client = new AlipayClient($payCfg);
                    if (!$client->isEnabled()) {
                        throw new \RuntimeException('alipay not enabled');
                    }
                    $client->assertConfigured();
                    $closeResult = $client->close($outTradeNo);
                } elseif ($channel === 'wechat') {
                    $client = new WechatPayV2Client($payCfg);
                    if (!$client->isEnabled()) {
                        throw new \RuntimeException('wechat not enabled');
                    }
                    $client->assertConfigured();
                    $closeResult = $client->close($outTradeNo);
                } else {
                    throw new \RuntimeException('unsupported payment channel');
                }

                if (empty($closeResult['ok'])) {
                    throw new \RuntimeException((string) ($closeResult['error'] ?? 'close sibling payment failed'));
                }

                $update = $pdo->prepare(
                    'UPDATE qiling_online_payments
                     SET status = :status,
                         gateway_response_json = :gateway_response_json,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'status' => 'closed',
                    'gateway_response_json' => json_encode($closeResult['gateway_response'] ?? $closeResult, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'id' => $id,
                ]);
            } catch (\Throwable $t) {
                $warnings[] = 'close_sibling_failed ' . $paymentNo . ': ' . $t->getMessage();
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockOrder(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, order_no, store_id, customer_id, status, payable_amount, paid_amount, paid_at
             FROM qiling_orders
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockOnlinePaymentByPaymentNo(PDO $pdo, string $paymentNo): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_online_payments
             WHERE payment_no = :payment_no
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'payment_no' => $paymentNo,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<int, string>
     */
    private static function alipaySceneCandidates(string $scene): array
    {
        $scene = strtolower(trim($scene));
        if (in_array($scene, ['page', 'web'], true)) {
            return ['page'];
        }
        if (in_array($scene, ['wap', 'h5'], true)) {
            return ['wap'];
        }
        if ($scene === 'app') {
            return ['app'];
        }
        if ($scene === 'f2f') {
            return ['f2f'];
        }
        return ['f2f', 'page'];
    }

    private static function generatePaymentNo(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $paymentNo = 'QLON' . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM qiling_online_payments WHERE payment_no = :payment_no LIMIT 1');
            $stmt->execute(['payment_no' => $paymentNo]);
            if (!$stmt->fetchColumn()) {
                return $paymentNo;
            }
        }

        throw new \RuntimeException('failed to generate online payment no');
    }

    private static function generateRefundNo(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $refundNo = 'QLRF' . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM qiling_online_payment_refunds WHERE refund_no = :refund_no LIMIT 1');
            $stmt->execute(['refund_no' => $refundNo]);
            if (!$stmt->fetchColumn()) {
                return $refundNo;
            }
        }

        throw new \RuntimeException('failed to generate refund no');
    }

    private static function generateRefundPaymentNo(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $paymentNo = 'QLPMR' . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM qiling_order_payments WHERE payment_no = :payment_no LIMIT 1');
            $stmt->execute(['payment_no' => $paymentNo]);
            if (!$stmt->fetchColumn()) {
                return $paymentNo;
            }
        }

        throw new \RuntimeException('failed to generate refund payment no');
    }

    private static function sumRefundedAmount(PDO $pdo, int $onlinePaymentId): float
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(refund_amount), 0)
             FROM qiling_online_payment_refunds
             WHERE online_payment_id = :online_payment_id
               AND status = :status'
        );
        $stmt->execute([
            'online_payment_id' => $onlinePaymentId,
            'status' => 'success',
        ]);

        return round((float) $stmt->fetchColumn(), 2);
    }
}

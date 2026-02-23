<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Core\Auth;
use Qiling\Core\Audit;
use Qiling\Core\Config;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\Payment\AlipayClient;
use Qiling\Core\Payment\OnlinePaymentService;
use Qiling\Core\Payment\PaymentConfigService;
use Qiling\Core\Payment\WechatPayV2Client;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class PaymentController
{
    public static function config(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        Response::json(PaymentConfigService::adminPayload(Database::pdo()));
    }

    public static function updateConfig(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);
        $data = Request::jsonBody();

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            PaymentConfigService::update($pdo, $data, (int) $user['id']);
            Audit::log((int) $user['id'], 'payment.config.update', 'system_settings', 0, 'Update payment config', [
                'fields' => array_keys($data),
            ]);
            $pdo->commit();
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
            return;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('保存支付配置失败', $e);
            return;
        }

        Response::json(PaymentConfigService::adminPayload(Database::pdo()));
    }

    public static function createOnline(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();
        $orderId = Request::int($data, 'order_id', 0);
        if ($orderId > 0) {
            self::assertOrderAccess($user, $orderId);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $result = OnlinePaymentService::create($pdo, (int) $user['id'], $data);
            $pdo->commit();
            Response::json(self::appendCashierMeta($result), 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create online payment failed', $e);
        }
    }

    public static function createDualQr(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();
        $orderId = Request::int($data, 'order_id', 0);
        if ($orderId <= 0) {
            Response::json(['message' => 'order_id is required'], 422);
            return;
        }
        self::assertOrderAccess($user, $orderId);

        $subject = Request::str($data, 'subject');
        $clientIp = Request::str($data, 'client_ip');
        if ($clientIp === '') {
            $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        }
        $alipaySceneInput = strtolower(Request::str($data, 'alipay_scene', 'auto'));
        $alipaySceneCandidates = self::alipayDualQrSceneCandidates($alipaySceneInput);
        $alipaySceneUsed = '';

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $result = [
                'order_id' => $orderId,
                'alipay' => null,
                'wechat' => null,
                'errors' => [],
            ];

            foreach ($alipaySceneCandidates as $scene) {
                try {
                    $alipayResult = OnlinePaymentService::create($pdo, (int) $user['id'], [
                        'order_id' => $orderId,
                        'channel' => 'alipay',
                        'scene' => $scene,
                        'subject' => $subject,
                        'client_ip' => $clientIp,
                    ]);
                    $alipaySceneUsed = $scene;
                    $result['alipay'] = self::appendCashierMeta($alipayResult);
                    break;
                } catch (\Throwable $e) {
                    $result['errors']['alipay_' . $scene] = $e->getMessage();
                }
            }

            try {
                $wechatResult = OnlinePaymentService::create($pdo, (int) $user['id'], [
                    'order_id' => $orderId,
                    'channel' => 'wechat',
                    'scene' => 'native',
                    'subject' => $subject,
                    'client_ip' => $clientIp,
                ]);
                $result['wechat'] = self::appendCashierMeta($wechatResult);
            } catch (\Throwable $e) {
                $result['errors']['wechat'] = $e->getMessage();
            }

            if (!is_array($result['alipay']) && !is_array($result['wechat'])) {
                throw new \RuntimeException('alipay and wechat create both failed');
            }

            $result['alipay_scene_requested'] = $alipaySceneInput;
            $result['alipay_scene_used'] = $alipaySceneUsed;
            $result['alipay_fallback_used'] = ($alipaySceneInput === 'auto' || $alipaySceneInput === 'f2f') && $alipaySceneUsed === 'page' ? 1 : 0;

            Audit::log((int) $user['id'], 'online_payment.create_dual_qr', 'order', $orderId, 'Create dual qr payment', [
                'order_id' => $orderId,
                'alipay_ok' => is_array($result['alipay']) ? 1 : 0,
                'wechat_ok' => is_array($result['wechat']) ? 1 : 0,
                'alipay_scene_requested' => $alipaySceneInput,
                'alipay_scene_used' => $alipaySceneUsed,
                'errors' => $result['errors'],
            ]);

            $pdo->commit();
            Response::json($result, 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create dual qr payment failed', $e);
        }
    }

    public static function publicStatus(): void
    {
        $ticket = isset($_GET['ticket']) && is_string($_GET['ticket']) ? trim($_GET['ticket']) : '';
        if ($ticket === '') {
            Response::json(['message' => 'ticket is required'], 422);
            return;
        }

        $parsed = self::parseCashierTicket($ticket);
        if (!is_array($parsed)) {
            Response::json(['message' => 'ticket invalid or expired'], 401);
            return;
        }

        $row = OnlinePaymentService::findByPaymentNo(Database::pdo(), (string) $parsed['payment_no']);
        if (!is_array($row)) {
            Response::json(['message' => 'payment not found'], 404);
            return;
        }

        Response::json(self::publicPaymentPayload($row, $ticket, (int) $parsed['expire_at']));
    }

    public static function publicStatuses(): void
    {
        $data = Request::jsonBody();
        $tickets = self::normalizeTicketList($data['tickets'] ?? ($data['ticket'] ?? []));
        if (empty($tickets)) {
            Response::json(['message' => 'ticket is required'], 422);
            return;
        }

        $syncPending = self::toBool($data['sync_pending'] ?? false);
        $items = [];
        $errors = [];
        $seenPaymentNos = [];
        $pdo = Database::pdo();

        foreach ($tickets as $ticket) {
            $parsed = self::parseCashierTicket($ticket);
            if (!is_array($parsed)) {
                $errors[] = [
                    'ticket' => $ticket,
                    'message' => 'ticket invalid or expired',
                ];
                continue;
            }
            $paymentNo = (string) $parsed['payment_no'];

            if ($syncPending) {
                $rowBefore = OnlinePaymentService::findByPaymentNo($pdo, $paymentNo);
                if (is_array($rowBefore) && (string) ($rowBefore['status'] ?? '') === 'pending') {
                    $pdo->beginTransaction();
                    try {
                        OnlinePaymentService::syncStatus($pdo, 0, $paymentNo);
                        $pdo->commit();
                    } catch (\Throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }
                }
            }

            $row = OnlinePaymentService::findByPaymentNo($pdo, $paymentNo);
            if (!is_array($row)) {
                $errors[] = [
                    'ticket' => $ticket,
                    'message' => 'payment not found',
                ];
                continue;
            }

            if (isset($seenPaymentNos[$paymentNo])) {
                continue;
            }
            $seenPaymentNos[$paymentNo] = 1;

            $items[] = self::publicPaymentPayload($row, $ticket, (int) $parsed['expire_at']);
        }

        Response::json([
            'total' => count($items),
            'errors' => $errors,
            'data' => $items,
        ]);
    }

    public static function status(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $paymentNo = isset($_GET['payment_no']) && is_string($_GET['payment_no']) ? trim($_GET['payment_no']) : '';
        if ($paymentNo === '') {
            Response::json(['message' => 'payment_no is required'], 422);
            return;
        }
        self::assertPaymentAccess($user, $paymentNo);

        $row = OnlinePaymentService::findByPaymentNo(Database::pdo(), $paymentNo);
        if (!is_array($row)) {
            Response::json(['message' => 'payment not found'], 404);
            return;
        }

        Response::json([
            'payment_no' => $row['payment_no'],
            'out_trade_no' => $row['out_trade_no'],
            'order_id' => (int) $row['order_id'],
            'order_no' => $row['order_no'],
            'channel' => $row['channel'],
            'scene' => $row['scene'],
            'status' => $row['status'],
            'amount' => (float) $row['amount'],
            'order_status' => $row['order_status'],
            'paid_amount' => (float) $row['paid_amount'],
            'payable_amount' => (float) $row['payable_amount'],
            'outstanding_amount' => round(max(0.0, (float) $row['payable_amount'] - (float) $row['paid_amount']), 2),
            'qr_code' => (string) ($row['qr_code'] ?? ''),
            'pay_url' => (string) ($row['pay_url'] ?? ''),
            'prepay_id' => (string) ($row['prepay_id'] ?? ''),
            'gateway_trade_no' => (string) ($row['gateway_trade_no'] ?? ''),
            'paid_at' => $row['paid_at'],
            'created_at' => $row['created_at'],
        ]);
    }

    public static function queryOnline(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();
        $paymentNo = Request::str($data, 'payment_no');
        if ($paymentNo === '') {
            Response::json(['message' => 'payment_no is required'], 422);
            return;
        }
        self::assertPaymentAccess($user, $paymentNo);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $result = OnlinePaymentService::syncStatus($pdo, (int) $user['id'], $paymentNo);
            $pdo->commit();
            Response::json($result);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('query online payment failed', $e);
        }
    }

    public static function closeOnline(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();
        $paymentNo = Request::str($data, 'payment_no');
        if ($paymentNo === '') {
            Response::json(['message' => 'payment_no is required'], 422);
            return;
        }
        self::assertPaymentAccess($user, $paymentNo);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $result = OnlinePaymentService::closePayment($pdo, (int) $user['id'], $paymentNo);
            $pdo->commit();
            Response::json($result);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('close online payment failed', $e);
        }
    }

    public static function refundOnline(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $paymentNo = Request::str($data, 'payment_no');
        if ($paymentNo === '') {
            Response::json(['message' => 'payment_no is required'], 422);
            return;
        }
        self::assertPaymentAccess($user, $paymentNo);

        $refundAmount = max(0.0, (float) ($data['refund_amount'] ?? 0));
        $reason = Request::str($data, 'reason', '后台发起退款');

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $result = OnlinePaymentService::refundPayment($pdo, (int) $user['id'], $paymentNo, $refundAmount, $reason);
            $pdo->commit();
            Response::json($result);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('refund online payment failed', $e);
        }
    }

    public static function refunds(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $paymentNo = isset($_GET['payment_no']) && is_string($_GET['payment_no']) ? trim($_GET['payment_no']) : '';
        if ($paymentNo === '') {
            Response::json(['message' => 'payment_no is required'], 422);
            return;
        }
        self::assertPaymentAccess($user, $paymentNo);

        $rows = OnlinePaymentService::listRefunds(Database::pdo(), $paymentNo);
        Response::json([
            'payment_no' => $paymentNo,
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    public static function alipayNotify(): void
    {
        $payload = $_POST;
        if (!is_array($payload)) {
            $payload = [];
        }

        $client = new AlipayClient(PaymentConfigService::runtime(Database::pdo()));
        if (!$client->verifyNotify($payload)) {
            self::text('fail', 200);
            return;
        }

        $tradeStatus = (string) ($payload['trade_status'] ?? '');
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            self::text('fail', 200);
            return;
        }

        $outTradeNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $tradeNo = trim((string) ($payload['trade_no'] ?? ''));
        $totalAmount = round((float) ($payload['total_amount'] ?? 0), 2);
        if ($outTradeNo === '' || $totalAmount <= 0) {
            self::text('fail', 200);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            OnlinePaymentService::markSuccess($pdo, 'alipay', $outTradeNo, $tradeNo, $totalAmount, $payload);
            $pdo->commit();
            self::text('success', 200);
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::text('fail', 200);
        }
    }

    public static function wechatNotify(): void
    {
        $xml = file_get_contents('php://input');
        $xml = is_string($xml) ? $xml : '';

        $client = new WechatPayV2Client(PaymentConfigService::runtime(Database::pdo()));
        $payload = $client->parseNotifyXml($xml);
        if (empty($payload)) {
            self::xml(self::wechatReply('FAIL', 'XML parse failed'));
            return;
        }

        if ((string) ($payload['return_code'] ?? '') !== 'SUCCESS') {
            self::xml(self::wechatReply('FAIL', 'return_code failed'));
            return;
        }

        if ((string) ($payload['result_code'] ?? '') !== 'SUCCESS') {
            self::xml(self::wechatReply('FAIL', 'result_code failed'));
            return;
        }

        if (!$client->verifyNotify($payload)) {
            self::xml(self::wechatReply('FAIL', 'sign invalid'));
            return;
        }

        $outTradeNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        $totalFeeFen = is_numeric($payload['total_fee'] ?? null) ? (int) $payload['total_fee'] : 0;
        $paidAmount = round($totalFeeFen / 100, 2);

        if ($outTradeNo === '' || $paidAmount <= 0) {
            self::xml(self::wechatReply('FAIL', 'params invalid'));
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            OnlinePaymentService::markSuccess($pdo, 'wechat', $outTradeNo, $transactionId, $paidAmount, $payload);
            $pdo->commit();
            self::xml(self::wechatReply('SUCCESS', 'OK'));
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::xml(self::wechatReply('FAIL', 'process failed'));
        }
    }

    private static function text(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }

    private static function xml(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/xml; charset=utf-8');
        echo $body;
    }

    private static function wechatReply(string $code, string $msg): string
    {
        return '<xml><return_code><![CDATA[' . $code . ']]></return_code><return_msg><![CDATA[' . $msg . ']]></return_msg></xml>';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function appendCashierMeta(array $payload): array
    {
        $paymentNo = trim((string) ($payload['payment_no'] ?? ''));
        if ($paymentNo === '') {
            return $payload;
        }

        $ticket = self::buildCashierTicket($paymentNo, 7200);
        if ($ticket === '') {
            return $payload;
        }
        $payload['cashier_ticket'] = $ticket;
        $payload['cashier_url'] = self::cashierUrl($ticket);
        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicPaymentPayload(array $row, string $ticket, int $expireAtTs): array
    {
        $payable = round((float) ($row['payable_amount'] ?? 0), 2);
        $paid = round((float) ($row['paid_amount'] ?? 0), 2);
        return [
            'ticket' => $ticket,
            'cashier_url' => self::cashierUrl($ticket),
            'ticket_expire_at' => gmdate('Y-m-d H:i:s', $expireAtTs),
            'payment_no' => (string) ($row['payment_no'] ?? ''),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_no' => (string) ($row['order_no'] ?? ''),
            'store_id' => (int) ($row['store_id'] ?? 0),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'channel' => (string) ($row['channel'] ?? ''),
            'scene' => (string) ($row['scene'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'order_status' => (string) ($row['order_status'] ?? ''),
            'amount' => round((float) ($row['amount'] ?? 0), 2),
            'payable_amount' => $payable,
            'paid_amount' => $paid,
            'outstanding_amount' => round(max(0.0, $payable - $paid), 2),
            'qr_code' => (string) ($row['qr_code'] ?? ''),
            'pay_url' => (string) ($row['pay_url'] ?? ''),
            'paid_at' => (string) ($row['paid_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function normalizeTicketList(mixed $value): array
    {
        $items = [];
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', trim($value));
            $items = is_array($parts) ? $parts : [];
        } elseif (is_array($value)) {
            foreach ($value as $v) {
                if (is_string($v)) {
                    $items[] = $v;
                }
            }
        }

        $uniq = [];
        foreach ($items as $item) {
            $t = trim((string) $item);
            if ($t === '') {
                continue;
            }
            $uniq[$t] = 1;
            if (count($uniq) >= 40) {
                break;
            }
        }
        return array_keys($uniq);
    }

    private static function toBool(mixed $value): bool
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<int, string>
     */
    private static function alipayDualQrSceneCandidates(string $scene): array
    {
        $scene = strtolower(trim($scene));
        if (in_array($scene, ['page', 'web'], true)) {
            return ['page'];
        }
        if (in_array($scene, ['wap', 'h5'], true)) {
            return ['wap'];
        }
        if ($scene === 'f2f') {
            return ['f2f', 'page'];
        }
        return ['f2f', 'page'];
    }

    private static function buildCashierTicket(string $paymentNo, int $ttlSeconds): string
    {
        $secret = self::cashierSecret();
        if ($secret === null) {
            return '';
        }

        $ttl = max(300, min($ttlSeconds, 86400));
        $expireAt = time() + $ttl;
        $nonce = bin2hex(random_bytes(6));
        $payload = $paymentNo . '|' . $expireAt . '|' . $nonce;
        $sig = hash_hmac('sha256', $payload, $secret);
        return self::base64UrlEncode($payload . '|' . $sig);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseCashierTicket(string $ticket): ?array
    {
        $secret = self::cashierSecret();
        if ($secret === null) {
            return null;
        }

        $decoded = self::base64UrlDecode($ticket);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }

        $paymentNo = trim((string) ($parts[0] ?? ''));
        $expireAt = is_numeric($parts[1] ?? null) ? (int) $parts[1] : 0;
        $nonce = trim((string) ($parts[2] ?? ''));
        $sig = trim((string) ($parts[3] ?? ''));

        if ($paymentNo === '' || !preg_match('/^[A-Z0-9]+$/', $paymentNo)) {
            return null;
        }
        if ($expireAt <= time()) {
            return null;
        }
        if ($nonce === '' || $sig === '') {
            return null;
        }

        $payload = $paymentNo . '|' . $expireAt . '|' . $nonce;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return [
            'payment_no' => $paymentNo,
            'expire_at' => $expireAt,
        ];
    }

    private static function cashierSecret(): ?string
    {
        $appKey = trim((string) Config::get('APP_KEY', ''));
        if ($appKey !== '') {
            return $appKey;
        }
        return null;
    }

    private static function cashierUrl(string $ticket): string
    {
        $baseUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($baseUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $base = str_replace('\\', '/', dirname($scriptName));
            $rootPath = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');
            $baseUrl = $scheme . '://' . $host . $rootPath;
        }
        return $baseUrl . '/pay/?ticket=' . rawurlencode($ticket);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $raw = strtr($encoded, '-_', '+/');
        $pad = strlen($raw) % 4;
        if ($pad > 0) {
            $raw .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($raw, true);
        return is_string($decoded) ? $decoded : null;
    }

    private static function assertOrderAccess(array $user, int $orderId): void
    {
        $stmt = Database::pdo()->prepare('SELECT store_id FROM qiling_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $result = $stmt->fetchColumn();
        if ($result === false) {
            Response::json(['message' => 'order not found'], 404);
            exit;
        }

        DataScope::assertStoreAccess($user, (int) $result);
    }

    private static function assertPaymentAccess(array $user, string $paymentNo): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT o.store_id
             FROM qiling_online_payments op
             INNER JOIN qiling_orders o ON o.id = op.order_id
             WHERE op.payment_no = :payment_no
             LIMIT 1'
        );
        $stmt->execute(['payment_no' => $paymentNo]);
        $result = $stmt->fetchColumn();
        if ($result === false) {
            Response::json(['message' => 'payment not found'], 404);
            exit;
        }

        DataScope::assertStoreAccess($user, (int) $result);
    }
}

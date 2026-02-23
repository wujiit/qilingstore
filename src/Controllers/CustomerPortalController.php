<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\Config;
use Qiling\Core\CustomerPortalService;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\Payment\OnlinePaymentService;
use Qiling\Core\Payment\PaymentConfigService;
use Qiling\Core\Push\PushService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CustomerPortalController
{
    public static function createToken(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $customerId = Request::int($data, 'customer_id', 0);
        $customerNo = Request::str($data, 'customer_no');
        $customerMobile = Request::str($data, 'customer_mobile');
        if ($customerId <= 0 && $customerNo === '' && $customerMobile === '') {
            Response::json(['message' => 'customer reference is required'], 422);
            return;
        }

        $expireDays = Request::int($data, 'expire_days', 365);
        $expireDays = max(1, min($expireDays, 3650));
        $note = Request::str($data, 'note');
        if (mb_strlen($note) > 120) {
            $note = mb_substr($note, 0, 120);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            CustomerPortalService::ensureTables($pdo);
            $customer = CustomerPortalService::findCustomer($pdo, $customerId, $customerNo, $customerMobile);
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $storeId = (int) ($customer['store_id'] ?? 0);
            DataScope::assertStoreAccess($user, $storeId);

            $expireAt = gmdate('Y-m-d H:i:s', time() + $expireDays * 86400);
            $tokenInfo = CustomerPortalService::createToken(
                $pdo,
                (int) $customer['id'],
                $storeId,
                (int) ($user['id'] ?? 0),
                $expireAt,
                $note
            );
            $portalUrl = self::portalUrl((string) $tokenInfo['token']);
            $qrCodeUrl = 'https://quickchart.io/qr?size=360&margin=1&text=' . rawurlencode($portalUrl);

            Audit::log(
                (int) $user['id'],
                'customer_portal.token.create',
                'customer',
                (int) $customer['id'],
                'Create customer portal token',
                [
                    'token_id' => (int) $tokenInfo['id'],
                    'expire_at' => $expireAt,
                    'store_id' => $storeId,
                ]
            );

            $pdo->commit();
            Response::json([
                'token_info' => [
                    'id' => (int) $tokenInfo['id'],
                    'token_prefix' => (string) ($tokenInfo['token_prefix'] ?? ''),
                    'status' => 'active',
                    'expire_at' => $expireAt,
                ],
                'portal_url' => $portalUrl,
                'qr_code_url' => $qrCodeUrl,
                'customer' => [
                    'id' => (int) ($customer['id'] ?? 0),
                    'customer_no' => (string) ($customer['customer_no'] ?? ''),
                    'name' => (string) ($customer['name'] ?? ''),
                    'mobile' => (string) ($customer['mobile'] ?? ''),
                    'store_id' => $storeId,
                    'store_name' => (string) ($customer['store_name'] ?? ''),
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create customer portal token failed', $e);
        }
    }

    public static function tokens(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);

        $customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $customerNo = isset($_GET['customer_no']) && is_string($_GET['customer_no']) ? trim($_GET['customer_no']) : '';
        $customerMobile = isset($_GET['customer_mobile']) && is_string($_GET['customer_mobile']) ? trim($_GET['customer_mobile']) : '';
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        if ($status !== '' && !in_array($status, ['active', 'revoked', 'expired'], true)) {
            Response::json(['message' => 'invalid status'], 422);
            return;
        }
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 80;
        $limit = max(1, min($limit, 300));

        $pdo = Database::pdo();
        CustomerPortalService::ensureTables($pdo);

        $scopeStoreId = DataScope::resolveFilterStoreId($user, null);
        $targetCustomerId = null;
        if ($customerId > 0 || $customerNo !== '' || $customerMobile !== '') {
            $customer = CustomerPortalService::findCustomer($pdo, $customerId, $customerNo, $customerMobile);
            if (!is_array($customer)) {
                Response::json(['data' => []]);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($customer['store_id'] ?? 0));
            $targetCustomerId = (int) ($customer['id'] ?? 0);
            $scopeStoreId = (int) ($customer['store_id'] ?? 0);
        }

        $rows = CustomerPortalService::listTokens($pdo, $scopeStoreId, $targetCustomerId, $status, $limit);
        Response::json(['data' => $rows]);
    }

    public static function revokeToken(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $tokenId = Request::int($data, 'token_id', 0);
        if ($tokenId <= 0) {
            Response::json(['message' => 'token_id is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $token = CustomerPortalService::findTokenById($pdo, $tokenId);
            if (!is_array($token)) {
                $pdo->rollBack();
                Response::json(['message' => 'token not found'], 404);
                return;
            }

            DataScope::assertStoreAccess($user, (int) ($token['store_id'] ?? 0));
            CustomerPortalService::revokeToken($pdo, $tokenId);
            Audit::log(
                (int) $user['id'],
                'customer_portal.token.revoke',
                'customer_portal_token',
                $tokenId,
                'Revoke customer portal token',
                [
                    'customer_id' => (int) ($token['customer_id'] ?? 0),
                    'store_id' => (int) ($token['store_id'] ?? 0),
                ]
            );
            $pdo->commit();

            Response::json([
                'token_id' => $tokenId,
                'status' => 'revoked',
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('revoke token failed', $e);
        }
    }

    public static function resetToken(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $customerId = Request::int($data, 'customer_id', 0);
        $customerNo = Request::str($data, 'customer_no');
        $customerMobile = Request::str($data, 'customer_mobile');
        if ($customerId <= 0 && $customerNo === '' && $customerMobile === '') {
            Response::json(['message' => 'customer reference is required'], 422);
            return;
        }

        $newToken = Request::str($data, 'new_token');
        if ($newToken !== '') {
            $validationError = self::validateCustomPortalToken($newToken);
            if ($validationError !== '') {
                Response::json(['message' => $validationError], 422);
                return;
            }
        }

        $expireDays = Request::int($data, 'expire_days', 365);
        $expireDays = max(1, min($expireDays, 3650));
        $note = Request::str($data, 'note');
        if ($note === '') {
            $note = 'admin reset portal token';
        }
        if (mb_strlen($note) > 120) {
            $note = mb_substr($note, 0, 120);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            CustomerPortalService::ensureTables($pdo);
            $customer = CustomerPortalService::findCustomer($pdo, $customerId, $customerNo, $customerMobile);
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $storeId = (int) ($customer['store_id'] ?? 0);
            $customerPk = (int) ($customer['id'] ?? 0);
            DataScope::assertStoreAccess($user, $storeId);
            if ($customerPk <= 0) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $revokedCount = CustomerPortalService::revokeActiveTokensByCustomer($pdo, $customerPk);
            $expireAt = gmdate('Y-m-d H:i:s', time() + $expireDays * 86400);
            $tokenInfo = CustomerPortalService::createToken(
                $pdo,
                $customerPk,
                $storeId,
                (int) ($user['id'] ?? 0),
                $expireAt,
                $note,
                $newToken
            );
            $portalUrl = self::portalUrl((string) $tokenInfo['token']);
            $qrCodeUrl = 'https://quickchart.io/qr?size=360&margin=1&text=' . rawurlencode($portalUrl);

            Audit::log(
                (int) $user['id'],
                'customer_portal.token.reset',
                'customer',
                $customerPk,
                'Reset customer portal token by admin',
                [
                    'token_id' => (int) $tokenInfo['id'],
                    'revoked_count' => $revokedCount,
                    'expire_at' => $expireAt,
                    'store_id' => $storeId,
                ]
            );

            $pdo->commit();
            Response::json([
                'revoked_count' => $revokedCount,
                'token' => (string) ($tokenInfo['token'] ?? ''),
                'token_info' => [
                    'id' => (int) ($tokenInfo['id'] ?? 0),
                    'token_prefix' => (string) ($tokenInfo['token_prefix'] ?? ''),
                    'status' => (string) ($tokenInfo['status'] ?? 'active'),
                    'expire_at' => $expireAt,
                ],
                'portal_url' => $portalUrl,
                'qr_code_url' => $qrCodeUrl,
                'customer' => [
                    'id' => $customerPk,
                    'customer_no' => (string) ($customer['customer_no'] ?? ''),
                    'name' => (string) ($customer['name'] ?? ''),
                    'mobile' => (string) ($customer['mobile'] ?? ''),
                    'store_id' => $storeId,
                    'store_name' => (string) ($customer['store_name'] ?? ''),
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('reset customer portal token failed', $e);
        }
    }

    public static function guards(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);

        $customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $customerNo = isset($_GET['customer_no']) && is_string($_GET['customer_no']) ? trim($_GET['customer_no']) : '';
        $customerMobile = isset($_GET['customer_mobile']) && is_string($_GET['customer_mobile']) ? trim($_GET['customer_mobile']) : '';
        $lockedOnlyRaw = isset($_GET['locked_only']) && is_string($_GET['locked_only']) ? trim($_GET['locked_only']) : '1';
        $lockedOnly = !in_array(strtolower($lockedOnlyRaw), ['0', 'false', 'no', 'off'], true);
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 80;
        $limit = max(1, min($limit, 300));

        $pdo = Database::pdo();
        CustomerPortalService::ensureTables($pdo);

        $scopeStoreId = DataScope::resolveFilterStoreId($user, null);
        $targetCustomerId = null;
        if ($customerId > 0 || $customerNo !== '' || $customerMobile !== '') {
            $customer = CustomerPortalService::findCustomer($pdo, $customerId, $customerNo, $customerMobile);
            if (!is_array($customer)) {
                Response::json(['data' => []]);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($customer['store_id'] ?? 0));
            $targetCustomerId = (int) ($customer['id'] ?? 0);
            $scopeStoreId = (int) ($customer['store_id'] ?? 0);
        }

        $rows = CustomerPortalService::listTokenLockGuards($pdo, $scopeStoreId, $targetCustomerId, $lockedOnly, $limit);
        Response::json(['data' => $rows]);
    }

    public static function unlockGuard(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $ipAddress = Request::str($data, 'ip_address');
        $customerId = Request::int($data, 'customer_id', 0);
        $customerNo = Request::str($data, 'customer_no');
        $customerMobile = Request::str($data, 'customer_mobile');
        if ($ipAddress === '' && $customerId <= 0 && $customerNo === '' && $customerMobile === '') {
            Response::json(['message' => 'customer reference or ip_address is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        CustomerPortalService::ensureTables($pdo);

        if ($ipAddress !== '' && $customerId <= 0 && $customerNo === '' && $customerMobile === '') {
            $updated = CustomerPortalService::unlockIpGuard($pdo, $ipAddress);
            if (!$updated) {
                Response::json(['message' => 'ip guard not found'], 404);
                return;
            }

            Audit::log(
                (int) ($user['id'] ?? 0),
                'customer_portal.guard.unlock',
                'customer_portal_ip_guard',
                0,
                'Unlock customer portal ip guard',
                [
                    'ip_address' => $ipAddress,
                ]
            );

            Response::json([
                'unlocked' => true,
                'ip_address' => $ipAddress,
            ]);
            return;
        }

        $customer = CustomerPortalService::findCustomer($pdo, $customerId, $customerNo, $customerMobile);
        if (!is_array($customer)) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }
        $storeId = (int) ($customer['store_id'] ?? 0);
        $customerPk = (int) ($customer['id'] ?? 0);
        DataScope::assertStoreAccess($user, $storeId);
        if ($customerPk <= 0) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }

        $updatedCount = CustomerPortalService::unlockTokenGuardsByCustomer($pdo, $customerPk);

        Audit::log(
            (int) ($user['id'] ?? 0),
            'customer_portal.guard.unlock',
            'customer',
            $customerPk,
            'Unlock customer portal token guards',
            [
                'store_id' => $storeId,
                'updated_count' => $updatedCount,
            ]
        );

        Response::json([
            'unlocked' => true,
            'updated_count' => $updatedCount,
            'customer' => [
                'id' => $customerPk,
                'customer_no' => (string) ($customer['customer_no'] ?? ''),
                'name' => (string) ($customer['name'] ?? ''),
                'mobile' => (string) ($customer['mobile'] ?? ''),
                'store_id' => $storeId,
                'store_name' => (string) ($customer['store_name'] ?? ''),
            ],
        ]);
    }

    public static function rotateToken(): void
    {
        $data = Request::jsonBody();
        $token = Request::str($data, 'token');
        if ($token === '') {
            Response::json(['message' => 'portal token is required'], 422);
            return;
        }

        $newToken = Request::str($data, 'new_token');
        if ($newToken !== '') {
            if ($newToken === $token) {
                Response::json(['message' => 'new_token must be different from current token'], 422);
                return;
            }
            $validationError = self::validateCustomPortalToken($newToken);
            if ($validationError !== '') {
                Response::json(['message' => $validationError], 422);
                return;
            }
        }

        $pdo = Database::pdo();
        $clientIp = self::clientIp();
        if (!self::assertPortalAccessRateLimit($pdo, $clientIp)) {
            return;
        }
        $pdo->beginTransaction();
        try {
            CustomerPortalService::ensureTables($pdo);
            $resolved = CustomerPortalService::resolveActiveToken($pdo, $token);
            if (!is_array($resolved)) {
                $pdo->rollBack();
                self::handleInvalidPortalToken($pdo, $token);
                return;
            }

            if ((string) ($resolved['customer_status'] ?? '') !== 'active') {
                $pdo->rollBack();
                Response::json(['message' => 'customer account disabled'], 403);
                return;
            }

            $oldTokenId = (int) ($resolved['id'] ?? 0);
            $customerId = (int) ($resolved['customer_id'] ?? 0);
            if ($oldTokenId <= 0 || $customerId <= 0) {
                $pdo->rollBack();
                self::handleInvalidPortalToken($pdo, $token);
                return;
            }
            CustomerPortalService::clearAuthFailures($pdo, self::tokenFailureKey($token));

            $storeId = (int) ($resolved['store_id'] ?? 0);
            $expireAtRaw = $resolved['expire_at'] ?? null;
            $expireAt = null;
            if (is_string($expireAtRaw) && trim($expireAtRaw) !== '') {
                $expireAt = trim($expireAtRaw);
            }

            CustomerPortalService::revokeToken($pdo, $oldTokenId);
            $tokenInfo = CustomerPortalService::createToken(
                $pdo,
                $customerId,
                $storeId,
                0,
                $expireAt,
                'customer self rotate token',
                $newToken
            );
            $newTokenValue = (string) ($tokenInfo['token'] ?? '');
            $portalUrl = self::portalUrl($newTokenValue);
            $qrCodeUrl = 'https://quickchart.io/qr?size=360&margin=1&text=' . rawurlencode($portalUrl);

            Audit::log(
                0,
                'customer_portal.token.rotate',
                'customer_portal_token',
                (int) ($tokenInfo['id'] ?? 0),
                'Rotate customer portal token by customer',
                [
                    'old_token_id' => $oldTokenId,
                    'customer_id' => $customerId,
                    'store_id' => $storeId,
                    'expire_at' => $expireAt,
                ]
            );

            $pdo->commit();
            Response::json([
                'updated' => true,
                'token' => $newTokenValue,
                'token_info' => [
                    'id' => (int) ($tokenInfo['id'] ?? 0),
                    'token_prefix' => (string) ($tokenInfo['token_prefix'] ?? ''),
                    'status' => (string) ($tokenInfo['status'] ?? 'active'),
                    'expire_at' => $expireAt,
                ],
                'portal_url' => $portalUrl,
                'qr_code_url' => $qrCodeUrl,
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
            Response::serverError('rotate customer portal token failed', $e);
        }
    }

    public static function overview(): void
    {
        $token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
        if ($token === '') {
            Response::json(['message' => 'portal token is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $clientIp = self::clientIp();
        if (!self::assertPortalAccessRateLimit($pdo, $clientIp)) {
            return;
        }
        CustomerPortalService::ensureTables($pdo);
        ServiceController::ensureServiceSchema($pdo);
        $resolved = CustomerPortalService::resolveActiveToken($pdo, $token);
        if (!is_array($resolved)) {
            self::handleInvalidPortalToken($pdo, $token);
            return;
        }

        if ((string) ($resolved['customer_status'] ?? '') !== 'active') {
            Response::json(['message' => 'customer account disabled'], 403);
            return;
        }
        CustomerPortalService::clearAuthFailures($pdo, self::tokenFailureKey($token));

        $customerId = (int) ($resolved['customer_id'] ?? 0);
        if ($customerId <= 0) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }

        CustomerPortalService::touchTokenUse($pdo, (int) ($resolved['id'] ?? 0));

        $profileStmt = $pdo->prepare(
            'SELECT c.id, c.customer_no, c.store_id, c.name, c.mobile, c.gender, c.birthday, c.source_channel, c.status,
                    c.total_spent, c.visit_count, c.last_visit_at, c.created_at, s.store_name
             FROM qiling_customers c
             LEFT JOIN qiling_stores s ON s.id = c.store_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $profileStmt->execute(['id' => $customerId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile)) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }

        $walletStmt = $pdo->prepare(
            'SELECT balance, total_recharge, total_gift, total_spent
             FROM qiling_customer_wallets
             WHERE customer_id = :customer_id
             LIMIT 1'
        );
        $walletStmt->execute(['customer_id' => $customerId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($wallet)) {
            $wallet = [
                'balance' => '0.00',
                'total_recharge' => '0.00',
                'total_gift' => '0.00',
                'total_spent' => '0.00',
            ];
        }

        $cardsStmt = $pdo->prepare(
            'SELECT mc.id, mc.card_no, mc.total_sessions, mc.remaining_sessions, mc.sold_price, mc.sold_at, mc.expire_at, mc.status,
                    sp.package_name
             FROM qiling_member_cards mc
             LEFT JOIN qiling_service_packages sp ON sp.id = mc.package_id
             WHERE mc.customer_id = :customer_id
             ORDER BY mc.id DESC
             LIMIT 120'
        );
        $cardsStmt->execute(['customer_id' => $customerId]);
        $memberCards = $cardsStmt->fetchAll(PDO::FETCH_ASSOC);

        $couponStmt = $pdo->prepare(
            'SELECT id, coupon_code, coupon_name, coupon_type, face_value, min_spend, remain_count, expire_at, status
             FROM qiling_coupons
             WHERE customer_id = :customer_id
             ORDER BY id DESC
             LIMIT 200'
        );
        $couponStmt->execute(['customer_id' => $customerId]);
        $coupons = $couponStmt->fetchAll(PDO::FETCH_ASSOC);

        $consumeStmt = $pdo->prepare(
            'SELECT id, consume_no, consume_amount, deduct_balance_amount, deduct_coupon_amount, deduct_member_card_sessions, created_at
             FROM qiling_customer_consume_records
             WHERE customer_id = :customer_id
             ORDER BY id DESC
             LIMIT 200'
        );
        $consumeStmt->execute(['customer_id' => $customerId]);
        $consumeRecords = $consumeStmt->fetchAll(PDO::FETCH_ASSOC);

        $ordersStmt = $pdo->prepare(
            'SELECT id, order_no, status, payable_amount, paid_amount, paid_at, created_at
             FROM qiling_orders
             WHERE customer_id = :customer_id
             ORDER BY id DESC
             LIMIT 120'
        );
        $ordersStmt->execute(['customer_id' => $customerId]);
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        $availableServices = self::listPortalBookableServices($pdo, (int) ($profile['store_id'] ?? 0));
        $appointments = self::listPortalAppointments($pdo, $customerId);

        $onlineStmt = $pdo->prepare(
            'SELECT op.id, op.payment_no, op.order_id, o.order_no, op.channel, op.scene, op.amount, op.status,
                    op.qr_code, op.pay_url, op.prepay_id, op.paid_at, op.created_at,
                    o.status AS order_status, o.payable_amount, o.paid_amount
             FROM qiling_online_payments op
             INNER JOIN qiling_orders o ON o.id = op.order_id
             WHERE o.customer_id = :customer_id
             ORDER BY op.id DESC
             LIMIT 200'
        );
        $onlineStmt->execute(['customer_id' => $customerId]);
        $onlinePayments = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json([
            'profile' => [
                'id' => (int) ($profile['id'] ?? 0),
                'customer_no' => (string) ($profile['customer_no'] ?? ''),
                'store_id' => (int) ($profile['store_id'] ?? 0),
                'store_name' => (string) ($profile['store_name'] ?? ''),
                'name' => (string) ($profile['name'] ?? ''),
                'mobile' => (string) ($profile['mobile'] ?? ''),
                'gender' => (string) ($profile['gender'] ?? ''),
                'birthday' => (string) ($profile['birthday'] ?? ''),
                'source_channel' => (string) ($profile['source_channel'] ?? ''),
                'status' => (string) ($profile['status'] ?? ''),
                'total_spent' => (string) ($profile['total_spent'] ?? '0.00'),
                'visit_count' => (int) ($profile['visit_count'] ?? 0),
                'last_visit_at' => (string) ($profile['last_visit_at'] ?? ''),
                'created_at' => (string) ($profile['created_at'] ?? ''),
            ],
            'wallet' => $wallet,
            'member_cards' => $memberCards,
            'coupons' => $coupons,
            'consume_records' => $consumeRecords,
            'orders' => $orders,
            'available_services' => $availableServices,
            'appointments' => $appointments,
            'online_payments' => is_array($onlinePayments) ? $onlinePayments : [],
            'payment_channels' => self::portalPaymentChannels($pdo),
        ]);
    }

    public static function createPortalAppointment(): void
    {
        $data = Request::jsonBody();
        $token = Request::str($data, 'token');
        if ($token === '') {
            Response::json(['message' => 'portal token is required'], 422);
            return;
        }

        $serviceId = Request::int($data, 'service_id', 0);
        if ($serviceId <= 0) {
            Response::json(['message' => 'service_id is required'], 422);
            return;
        }

        $startAt = Request::str($data, 'start_at');
        if ($startAt === '') {
            Response::json(['message' => 'start_at is required'], 422);
            return;
        }

        $startTs = strtotime($startAt);
        if ($startTs === false) {
            Response::json(['message' => 'invalid start_at or end_at'], 422);
            return;
        }
        $nowTs = time();
        if ($startTs < ($nowTs - 300)) {
            Response::json(['message' => 'appointment time must be in the future'], 422);
            return;
        }

        $pdo = Database::pdo();
        $clientIp = self::clientIp();
        if (!self::assertPortalAccessRateLimit($pdo, $clientIp)) {
            return;
        }
        $pdo->beginTransaction();
        try {
            CustomerPortalService::ensureTables($pdo);
            ServiceController::ensureServiceSchema($pdo);
            $resolved = CustomerPortalService::resolveActiveToken($pdo, $token);
            if (!is_array($resolved)) {
                $pdo->rollBack();
                self::handleInvalidPortalToken($pdo, $token);
                return;
            }

            if ((string) ($resolved['customer_status'] ?? '') !== 'active') {
                $pdo->rollBack();
                Response::json(['message' => 'customer account disabled'], 403);
                return;
            }
            CustomerPortalService::clearAuthFailures($pdo, self::tokenFailureKey($token));

            $customerId = (int) ($resolved['customer_id'] ?? 0);
            $storeId = (int) ($resolved['store_id'] ?? 0);
            if ($customerId <= 0) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $service = self::findPortalBookableService($pdo, $serviceId, $storeId);
            if (!is_array($service)) {
                $pdo->rollBack();
                Response::json(['message' => 'service not found or online booking disabled'], 404);
                return;
            }

            $durationMinutes = max(15, Request::int($data, 'duration_minutes', (int) ($service['duration_minutes'] ?? 60)));
            $endAtInput = Request::str($data, 'end_at');
            $endTs = $endAtInput !== '' ? strtotime($endAtInput) : ($startTs + $durationMinutes * 60);
            if ($endTs === false || $endTs <= $startTs) {
                $pdo->rollBack();
                Response::json(['message' => 'invalid start_at or end_at'], 422);
                return;
            }

            $startAtDb = gmdate('Y-m-d H:i:s', $startTs);
            $endAtDb = gmdate('Y-m-d H:i:s', $endTs);
            if (self::customerAppointmentConflict($pdo, $customerId, $startAtDb, $endAtDb)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer appointment time conflict'], 409);
                return;
            }

            $appointmentNo = 'QLA' . gmdate('ymdHis') . random_int(100, 999);
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_appointments
                 (appointment_no, store_id, customer_id, staff_id, service_id, start_at, end_at, status, source_channel, notes, created_by, created_at, updated_at)
                 VALUES
                 (:appointment_no, :store_id, :customer_id, NULL, :service_id, :start_at, :end_at, :status, :source_channel, :notes, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute([
                'appointment_no' => $appointmentNo,
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'service_id' => $serviceId,
                'start_at' => $startAtDb,
                'end_at' => $endAtDb,
                'status' => 'booked',
                'source_channel' => 'customer_portal',
                'notes' => Request::str($data, 'notes'),
                'created_by' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $appointmentId = (int) $pdo->lastInsertId();

            CustomerPortalService::touchTokenUse($pdo, (int) ($resolved['id'] ?? 0));
            Audit::log(
                0,
                'customer_portal.appointment.create',
                'appointment',
                $appointmentId,
                'Create appointment by customer portal',
                [
                    'appointment_no' => $appointmentNo,
                    'customer_id' => $customerId,
                    'store_id' => $storeId,
                    'service_id' => $serviceId,
                    'start_at' => $startAtDb,
                    'end_at' => $endAtDb,
                ]
            );

            $pdo->commit();

            $pushResult = null;
            $pushWarning = '';
            try {
                $pushResult = PushService::notifyAppointmentCreated($pdo, $appointmentId, 'appointment_created_portal');
            } catch (\Throwable $pushError) {
                $pushWarning = $pushError->getMessage();
            }

            $response = [
                'created' => true,
                'appointment' => [
                    'id' => $appointmentId,
                    'appointment_no' => $appointmentNo,
                    'service_id' => $serviceId,
                    'service_name' => (string) ($service['service_name'] ?? ''),
                    'start_at' => $startAtDb,
                    'end_at' => $endAtDb,
                    'status' => 'booked',
                ],
            ];

            if (is_array($pushResult)) {
                $response['push'] = $pushResult;
            }
            if ($pushWarning !== '') {
                $response['push_warning'] = '预约已创建，消息推送失败：' . $pushWarning;
            }

            Response::json($response, 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create customer portal appointment failed', $e);
        }
    }

    public static function createOnlinePayment(): void
    {
        $data = Request::jsonBody();
        $token = Request::str($data, 'token');
        if ($token === '') {
            Response::json(['message' => 'portal token is required'], 422);
            return;
        }

        $orderId = Request::int($data, 'order_id', 0);
        if ($orderId <= 0) {
            Response::json(['message' => 'order_id is required'], 422);
            return;
        }

        $channelInput = strtolower(Request::str($data, 'channel'));
        $sceneInput = strtolower(Request::str($data, 'scene'));
        $openid = Request::str($data, 'openid');

        $pdo = Database::pdo();
        $clientIp = self::clientIp();
        if (!self::assertPortalAccessRateLimit($pdo, $clientIp)) {
            return;
        }
        $pdo->beginTransaction();
        try {
            CustomerPortalService::ensureTables($pdo);
            $resolved = CustomerPortalService::resolveActiveToken($pdo, $token);
            if (!is_array($resolved)) {
                $pdo->rollBack();
                self::handleInvalidPortalToken($pdo, $token);
                return;
            }

            if ((string) ($resolved['customer_status'] ?? '') !== 'active') {
                $pdo->rollBack();
                Response::json(['message' => 'customer account disabled'], 403);
                return;
            }
            CustomerPortalService::clearAuthFailures($pdo, self::tokenFailureKey($token));

            $customerId = (int) ($resolved['customer_id'] ?? 0);
            $tokenStoreId = (int) ($resolved['store_id'] ?? 0);
            $order = self::findCustomerOrder($pdo, $orderId, $customerId);
            if (!is_array($order)) {
                $pdo->rollBack();
                Response::json(['message' => 'order not found'], 404);
                return;
            }
            $orderStoreId = (int) ($order['store_id'] ?? 0);
            if ($tokenStoreId > 0 && $orderStoreId > 0 && $tokenStoreId !== $orderStoreId) {
                $pdo->rollBack();
                Response::json(['message' => 'order does not belong to token store'], 403);
                return;
            }

            $payableAmount = round((float) ($order['payable_amount'] ?? 0), 2);
            $paidAmount = round((float) ($order['paid_amount'] ?? 0), 2);
            $outstanding = round(max(0.0, $payableAmount - $paidAmount), 2);
            if ($outstanding <= 0.0) {
                $pdo->rollBack();
                Response::json(['message' => 'order already fully paid'], 422);
                return;
            }

            $channels = self::portalPaymentChannels($pdo);
            $selection = self::resolvePortalChannelScene($channels, $channelInput, $sceneInput, $openid);

            $subject = '会员中心支付 ' . (string) ($order['order_no'] ?? ('#' . $orderId));
            $result = OnlinePaymentService::create($pdo, 0, [
                'order_id' => $orderId,
                'channel' => $selection['channel'],
                'scene' => $selection['scene'],
                'openid' => $openid,
                'subject' => $subject,
                'client_ip' => $clientIp,
            ]);

            CustomerPortalService::touchTokenUse($pdo, (int) ($resolved['id'] ?? 0));
            $pdo->commit();

            Response::json([
                'created' => true,
                'payment' => $result,
                'payment_channels' => $channels,
            ], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create customer online payment failed', $e);
        }
    }

    public static function syncOnlinePayment(): void
    {
        $data = Request::jsonBody();
        $token = Request::str($data, 'token');
        $paymentNo = Request::str($data, 'payment_no');
        if ($token === '') {
            Response::json(['message' => 'portal token is required'], 422);
            return;
        }
        if ($paymentNo === '') {
            Response::json(['message' => 'payment_no is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $clientIp = self::clientIp();
        if (!self::assertPortalAccessRateLimit($pdo, $clientIp)) {
            return;
        }
        $pdo->beginTransaction();
        try {
            CustomerPortalService::ensureTables($pdo);
            $resolved = CustomerPortalService::resolveActiveToken($pdo, $token);
            if (!is_array($resolved)) {
                $pdo->rollBack();
                self::handleInvalidPortalToken($pdo, $token);
                return;
            }

            if ((string) ($resolved['customer_status'] ?? '') !== 'active') {
                $pdo->rollBack();
                Response::json(['message' => 'customer account disabled'], 403);
                return;
            }
            CustomerPortalService::clearAuthFailures($pdo, self::tokenFailureKey($token));

            $customerId = (int) ($resolved['customer_id'] ?? 0);
            if (!self::paymentBelongsToCustomer($pdo, $paymentNo, $customerId)) {
                $pdo->rollBack();
                Response::json(['message' => 'payment not found'], 404);
                return;
            }

            $result = OnlinePaymentService::syncStatus($pdo, 0, $paymentNo);
            CustomerPortalService::touchTokenUse($pdo, (int) ($resolved['id'] ?? 0));
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
            Response::serverError('sync customer online payment failed', $e);
        }
    }

    public static function syncPendingPayments(): void
    {
        $data = Request::jsonBody();
        $token = Request::str($data, 'token');
        if ($token === '') {
            Response::json(['message' => 'portal token is required'], 422);
            return;
        }

        $limit = Request::int($data, 'limit', 8);
        $limit = max(1, min($limit, 20));

        $pdo = Database::pdo();
        $clientIp = self::clientIp();
        if (!self::assertPortalAccessRateLimit($pdo, $clientIp)) {
            return;
        }
        try {
            CustomerPortalService::ensureTables($pdo);
            $resolved = CustomerPortalService::resolveActiveToken($pdo, $token);
            if (!is_array($resolved)) {
                self::handleInvalidPortalToken($pdo, $token);
                return;
            }

            if ((string) ($resolved['customer_status'] ?? '') !== 'active') {
                Response::json(['message' => 'customer account disabled'], 403);
                return;
            }
            CustomerPortalService::clearAuthFailures($pdo, self::tokenFailureKey($token));

            $customerId = (int) ($resolved['customer_id'] ?? 0);
            if ($customerId <= 0) {
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $pendingRows = self::listPendingCustomerPayments($pdo, $customerId, $limit);
            $synced = [];
            $errors = [];
            foreach ($pendingRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $paymentNo = (string) ($row['payment_no'] ?? '');
                if ($paymentNo === '') {
                    continue;
                }

                $pdo->beginTransaction();
                try {
                    $result = OnlinePaymentService::syncStatus($pdo, 0, $paymentNo);
                    $pdo->commit();
                    $synced[] = [
                        'payment_no' => $paymentNo,
                        'status' => (string) ($result['status'] ?? ''),
                    ];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = [
                        'payment_no' => $paymentNo,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            CustomerPortalService::touchTokenUse($pdo, (int) ($resolved['id'] ?? 0));
            Response::json([
                'total_pending' => count($pendingRows),
                'synced_count' => count($synced),
                'error_count' => count($errors),
                'synced' => $synced,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('sync pending customer payments failed', $e);
        }
    }

    private static function assertPortalAccessRateLimit(PDO $pdo, string $clientIp): bool
    {
        $result = CustomerPortalService::checkAccessRateLimit($pdo, $clientIp);
        if (!empty($result['allowed'])) {
            return true;
        }

        Response::json([
            'message' => 'too many requests',
            'retry_after_seconds' => (int) ($result['retry_after_seconds'] ?? 60),
        ], 429);
        return false;
    }

    private static function handleInvalidPortalToken(PDO $pdo, string $token): void
    {
        $result = CustomerPortalService::recordAuthFailure($pdo, self::tokenFailureKey($token));
        if (!empty($result['locked'])) {
            Response::json([
                'message' => 'too many failed token attempts',
                'retry_after_seconds' => (int) ($result['retry_after_seconds'] ?? 300),
            ], 429);
            return;
        }

        Response::json(['message' => 'portal token invalid or expired'], 401);
    }

    private static function tokenFailureKey(string $token): string
    {
        $value = trim($token);
        if ($value === '') {
            return 'token_unknown';
        }

        return CustomerPortalService::hashToken($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listPortalBookableServices(PDO $pdo, int $storeId): array
    {
        $sql = 'SELECT id, service_code, store_id, service_name, category, duration_minutes, list_price, supports_online_booking
                FROM qiling_services
                WHERE status = :status
                  AND supports_online_booking = :supports_online_booking';
        $params = [
            'status' => 'active',
            'supports_online_booking' => 1,
        ];

        if ($storeId > 0) {
            $sql .= ' AND (store_id = :store_id OR store_id = 0)';
            $params['store_id'] = $storeId;
        }

        $sql .= ' ORDER BY store_id DESC, id DESC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findPortalBookableService(PDO $pdo, int $serviceId, int $storeId): ?array
    {
        $sql = 'SELECT id, service_code, store_id, service_name, category, duration_minutes, list_price, supports_online_booking, status
                FROM qiling_services
                WHERE id = :id
                  AND status = :status
                  AND supports_online_booking = :supports_online_booking';
        $params = [
            'id' => $serviceId,
            'status' => 'active',
            'supports_online_booking' => 1,
        ];
        if ($storeId > 0) {
            $sql .= ' AND (store_id = :store_id OR store_id = 0)';
            $params['store_id'] = $storeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listPortalAppointments(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.appointment_no, a.store_id, a.staff_id, a.service_id, a.start_at, a.end_at, a.status, a.source_channel, a.notes, a.created_at,
                    s.service_name, s.category,
                    u.username AS staff_name
             FROM qiling_appointments a
             LEFT JOIN qiling_services s ON s.id = a.service_id
             LEFT JOIN qiling_staff st ON st.id = a.staff_id
             LEFT JOIN qiling_users u ON u.id = st.user_id
             WHERE a.customer_id = :customer_id
             ORDER BY a.id DESC
             LIMIT 120'
        );
        $stmt->execute(['customer_id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function customerAppointmentConflict(PDO $pdo, int $customerId, string $startAt, string $endAt): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM qiling_appointments
             WHERE customer_id = :customer_id
               AND (status = :status_booked OR status = :status_completed)
               AND start_at < :end_at
               AND end_at > :start_at
             LIMIT 1'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'status_booked' => 'booked',
            'status_completed' => 'completed',
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private static function portalUrl(string $token): string
    {
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($appUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $base = str_replace('\\', '/', dirname($scriptName));
            $rootPath = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');
            $appUrl = $scheme . '://' . $host . $rootPath;
        }

        return $appUrl . '/customer/?token=' . rawurlencode($token);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function portalPaymentChannels(PDO $pdo): array
    {
        $cfg = PaymentConfigService::runtime($pdo);
        $channels = [];

        if (!empty($cfg['alipay_enabled'])) {
            $scenes = [];
            if (!empty($cfg['alipay_h5_enabled'])) {
                $scenes[] = ['code' => 'wap', 'name' => 'H5支付', 'requires_openid' => 0];
            }
            if (!empty($cfg['alipay_web_enabled'])) {
                $scenes[] = ['code' => 'page', 'name' => '网页支付', 'requires_openid' => 0];
            }
            if (!empty($cfg['alipay_f2f_enabled'])) {
                $scenes[] = ['code' => 'f2f', 'name' => '当面付二维码', 'requires_openid' => 0];
            }
            if (!empty($cfg['alipay_app_enabled'])) {
                $scenes[] = ['code' => 'app', 'name' => 'App支付', 'requires_openid' => 0];
            }
            if (!empty($scenes)) {
                $channels[] = [
                    'code' => 'alipay',
                    'name' => '支付宝',
                    'default_scene' => (string) ($scenes[0]['code'] ?? 'wap'),
                    'scenes' => $scenes,
                ];
            }
        }

        if (!empty($cfg['wechat_enabled'])) {
            $scenes = [];
            if (!empty($cfg['wechat_h5_enabled'])) {
                $scenes[] = ['code' => 'h5', 'name' => 'H5支付', 'requires_openid' => 0];
            }
            $scenes[] = ['code' => 'native', 'name' => '扫码支付', 'requires_openid' => 0];
            if (!empty($cfg['wechat_jsapi_enabled'])) {
                $scenes[] = ['code' => 'jsapi', 'name' => '公众号支付', 'requires_openid' => 1];
            }
            $channels[] = [
                'code' => 'wechat',
                'name' => '微信支付',
                'default_scene' => !empty($cfg['wechat_h5_enabled']) ? 'h5' : 'native',
                'scenes' => $scenes,
            ];
        }

        return $channels;
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     * @return array{channel:string,scene:string}
     */
    private static function resolvePortalChannelScene(array $channels, string $channelInput, string $sceneInput, string $openid): array
    {
        if (empty($channels)) {
            throw new \RuntimeException('online payment is not enabled');
        }

        $channelMap = [];
        foreach ($channels as $channelRow) {
            $code = strtolower(trim((string) ($channelRow['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $channelMap[$code] = $channelRow;
        }
        if (empty($channelMap)) {
            throw new \RuntimeException('online payment is not enabled');
        }

        $channel = $channelInput !== '' ? $channelInput : (string) ($channels[0]['code'] ?? '');
        if (!isset($channelMap[$channel])) {
            throw new \RuntimeException('payment channel unavailable');
        }

        $channelRow = $channelMap[$channel];
        $sceneRows = is_array($channelRow['scenes'] ?? null) ? $channelRow['scenes'] : [];
        $sceneMap = [];
        foreach ($sceneRows as $sceneRow) {
            if (!is_array($sceneRow)) {
                continue;
            }
            $sceneCode = strtolower(trim((string) ($sceneRow['code'] ?? '')));
            if ($sceneCode === '') {
                continue;
            }
            $sceneMap[$sceneCode] = $sceneRow;
        }

        if (empty($sceneMap)) {
            throw new \RuntimeException('payment scene unavailable');
        }

        $scene = $sceneInput !== '' ? $sceneInput : strtolower(trim((string) ($channelRow['default_scene'] ?? '')));
        if ($scene === '' || !isset($sceneMap[$scene])) {
            $scene = (string) array_key_first($sceneMap);
        }
        if ($scene === '' || !isset($sceneMap[$scene])) {
            throw new \RuntimeException('payment scene unavailable');
        }

        $requiresOpenid = ((int) ($sceneMap[$scene]['requires_openid'] ?? 0)) === 1;
        if ($requiresOpenid && trim($openid) === '') {
            throw new \RuntimeException('openid is required for selected payment scene');
        }

        return [
            'channel' => $channel,
            'scene' => $scene,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findCustomerOrder(PDO $pdo, int $orderId, int $customerId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, order_no, store_id, status, payable_amount, paid_amount
             FROM qiling_orders
             WHERE id = :id
               AND customer_id = :customer_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $orderId,
            'customer_id' => $customerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function paymentBelongsToCustomer(PDO $pdo, string $paymentNo, int $customerId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT op.id
             FROM qiling_online_payments op
             INNER JOIN qiling_orders o ON o.id = op.order_id
             WHERE op.payment_no = :payment_no
               AND o.customer_id = :customer_id
             LIMIT 1'
        );
        $stmt->execute([
            'payment_no' => $paymentNo,
            'customer_id' => $customerId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listPendingCustomerPayments(PDO $pdo, int $customerId, int $limit): array
    {
        $stmt = $pdo->prepare(
            'SELECT op.id, op.payment_no
             FROM qiling_online_payments op
             INNER JOIN qiling_orders o ON o.id = op.order_id
             WHERE o.customer_id = :customer_id
               AND op.status = :status
             ORDER BY op.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'status' => 'pending',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private static function clientIp(): string
    {
        $trustProxy = in_array(
            strtolower(trim((string) Config::get('TRUST_PROXY_HEADERS', 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if ($trustProxy) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $parts = explode(',', $forwarded);
                $ip = trim((string) ($parts[0] ?? ''));
                if ($ip !== '') {
                    return $ip;
                }
            }

            $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
            if ($real !== '') {
                return trim($real);
            }
        }

        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')) ?: '127.0.0.1';
    }

    private static function validateCustomPortalToken(string $token): string
    {
        $value = trim($token);
        if (preg_match('/^\d{4,6}$/', $value) !== 1) {
            return 'new_token must be 4-6 digits';
        }

        return '';
    }
}

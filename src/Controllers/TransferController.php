<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\AssetService;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\CustomerService;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class TransferController
{
    public static function couponTransfers(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT t.*, cp.coupon_code, cp.coupon_name,
                       fc.customer_no AS from_customer_no, fc.name AS from_customer_name, fc.mobile AS from_customer_mobile,
                       tc.customer_no AS to_customer_no, tc.name AS to_customer_name, tc.mobile AS to_customer_mobile,
                       u.username AS operator_username
                FROM qiling_coupon_transfers t
                INNER JOIN qiling_coupons cp ON cp.id = t.coupon_id
                INNER JOIN qiling_customers fc ON fc.id = t.from_customer_id
                INNER JOIN qiling_customers tc ON tc.id = t.to_customer_id
                LEFT JOIN qiling_users u ON u.id = t.operator_user_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND (t.from_store_id = :store_id OR t.to_store_id = :store_id2)';
            $params['store_id'] = $scopeStoreId;
            $params['store_id2'] = $scopeStoreId;
        }
        if ($customerId > 0) {
            $sql .= ' AND (t.from_customer_id = :customer_id OR t.to_customer_id = :customer_id2)';
            $params['customer_id'] = $customerId;
            $params['customer_id2'] = $customerId;
        }

        $sql .= ' ORDER BY t.id DESC LIMIT ' . $limit;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function memberCardTransfers(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT t.*, mc.card_no, mc.remaining_sessions, mc.total_sessions,
                       fc.customer_no AS from_customer_no, fc.name AS from_customer_name, fc.mobile AS from_customer_mobile,
                       tc.customer_no AS to_customer_no, tc.name AS to_customer_name, tc.mobile AS to_customer_mobile,
                       u.username AS operator_username
                FROM qiling_member_card_transfers t
                INNER JOIN qiling_member_cards mc ON mc.id = t.member_card_id
                INNER JOIN qiling_customers fc ON fc.id = t.from_customer_id
                INNER JOIN qiling_customers tc ON tc.id = t.to_customer_id
                LEFT JOIN qiling_users u ON u.id = t.operator_user_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND (t.from_store_id = :store_id OR t.to_store_id = :store_id2)';
            $params['store_id'] = $scopeStoreId;
            $params['store_id2'] = $scopeStoreId;
        }
        if ($customerId > 0) {
            $sql .= ' AND (t.from_customer_id = :customer_id OR t.to_customer_id = :customer_id2)';
            $params['customer_id'] = $customerId;
            $params['customer_id2'] = $customerId;
        }

        $sql .= ' ORDER BY t.id DESC LIMIT ' . $limit;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function transferCoupon(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $toCustomerId = Request::int($data, 'to_customer_id', 0);
        $toCustomerMobile = Request::str($data, 'to_customer_mobile');
        if ($toCustomerId <= 0 && $toCustomerMobile === '') {
            Response::json(['message' => 'to_customer_id or to_customer_mobile is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $coupon = self::findCouponForUpdate($pdo, $data);
            if (!is_array($coupon)) {
                $pdo->rollBack();
                Response::json(['message' => 'coupon not found'], 404);
                return;
            }

            $fromCustomer = CustomerService::loadById($pdo, (int) ($coupon['customer_id'] ?? 0), true);
            if (!is_array($fromCustomer)) {
                throw new \RuntimeException('source customer not found');
            }
            DataScope::assertStoreAccess($user, (int) ($fromCustomer['store_id'] ?? 0));

            $fromCustomerIdInput = Request::int($data, 'from_customer_id', 0);
            $fromCustomerMobileInput = Request::str($data, 'from_customer_mobile');
            if ($fromCustomerIdInput > 0 && $fromCustomerIdInput !== (int) ($fromCustomer['id'] ?? 0)) {
                throw new \RuntimeException('from_customer_id mismatch');
            }
            if ($fromCustomerMobileInput !== '' && $fromCustomerMobileInput !== (string) ($fromCustomer['mobile'] ?? '')) {
                throw new \RuntimeException('from_customer_mobile mismatch');
            }

            $remainCount = (int) ($coupon['remain_count'] ?? 0);
            if ($remainCount <= 0 || (string) ($coupon['status'] ?? '') !== 'active') {
                throw new \RuntimeException('coupon is not transferable');
            }
            if (!empty($coupon['expire_at']) && strtotime((string) $coupon['expire_at']) < time()) {
                throw new \RuntimeException('coupon is expired');
            }

            $toCustomer = CustomerService::findByIdOrMobile($pdo, $toCustomerId, $toCustomerMobile, true);
            if (!is_array($toCustomer)) {
                throw new \RuntimeException('target customer not found');
            }
            DataScope::assertStoreAccess($user, (int) ($toCustomer['store_id'] ?? 0));

            $fromCustomerId = (int) ($fromCustomer['id'] ?? 0);
            $toCustomerId = (int) ($toCustomer['id'] ?? 0);
            if ($fromCustomerId === $toCustomerId) {
                throw new \RuntimeException('cannot transfer to same customer');
            }

            $now = gmdate('Y-m-d H:i:s');
            $updateCoupon = $pdo->prepare(
                'UPDATE qiling_coupons
                 SET customer_id = :customer_id,
                     store_id = :store_id,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCoupon->execute([
                'customer_id' => $toCustomerId,
                'store_id' => (int) ($toCustomer['store_id'] ?? 0),
                'updated_at' => $now,
                'id' => (int) ($coupon['id'] ?? 0),
            ]);

            AssetService::insertCouponLog(
                $pdo,
                (int) ($coupon['id'] ?? 0),
                $fromCustomerId,
                0,
                'transfer_out',
                0,
                $remainCount,
                $remainCount,
                (int) $user['id'],
                '优惠券转赠-转出',
                $now
            );
            AssetService::insertCouponLog(
                $pdo,
                (int) ($coupon['id'] ?? 0),
                $toCustomerId,
                0,
                'transfer_in',
                0,
                $remainCount,
                $remainCount,
                (int) $user['id'],
                '优惠券转赠-转入',
                $now
            );

            $transferNo = self::generateTransferNo($pdo, 'qiling_coupon_transfers', 'QLTFC');
            $insertTransfer = $pdo->prepare(
                'INSERT INTO qiling_coupon_transfers
                 (transfer_no, coupon_id, from_customer_id, to_customer_id, from_store_id, to_store_id, operator_user_id, note, status, created_at)
                 VALUES
                 (:transfer_no, :coupon_id, :from_customer_id, :to_customer_id, :from_store_id, :to_store_id, :operator_user_id, :note, :status, :created_at)'
            );
            $insertTransfer->execute([
                'transfer_no' => $transferNo,
                'coupon_id' => (int) ($coupon['id'] ?? 0),
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $toCustomerId,
                'from_store_id' => (int) ($fromCustomer['store_id'] ?? 0),
                'to_store_id' => (int) ($toCustomer['store_id'] ?? 0),
                'operator_user_id' => (int) $user['id'],
                'note' => Request::str($data, 'note', '后台优惠券转赠'),
                'status' => 'success',
                'created_at' => $now,
            ]);
            $transferId = (int) $pdo->lastInsertId();

            Audit::log((int) $user['id'], 'coupon.transfer', 'coupon_transfer', $transferId, 'Transfer coupon', [
                'transfer_no' => $transferNo,
                'coupon_id' => (int) ($coupon['id'] ?? 0),
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $toCustomerId,
            ]);

            $pdo->commit();
            Response::json([
                'transfer_id' => $transferId,
                'transfer_no' => $transferNo,
                'coupon_id' => (int) ($coupon['id'] ?? 0),
                'coupon_code' => (string) ($coupon['coupon_code'] ?? ''),
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $toCustomerId,
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
            Response::serverError('transfer coupon failed', $e);
        }
    }

    public static function transferMemberCard(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $toCustomerId = Request::int($data, 'to_customer_id', 0);
        $toCustomerMobile = Request::str($data, 'to_customer_mobile');
        if ($toCustomerId <= 0 && $toCustomerMobile === '') {
            Response::json(['message' => 'to_customer_id or to_customer_mobile is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $card = self::findMemberCardForUpdate($pdo, $data);
            if (!is_array($card)) {
                $pdo->rollBack();
                Response::json(['message' => 'member card not found'], 404);
                return;
            }

            $fromCustomer = CustomerService::loadById($pdo, (int) ($card['customer_id'] ?? 0), true);
            if (!is_array($fromCustomer)) {
                throw new \RuntimeException('source customer not found');
            }
            DataScope::assertStoreAccess($user, (int) ($fromCustomer['store_id'] ?? 0));

            $fromCustomerIdInput = Request::int($data, 'from_customer_id', 0);
            $fromCustomerMobileInput = Request::str($data, 'from_customer_mobile');
            if ($fromCustomerIdInput > 0 && $fromCustomerIdInput !== (int) ($fromCustomer['id'] ?? 0)) {
                throw new \RuntimeException('from_customer_id mismatch');
            }
            if ($fromCustomerMobileInput !== '' && $fromCustomerMobileInput !== (string) ($fromCustomer['mobile'] ?? '')) {
                throw new \RuntimeException('from_customer_mobile mismatch');
            }

            $remaining = (int) ($card['remaining_sessions'] ?? 0);
            $status = (string) ($card['status'] ?? '');
            if ($remaining <= 0 || in_array($status, ['expired', 'cancelled'], true)) {
                throw new \RuntimeException('member card is not transferable');
            }

            $toCustomer = CustomerService::findByIdOrMobile($pdo, $toCustomerId, $toCustomerMobile, true);
            if (!is_array($toCustomer)) {
                throw new \RuntimeException('target customer not found');
            }
            DataScope::assertStoreAccess($user, (int) ($toCustomer['store_id'] ?? 0));

            $fromCustomerId = (int) ($fromCustomer['id'] ?? 0);
            $toCustomerId = (int) ($toCustomer['id'] ?? 0);
            if ($fromCustomerId === $toCustomerId) {
                throw new \RuntimeException('cannot transfer to same customer');
            }

            $now = gmdate('Y-m-d H:i:s');
            $updateCard = $pdo->prepare(
                'UPDATE qiling_member_cards
                 SET customer_id = :customer_id,
                     store_id = :store_id,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCard->execute([
                'customer_id' => $toCustomerId,
                'store_id' => (int) ($toCustomer['store_id'] ?? 0),
                'updated_at' => $now,
                'id' => (int) ($card['id'] ?? 0),
            ]);

            AssetService::insertMemberCardLog(
                $pdo,
                (int) ($card['id'] ?? 0),
                $fromCustomerId,
                'transfer_out',
                0,
                $remaining,
                $remaining,
                (int) $user['id'],
                '次卡转赠-转出',
                $now
            );
            AssetService::insertMemberCardLog(
                $pdo,
                (int) ($card['id'] ?? 0),
                $toCustomerId,
                'transfer_in',
                0,
                $remaining,
                $remaining,
                (int) $user['id'],
                '次卡转赠-转入',
                $now
            );

            $transferNo = self::generateTransferNo($pdo, 'qiling_member_card_transfers', 'QLTFM');
            $insertTransfer = $pdo->prepare(
                'INSERT INTO qiling_member_card_transfers
                 (transfer_no, member_card_id, from_customer_id, to_customer_id, from_store_id, to_store_id, operator_user_id, note, status, created_at)
                 VALUES
                 (:transfer_no, :member_card_id, :from_customer_id, :to_customer_id, :from_store_id, :to_store_id, :operator_user_id, :note, :status, :created_at)'
            );
            $insertTransfer->execute([
                'transfer_no' => $transferNo,
                'member_card_id' => (int) ($card['id'] ?? 0),
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $toCustomerId,
                'from_store_id' => (int) ($fromCustomer['store_id'] ?? 0),
                'to_store_id' => (int) ($toCustomer['store_id'] ?? 0),
                'operator_user_id' => (int) $user['id'],
                'note' => Request::str($data, 'note', '后台次卡转赠'),
                'status' => 'success',
                'created_at' => $now,
            ]);
            $transferId = (int) $pdo->lastInsertId();

            Audit::log((int) $user['id'], 'member_card.transfer', 'member_card_transfer', $transferId, 'Transfer member card', [
                'transfer_no' => $transferNo,
                'member_card_id' => (int) ($card['id'] ?? 0),
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $toCustomerId,
            ]);

            $pdo->commit();
            Response::json([
                'transfer_id' => $transferId,
                'transfer_no' => $transferNo,
                'member_card_id' => (int) ($card['id'] ?? 0),
                'card_no' => (string) ($card['card_no'] ?? ''),
                'from_customer_id' => $fromCustomerId,
                'to_customer_id' => $toCustomerId,
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
            Response::serverError('transfer member card failed', $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function findCouponForUpdate(PDO $pdo, array $data): ?array
    {
        $couponId = Request::int($data, 'coupon_id', 0);
        $couponCode = Request::str($data, 'coupon_code');
        if ($couponId <= 0 && $couponCode === '') {
            throw new \RuntimeException('coupon_id or coupon_code is required');
        }

        $sql = 'SELECT id, coupon_code, customer_id, store_id, status, remain_count, expire_at
                FROM qiling_coupons
                WHERE 1 = 1';
        $params = [];
        if ($couponId > 0) {
            $sql .= ' AND id = :id';
            $params['id'] = $couponId;
        }
        if ($couponCode !== '') {
            $sql .= ' AND coupon_code = :coupon_code';
            $params['coupon_code'] = $couponCode;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function findMemberCardForUpdate(PDO $pdo, array $data): ?array
    {
        $memberCardId = Request::int($data, 'member_card_id', 0);
        $cardNo = Request::str($data, 'card_no');
        if ($memberCardId <= 0 && $cardNo === '') {
            throw new \RuntimeException('member_card_id or card_no is required');
        }

        $sql = 'SELECT id, card_no, customer_id, store_id, remaining_sessions, total_sessions, status
                FROM qiling_member_cards
                WHERE 1 = 1';
        $params = [];
        if ($memberCardId > 0) {
            $sql .= ' AND id = :id';
            $params['id'] = $memberCardId;
        }
        if ($cardNo !== '') {
            $sql .= ' AND card_no = :card_no';
            $params['card_no'] = $cardNo;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function generateTransferNo(PDO $pdo, string $table, string $prefix): string
    {
        for ($i = 0; $i < 10; $i++) {
            $transferNo = $prefix . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE transfer_no = :transfer_no LIMIT 1');
            $stmt->execute(['transfer_no' => $transferNo]);
            if (!$stmt->fetchColumn()) {
                return $transferNo;
            }
        }

        throw new \RuntimeException('failed to generate transfer_no');
    }
}

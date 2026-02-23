<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\AssetService;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\OpenGiftService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class AdminCustomerController
{
    public static function onboard(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);

        $data = Request::jsonBody();
        $customerData = isset($data['customer']) && is_array($data['customer']) ? $data['customer'] : $data;

        $customerIdInput = Request::int($customerData, 'customer_id', 0);
        $mobile = Request::str($customerData, 'mobile');
        $name = Request::str($customerData, 'name');
        if ($mobile === '' && $customerIdInput <= 0) {
            Response::json(['message' => 'customer.mobile is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $customer = self::findCustomerForUpdate($pdo, $customerData, $mobile, $user);
            $now = gmdate('Y-m-d H:i:s');
            $created = false;
            $storeId = 0;

            if (!is_array($customer)) {
                if ($name === '') {
                    throw new \RuntimeException('customer.name is required for new customer');
                }

                $storeId = DataScope::resolveInputStoreId($user, Request::int($customerData, 'store_id', 0));
                $customerNo = 'QLC' . gmdate('ymd') . random_int(1000, 9999);
                $insertCustomer = $pdo->prepare(
                    'INSERT INTO qiling_customers
                     (customer_no, store_id, name, mobile, gender, birthday, source_channel, skin_type, allergies, notes, total_spent, visit_count, last_visit_at, status, created_at, updated_at)
                     VALUES
                     (:customer_no, :store_id, :name, :mobile, :gender, :birthday, :source_channel, :skin_type, :allergies, :notes, 0.00, 0, :last_visit_at, :status, :created_at, :updated_at)'
                );
                $insertCustomer->execute([
                    'customer_no' => $customerNo,
                    'store_id' => $storeId,
                    'name' => $name,
                    'mobile' => $mobile,
                    'gender' => self::normalizeGender(Request::str($customerData, 'gender', 'unknown')),
                    'birthday' => self::nullableDate(Request::str($customerData, 'birthday')),
                    'source_channel' => Request::str($customerData, 'source_channel'),
                    'skin_type' => Request::str($customerData, 'skin_type'),
                    'allergies' => Request::str($customerData, 'allergies'),
                    'notes' => Request::str($customerData, 'notes'),
                    'last_visit_at' => null,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $customerId = (int) $pdo->lastInsertId();
                $created = true;
            } else {
                $customerId = (int) $customer['id'];
                DataScope::assertStoreAccess($user, (int) ($customer['store_id'] ?? 0));
                $storeId = DataScope::resolveInputStoreId($user, Request::int($customerData, 'store_id', (int) ($customer['store_id'] ?? 0)));
                $updateCustomer = $pdo->prepare(
                    'UPDATE qiling_customers
                     SET store_id = :store_id,
                         name = :name,
                         gender = :gender,
                         birthday = :birthday,
                         source_channel = :source_channel,
                         skin_type = :skin_type,
                         allergies = :allergies,
                         notes = :notes,
                         status = :status,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateCustomer->execute([
                    'store_id' => $storeId,
                    'name' => $name !== '' ? $name : (string) $customer['name'],
                    'gender' => self::normalizeGender(Request::str($customerData, 'gender', (string) $customer['gender'])),
                    'birthday' => self::nullableDate(Request::str($customerData, 'birthday', (string) ($customer['birthday'] ?? ''))),
                    'source_channel' => Request::str($customerData, 'source_channel', (string) ($customer['source_channel'] ?? '')),
                    'skin_type' => Request::str($customerData, 'skin_type', (string) ($customer['skin_type'] ?? '')),
                    'allergies' => Request::str($customerData, 'allergies', (string) ($customer['allergies'] ?? '')),
                    'notes' => Request::str($customerData, 'notes', (string) ($customer['notes'] ?? '')),
                    'status' => Request::str($customerData, 'status', (string) ($customer['status'] ?? 'active')),
                    'updated_at' => $now,
                    'id' => $customerId,
                ]);
            }
            self::ensureWallet($pdo, $customerId, $now);

            $giftBalance = max(0.0, (float) ($data['gift_balance'] ?? 0));
            if ($giftBalance > 0) {
                self::changeWallet(
                    $pdo,
                    $customerId,
                    $giftBalance,
                    'gift',
                    0,
                    (int) $user['id'],
                    Request::str($data, 'gift_balance_note', '新客建档赠送余额'),
                    $now
                );
            }

            $giftCards = isset($data['gift_member_cards']) && is_array($data['gift_member_cards']) ? $data['gift_member_cards'] : [];
            $giftCardIds = self::giftMemberCards($pdo, $customerId, $storeId, $giftCards, (int) $user['id'], $now);

            $giftCoupons = isset($data['gift_coupons']) && is_array($data['gift_coupons']) ? $data['gift_coupons'] : [];
            $giftCouponIds = self::giftCoupons($pdo, $customerId, $storeId, $giftCoupons, (int) $user['id'], $now);
            $openGiftResult = null;
            $warnings = [];

            try {
                $openGiftResult = OpenGiftService::trigger(
                    $pdo,
                    'onboard',
                    (int) $user['id'],
                    $customerId,
                    $storeId,
                    'customer_onboard',
                    $customerId
                );
            } catch (\Throwable $t) {
                $warnings[] = 'open_gift_failed: ' . $t->getMessage();
            }

            Audit::log((int) $user['id'], 'admin.customer.onboard', 'customer', $customerId, 'Admin onboard customer', [
                'created' => $created ? 1 : 0,
                'gift_balance' => $giftBalance,
                'gift_member_cards' => count($giftCardIds),
                'gift_coupons' => count($giftCouponIds),
                'open_gift_triggered' => is_array($openGiftResult) && (($openGiftResult['triggered'] ?? false) === true) ? 1 : 0,
            ]);

            $pdo->commit();
            Response::json([
                'customer_id' => $customerId,
                'created' => $created,
                'gift_balance' => $giftBalance,
                'gift_member_card_ids' => $giftCardIds,
                'gift_coupon_ids' => $giftCouponIds,
                'open_gift' => $openGiftResult,
                'warnings' => $warnings,
            ], $created ? 201 : 200);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('onboard customer failed', $e);
        }
    }

    public static function consumeRecord(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $consumeAmount = max(0.0, (float) ($data['consume_amount'] ?? 0));
        $deductBalanceAmount = max(0.0, (float) ($data['deduct_balance_amount'] ?? 0));
        $couponUsages = isset($data['coupon_usages']) && is_array($data['coupon_usages']) ? $data['coupon_usages'] : [];
        $memberCardUsages = isset($data['member_card_usages']) && is_array($data['member_card_usages']) ? $data['member_card_usages'] : [];
        $note = Request::str($data, 'note', '后台登记消费');

        if ($consumeAmount <= 0 && $deductBalanceAmount <= 0 && empty($couponUsages) && empty($memberCardUsages)) {
            Response::json(['message' => 'at least one consume operation is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $customer = self::resolveCustomerForUpdate($pdo, $data, $user);
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $customerId = (int) $customer['id'];
            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', (int) ($customer['store_id'] ?? 0)));
            if ((int) ($customer['store_id'] ?? 0) > 0 && $storeId !== (int) $customer['store_id']) {
                throw new \RuntimeException('customer store mismatch');
            }
            $now = gmdate('Y-m-d H:i:s');
            $couponDeductAmount = 0.0;
            $memberCardDeductSessions = 0;
            $couponUsageDetails = [];
            $cardUsageDetails = [];

            self::ensureWallet($pdo, $customerId, $now);

            if ($deductBalanceAmount > 0) {
                self::changeWallet(
                    $pdo,
                    $customerId,
                    -$deductBalanceAmount,
                    'deduct',
                    0,
                    (int) $user['id'],
                    $note,
                    $now
                );
            }

            foreach ($couponUsages as $usage) {
                if (is_string($usage)) {
                    $usage = ['coupon_code' => $usage, 'use_count' => 1];
                }
                if (!is_array($usage)) {
                    continue;
                }

                $couponCode = Request::str($usage, 'coupon_code');
                $useCount = max(1, Request::int($usage, 'use_count', 1));
                if ($couponCode === '') {
                    continue;
                }

                $coupon = self::findCouponForUpdate($pdo, $customerId, $couponCode);
                if (!is_array($coupon)) {
                    throw new \RuntimeException('coupon not found: ' . $couponCode);
                }

                $beforeCount = (int) $coupon['remain_count'];
                if ($beforeCount < $useCount) {
                    throw new \RuntimeException('coupon remain_count not enough: ' . $couponCode);
                }

                if (!empty($coupon['expire_at']) && strtotime((string) $coupon['expire_at']) < strtotime($now)) {
                    throw new \RuntimeException('coupon expired: ' . $couponCode);
                }

                $afterCount = $beforeCount - $useCount;
                $couponStatus = $afterCount > 0 ? 'active' : 'used';
                $couponDeductAmount += (float) $coupon['face_value'] * $useCount;

                $updateCoupon = $pdo->prepare(
                    'UPDATE qiling_coupons
                     SET remain_count = :remain_count,
                         status = :status,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateCoupon->execute([
                    'remain_count' => $afterCount,
                    'status' => $couponStatus,
                    'updated_at' => $now,
                    'id' => (int) $coupon['id'],
                ]);

                AssetService::insertCouponLog(
                    $pdo,
                    (int) $coupon['id'],
                    $customerId,
                    0,
                    'consume',
                    -$useCount,
                    $beforeCount,
                    $afterCount,
                    (int) $user['id'],
                    $note,
                    $now
                );

                $couponUsageDetails[] = [
                    'coupon_id' => (int) $coupon['id'],
                    'coupon_code' => (string) $coupon['coupon_code'],
                    'use_count' => $useCount,
                    'face_value' => (float) $coupon['face_value'],
                    'deduct_amount' => round((float) $coupon['face_value'] * $useCount, 2),
                    'before_count' => $beforeCount,
                    'after_count' => $afterCount,
                ];
            }

            foreach ($memberCardUsages as $usage) {
                if (!is_array($usage)) {
                    continue;
                }

                $consumeSessions = max(1, Request::int($usage, 'consume_sessions', 1));
                $card = self::findMemberCardForUpdate($pdo, $customerId, $usage);
                if (!is_array($card)) {
                    throw new \RuntimeException('member card not found for consume');
                }

                $beforeSessions = (int) $card['remaining_sessions'];
                if ($beforeSessions < $consumeSessions) {
                    throw new \RuntimeException('member card remaining sessions not enough: ' . $card['card_no']);
                }

                $afterSessions = $beforeSessions - $consumeSessions;
                $status = $afterSessions > 0 ? 'active' : 'depleted';

                $updateCard = $pdo->prepare(
                    'UPDATE qiling_member_cards
                     SET remaining_sessions = :remaining_sessions,
                         status = :status,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateCard->execute([
                    'remaining_sessions' => $afterSessions,
                    'status' => $status,
                    'updated_at' => $now,
                    'id' => (int) $card['id'],
                ]);

                AssetService::insertMemberCardLog(
                    $pdo,
                    (int) $card['id'],
                    $customerId,
                    'admin_consume_settle',
                    -$consumeSessions,
                    $beforeSessions,
                    $afterSessions,
                    (int) $user['id'],
                    $note,
                    $now
                );

                $memberCardDeductSessions += $consumeSessions;
                $cardUsageDetails[] = [
                    'member_card_id' => (int) $card['id'],
                    'card_no' => (string) $card['card_no'],
                    'consume_sessions' => $consumeSessions,
                    'before_sessions' => $beforeSessions,
                    'after_sessions' => $afterSessions,
                ];
            }

            $consumeNo = self::generateConsumeNo($pdo);
            $insertConsumeRecord = $pdo->prepare(
                'INSERT INTO qiling_customer_consume_records
                 (consume_no, customer_id, store_id, consume_amount, deduct_balance_amount, deduct_coupon_amount, deduct_member_card_sessions, coupon_usage_json, member_card_usage_json, note, operator_user_id, created_at)
                 VALUES
                 (:consume_no, :customer_id, :store_id, :consume_amount, :deduct_balance_amount, :deduct_coupon_amount, :deduct_member_card_sessions, :coupon_usage_json, :member_card_usage_json, :note, :operator_user_id, :created_at)'
            );
            $insertConsumeRecord->execute([
                'consume_no' => $consumeNo,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'consume_amount' => round($consumeAmount, 2),
                'deduct_balance_amount' => round($deductBalanceAmount, 2),
                'deduct_coupon_amount' => round($couponDeductAmount, 2),
                'deduct_member_card_sessions' => $memberCardDeductSessions,
                'coupon_usage_json' => json_encode($couponUsageDetails, JSON_UNESCAPED_UNICODE),
                'member_card_usage_json' => json_encode($cardUsageDetails, JSON_UNESCAPED_UNICODE),
                'note' => $note,
                'operator_user_id' => (int) $user['id'],
                'created_at' => $now,
            ]);
            $consumeRecordId = (int) $pdo->lastInsertId();

            $updateCustomer = $pdo->prepare(
                'UPDATE qiling_customers
                 SET total_spent = total_spent + :consume_amount,
                     visit_count = visit_count + :visit_delta,
                     last_visit_at = :last_visit_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCustomer->execute([
                'consume_amount' => round($consumeAmount, 2),
                'visit_delta' => $consumeAmount > 0 ? 1 : 0,
                'last_visit_at' => $now,
                'updated_at' => $now,
                'id' => $customerId,
            ]);

            Audit::log((int) $user['id'], 'admin.customer.consume_record', 'customer_consume_record', $consumeRecordId, 'Admin record customer consume', [
                'consume_no' => $consumeNo,
                'consume_amount' => round($consumeAmount, 2),
                'deduct_balance_amount' => round($deductBalanceAmount, 2),
                'deduct_coupon_amount' => round($couponDeductAmount, 2),
                'deduct_member_card_sessions' => $memberCardDeductSessions,
            ]);

            $pdo->commit();
            Response::json([
                'consume_record_id' => $consumeRecordId,
                'consume_no' => $consumeNo,
                'customer_id' => $customerId,
                'consume_amount' => round($consumeAmount, 2),
                'deduct_balance_amount' => round($deductBalanceAmount, 2),
                'deduct_coupon_amount' => round($couponDeductAmount, 2),
                'deduct_member_card_sessions' => $memberCardDeductSessions,
                'coupon_usages' => $couponUsageDetails,
                'member_card_usages' => $cardUsageDetails,
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
            Response::serverError('record consume failed', $e);
        }
    }

    public static function adjustWallet(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $mode = Request::str($data, 'mode', 'delta');
        if (!in_array($mode, ['delta', 'set_balance'], true)) {
            Response::json(['message' => 'mode must be delta or set_balance'], 422);
            return;
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        $changeType = Request::str($data, 'change_type', 'adjust');
        if (!in_array($changeType, ['adjust', 'gift', 'recharge', 'deduct'], true)) {
            $changeType = 'adjust';
        }

        $note = Request::str($data, 'note', '后台手工调整余额');

        if ($mode === 'delta' && abs($amount) < 0.01) {
            Response::json(['message' => 'amount cannot be zero for delta mode'], 422);
            return;
        }

        if ($mode === 'set_balance' && $amount < 0) {
            Response::json(['message' => 'amount must be >= 0 for set_balance mode'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $customer = self::resolveCustomerForUpdate($pdo, $data, $user);
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $customerId = (int) $customer['id'];
            $now = gmdate('Y-m-d H:i:s');

            self::ensureWallet($pdo, $customerId, $now);
            $wallet = self::findWalletForUpdate($pdo, $customerId);
            if (!is_array($wallet)) {
                throw new \RuntimeException('wallet not found');
            }

            $beforeBalance = round((float) $wallet['balance'], 2);
            $deltaAmount = $mode === 'set_balance' ? round($amount - $beforeBalance, 2) : $amount;
            $usedChangeType = $changeType;

            if ($mode === 'set_balance') {
                $usedChangeType = 'adjust';
            } elseif ($usedChangeType === 'deduct' && $deltaAmount > 0) {
                $deltaAmount = -$deltaAmount;
            }

            if (in_array($usedChangeType, ['gift', 'recharge'], true) && $deltaAmount < 0) {
                throw new \RuntimeException($usedChangeType . ' change_type requires positive amount');
            }

            if ($usedChangeType === 'deduct' && $deltaAmount > 0) {
                throw new \RuntimeException('deduct change_type requires negative amount');
            }

            if (abs($deltaAmount) >= 0.01) {
                self::changeWallet(
                    $pdo,
                    $customerId,
                    $deltaAmount,
                    $usedChangeType,
                    0,
                    (int) $user['id'],
                    $note,
                    $now
                );
            }

            $afterWallet = self::findWalletForUpdate($pdo, $customerId);
            if (!is_array($afterWallet)) {
                throw new \RuntimeException('wallet not found');
            }

            $afterBalance = round((float) $afterWallet['balance'], 2);

            Audit::log((int) $user['id'], 'admin.customer.wallet_adjust', 'customer_wallet', $customerId, 'Admin adjust customer wallet', [
                'mode' => $mode,
                'change_type' => $usedChangeType,
                'amount' => $amount,
                'delta_amount' => round($afterBalance - $beforeBalance, 2),
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
            ]);

            $pdo->commit();
            Response::json([
                'customer_id' => $customerId,
                'mode' => $mode,
                'change_type' => $usedChangeType,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
                'delta_amount' => round($afterBalance - $beforeBalance, 2),
                'wallet' => [
                    'balance' => $afterWallet['balance'],
                    'total_recharge' => $afterWallet['total_recharge'],
                    'total_gift' => $afterWallet['total_gift'],
                    'total_spent' => $afterWallet['total_spent'],
                ],
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
            Response::serverError('adjust wallet failed', $e);
        }
    }

    public static function adjustCoupon(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $mode = Request::str($data, 'mode', 'grant');
        if (!in_array($mode, ['grant', 'set_remaining', 'delta_count'], true)) {
            Response::json(['message' => 'mode must be grant, set_remaining or delta_count'], 422);
            return;
        }

        $note = Request::str($data, 'note', '后台手工调整优惠券');
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $customer = self::resolveCustomerForUpdate($pdo, $data, $user);
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $customerId = (int) $customer['id'];
            $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', (int) ($customer['store_id'] ?? 0)));
            if ((int) ($customer['store_id'] ?? 0) > 0 && $storeId !== (int) $customer['store_id']) {
                throw new \RuntimeException('customer store mismatch');
            }
            $now = gmdate('Y-m-d H:i:s');

            if ($mode === 'grant') {
                $couponName = Request::str($data, 'coupon_name');
                if ($couponName === '') {
                    throw new \RuntimeException('coupon_name is required for grant mode');
                }

                $couponType = Request::str($data, 'coupon_type', 'cash');
                if (!in_array($couponType, ['cash', 'discount'], true)) {
                    $couponType = 'cash';
                }

                $faceValue = max(0.0, round((float) ($data['face_value'] ?? 0), 2));
                $minSpend = max(0.0, round((float) ($data['min_spend'] ?? 0), 2));
                $count = max(1, Request::int($data, 'count', 1));
                $expireAt = self::nullableDateTime(Request::str($data, 'expire_at'));
                $coupon = AssetService::issueCoupon(
                    $pdo,
                    $customerId,
                    $storeId,
                    [
                        'coupon_name' => $couponName,
                        'coupon_type' => $couponType,
                        'face_value' => $faceValue,
                        'min_spend' => $minSpend,
                        'remain_count' => $count,
                        'expire_at' => $expireAt,
                    ],
                    (int) $user['id'],
                    'manual',
                    'manual_grant',
                    $note,
                    $now
                );
                $couponId = (int) ($coupon['coupon_id'] ?? 0);
                $couponCode = (string) ($coupon['coupon_code'] ?? '');
                $status = (string) ($coupon['status'] ?? 'active');

                Audit::log((int) $user['id'], 'admin.customer.coupon_grant', 'coupon', $couponId, 'Admin grant coupon', [
                    'coupon_code' => $couponCode,
                    'coupon_name' => $couponName,
                    'remain_count' => $count,
                    'store_id' => $storeId,
                ]);

                $pdo->commit();
                Response::json([
                    'coupon_id' => $couponId,
                    'coupon_code' => $couponCode,
                    'customer_id' => $customerId,
                    'remain_count' => $count,
                    'status' => $status,
                ], 201);
                return;
            }

            $coupon = self::findCouponByReferenceForUpdate($pdo, $customerId, $data);
            if (!is_array($coupon)) {
                $pdo->rollBack();
                Response::json(['message' => 'coupon not found'], 404);
                return;
            }

            $beforeCount = (int) $coupon['remain_count'];
            if ($mode === 'set_remaining') {
                $afterCount = max(0, Request::int($data, 'count', $beforeCount));
            } else {
                $delta = Request::int($data, 'delta_count', 0);
                if ($delta === 0) {
                    throw new \RuntimeException('delta_count cannot be zero');
                }
                $afterCount = $beforeCount + $delta;
            }

            if ($afterCount < 0) {
                throw new \RuntimeException('coupon remain_count cannot be negative');
            }

            $statusInput = Request::str($data, 'status');
            $status = $statusInput !== ''
                ? self::normalizeCouponStatus($statusInput, $afterCount)
                : ($afterCount > 0 ? 'active' : 'used');

            $updateCoupon = $pdo->prepare(
                'UPDATE qiling_coupons
                 SET remain_count = :remain_count,
                     status = :status,
                     expire_at = :expire_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCoupon->execute([
                'remain_count' => $afterCount,
                'status' => $status,
                'expire_at' => self::nullableDateTime(Request::str($data, 'expire_at', (string) ($coupon['expire_at'] ?? ''))),
                'updated_at' => $now,
                'id' => (int) $coupon['id'],
            ]);

            AssetService::insertCouponLog(
                $pdo,
                (int) $coupon['id'],
                $customerId,
                0,
                'manual_adjust',
                $afterCount - $beforeCount,
                $beforeCount,
                $afterCount,
                (int) $user['id'],
                $note,
                $now
            );

            Audit::log((int) $user['id'], 'admin.customer.coupon_adjust', 'coupon', (int) $coupon['id'], 'Admin adjust coupon', [
                'mode' => $mode,
                'before_count' => $beforeCount,
                'after_count' => $afterCount,
                'status' => $status,
            ]);

            $pdo->commit();
            Response::json([
                'coupon_id' => (int) $coupon['id'],
                'coupon_code' => (string) $coupon['coupon_code'],
                'customer_id' => $customerId,
                'mode' => $mode,
                'before_count' => $beforeCount,
                'after_count' => $afterCount,
                'delta_count' => $afterCount - $beforeCount,
                'status' => $status,
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
            Response::serverError('adjust coupon failed', $e);
        }
    }

    public static function adjustConsumeRecord(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $consumeRecordId = Request::int($data, 'consume_record_id', 0);
        $consumeNo = Request::str($data, 'consume_no');
        if ($consumeRecordId <= 0 && $consumeNo === '') {
            Response::json(['message' => 'consume_record_id or consume_no is required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $sql = 'SELECT id, consume_no, customer_id, store_id, consume_amount, deduct_balance_amount, deduct_coupon_amount, deduct_member_card_sessions, note
                    FROM qiling_customer_consume_records
                    WHERE 1 = 1';
            $params = [];

            if ($consumeRecordId > 0) {
                $sql .= ' AND id = :id';
                $params['id'] = $consumeRecordId;
            }
            if ($consumeNo !== '') {
                $sql .= ' AND consume_no = :consume_no';
                $params['consume_no'] = $consumeNo;
            }

            $sql .= ' LIMIT 1 FOR UPDATE';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record)) {
                $pdo->rollBack();
                Response::json(['message' => 'consume record not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($record['store_id'] ?? 0));

            $now = gmdate('Y-m-d H:i:s');
            $newConsumeAmount = array_key_exists('consume_amount', $data)
                ? max(0.0, round((float) $data['consume_amount'], 2))
                : round((float) $record['consume_amount'], 2);
            $newDeductBalanceAmount = array_key_exists('deduct_balance_amount', $data)
                ? max(0.0, round((float) $data['deduct_balance_amount'], 2))
                : round((float) $record['deduct_balance_amount'], 2);
            $newDeductCouponAmount = array_key_exists('deduct_coupon_amount', $data)
                ? max(0.0, round((float) $data['deduct_coupon_amount'], 2))
                : round((float) $record['deduct_coupon_amount'], 2);
            $newDeductCardSessions = array_key_exists('deduct_member_card_sessions', $data)
                ? max(0, (int) $data['deduct_member_card_sessions'])
                : (int) $record['deduct_member_card_sessions'];
            $newNote = array_key_exists('note', $data)
                ? Request::str($data, 'note')
                : (string) $record['note'];

            $update = $pdo->prepare(
                'UPDATE qiling_customer_consume_records
                 SET consume_amount = :consume_amount,
                     deduct_balance_amount = :deduct_balance_amount,
                     deduct_coupon_amount = :deduct_coupon_amount,
                     deduct_member_card_sessions = :deduct_member_card_sessions,
                     note = :note
                 WHERE id = :id'
            );
            $update->execute([
                'consume_amount' => $newConsumeAmount,
                'deduct_balance_amount' => $newDeductBalanceAmount,
                'deduct_coupon_amount' => $newDeductCouponAmount,
                'deduct_member_card_sessions' => $newDeductCardSessions,
                'note' => $newNote,
                'id' => (int) $record['id'],
            ]);

            $beforeConsumeAmount = round((float) $record['consume_amount'], 2);
            $diffConsumeAmount = round($newConsumeAmount - $beforeConsumeAmount, 2);
            if (abs($diffConsumeAmount) >= 0.01) {
                $updateCustomer = $pdo->prepare(
                    'UPDATE qiling_customers
                     SET total_spent = GREATEST(total_spent + :diff_consume_amount, 0),
                         updated_at = :updated_at
                     WHERE id = :customer_id'
                );
                $updateCustomer->execute([
                    'diff_consume_amount' => $diffConsumeAmount,
                    'updated_at' => $now,
                    'customer_id' => (int) $record['customer_id'],
                ]);
            }

            Audit::log((int) $user['id'], 'admin.customer.consume_record.adjust', 'customer_consume_record', (int) $record['id'], 'Admin adjust consume record', [
                'consume_no' => (string) $record['consume_no'],
                'before' => [
                    'consume_amount' => $beforeConsumeAmount,
                    'deduct_balance_amount' => round((float) $record['deduct_balance_amount'], 2),
                    'deduct_coupon_amount' => round((float) $record['deduct_coupon_amount'], 2),
                    'deduct_member_card_sessions' => (int) $record['deduct_member_card_sessions'],
                    'note' => (string) $record['note'],
                ],
                'after' => [
                    'consume_amount' => $newConsumeAmount,
                    'deduct_balance_amount' => $newDeductBalanceAmount,
                    'deduct_coupon_amount' => $newDeductCouponAmount,
                    'deduct_member_card_sessions' => $newDeductCardSessions,
                    'note' => $newNote,
                ],
            ]);

            $pdo->commit();
            Response::json([
                'consume_record_id' => (int) $record['id'],
                'consume_no' => (string) $record['consume_no'],
                'customer_id' => (int) $record['customer_id'],
                'consume_amount' => $newConsumeAmount,
                'deduct_balance_amount' => $newDeductBalanceAmount,
                'deduct_coupon_amount' => $newDeductCouponAmount,
                'deduct_member_card_sessions' => $newDeductCardSessions,
                'note' => $newNote,
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
            Response::serverError('adjust consume record failed', $e);
        }
    }

    /**
     * @param array<string, mixed> $customerData
     * @return array<string, mixed>|null
     */
    private static function findCustomerForUpdate(PDO $pdo, array $customerData, string $mobile, array $user): ?array
    {
        $customerId = Request::int($customerData, 'customer_id', 0);
        if ($customerId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM qiling_customers WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                DataScope::assertStoreAccess($user, (int) ($row['store_id'] ?? 0));
            }
            return is_array($row) ? $row : null;
        }

        if ($mobile === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM qiling_customers WHERE mobile = :mobile LIMIT 1 FOR UPDATE');
        $stmt->execute(['mobile' => $mobile]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            DataScope::assertStoreAccess($user, (int) ($row['store_id'] ?? 0));
        }
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function resolveCustomerForUpdate(PDO $pdo, array $data, array $user): ?array
    {
        $customerId = Request::int($data, 'customer_id', 0);
        $customerNo = Request::str($data, 'customer_no');
        $mobile = Request::str($data, 'customer_mobile');

        if ($customerId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM qiling_customers WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                DataScope::assertStoreAccess($user, (int) ($row['store_id'] ?? 0));
            }
            return is_array($row) ? $row : null;
        }

        if ($customerNo !== '') {
            $stmt = $pdo->prepare('SELECT * FROM qiling_customers WHERE customer_no = :customer_no LIMIT 1 FOR UPDATE');
            $stmt->execute(['customer_no' => $customerNo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                DataScope::assertStoreAccess($user, (int) ($row['store_id'] ?? 0));
                return $row;
            }
        }

        if ($mobile !== '') {
            $stmt = $pdo->prepare('SELECT * FROM qiling_customers WHERE mobile = :mobile LIMIT 1 FOR UPDATE');
            $stmt->execute(['mobile' => $mobile]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                DataScope::assertStoreAccess($user, (int) ($row['store_id'] ?? 0));
                return $row;
            }
        }

        return null;
    }

    private static function ensureWallet(PDO $pdo, int $customerId, string $now): void
    {
        $stmt = $pdo->prepare('SELECT id FROM qiling_customer_wallets WHERE customer_id = :customer_id LIMIT 1');
        $stmt->execute(['customer_id' => $customerId]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO qiling_customer_wallets
             (customer_id, balance, total_recharge, total_gift, total_spent, created_at, updated_at)
             VALUES
             (:customer_id, 0.00, 0.00, 0.00, 0.00, :created_at, :updated_at)'
        );
        $insert->execute([
            'customer_id' => $customerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function changeWallet(
        PDO $pdo,
        int $customerId,
        float $deltaAmount,
        string $changeType,
        int $orderId,
        int $operatorUserId,
        string $note,
        string $now
    ): void {
        $stmt = $pdo->prepare(
            'SELECT id, balance, total_recharge, total_gift, total_spent
             FROM qiling_customer_wallets
             WHERE customer_id = :customer_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['customer_id' => $customerId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($wallet)) {
            throw new \RuntimeException('wallet not found');
        }

        $before = round((float) $wallet['balance'], 2);
        $after = round($before + $deltaAmount, 2);
        if ($after < 0) {
            throw new \RuntimeException('wallet balance not enough');
        }

        $totalRecharge = round((float) $wallet['total_recharge'], 2);
        $totalGift = round((float) $wallet['total_gift'], 2);
        $totalSpent = round((float) $wallet['total_spent'], 2);

        if ($changeType === 'recharge' && $deltaAmount > 0) {
            $totalRecharge = round($totalRecharge + $deltaAmount, 2);
        }
        if ($changeType === 'gift' && $deltaAmount > 0) {
            $totalGift = round($totalGift + $deltaAmount, 2);
        }
        if ($changeType === 'deduct' && $deltaAmount < 0) {
            $totalSpent = round($totalSpent + abs($deltaAmount), 2);
        }

        $update = $pdo->prepare(
            'UPDATE qiling_customer_wallets
             SET balance = :balance,
                 total_recharge = :total_recharge,
                 total_gift = :total_gift,
                 total_spent = :total_spent,
                 updated_at = :updated_at
             WHERE customer_id = :customer_id'
        );
        $update->execute([
            'balance' => $after,
            'total_recharge' => $totalRecharge,
            'total_gift' => $totalGift,
            'total_spent' => $totalSpent,
            'updated_at' => $now,
            'customer_id' => $customerId,
        ]);

        $insertLog = $pdo->prepare(
            'INSERT INTO qiling_wallet_logs
             (customer_id, order_id, change_type, delta_amount, before_balance, after_balance, operator_user_id, note, created_at)
             VALUES
             (:customer_id, :order_id, :change_type, :delta_amount, :before_balance, :after_balance, :operator_user_id, :note, :created_at)'
        );
        $insertLog->execute([
            'customer_id' => $customerId,
            'order_id' => $orderId > 0 ? $orderId : null,
            'change_type' => $changeType,
            'delta_amount' => round($deltaAmount, 2),
            'before_balance' => $before,
            'after_balance' => $after,
            'operator_user_id' => $operatorUserId,
            'note' => $note,
            'created_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findWalletForUpdate(PDO $pdo, int $customerId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, balance, total_recharge, total_gift, total_spent
             FROM qiling_customer_wallets
             WHERE customer_id = :customer_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int, mixed> $giftCards
     * @return array<int, int>
     */
    private static function giftMemberCards(PDO $pdo, int $customerId, int $storeId, array $giftCards, int $operatorUserId, string $now): array
    {
        $createdIds = [];
        if (empty($giftCards)) {
            return $createdIds;
        }

        foreach ($giftCards as $giftCard) {
            if (!is_array($giftCard)) {
                continue;
            }

            $packageId = Request::int($giftCard, 'package_id', 0);
            if ($packageId <= 0) {
                continue;
            }

            $packageStmt = $pdo->prepare(
                'SELECT id, store_id, total_sessions, valid_days
                 FROM qiling_service_packages
                 WHERE id = :id
                 LIMIT 1'
            );
            $packageStmt->execute(['id' => $packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($package)) {
                throw new \RuntimeException('gift package not found: ' . $packageId);
            }

            $totalSessions = max(1, Request::int($giftCard, 'total_sessions', (int) $package['total_sessions']));
            $validDays = max(1, Request::int($giftCard, 'valid_days', (int) $package['valid_days']));
            $cardNo = 'QLMC' . gmdate('ymd') . random_int(1000, 9999);
            $expireAt = gmdate('Y-m-d H:i:s', strtotime($now . ' +' . $validDays . ' days'));

            $insertCard = $pdo->prepare(
                'INSERT INTO qiling_member_cards
                 (card_no, customer_id, store_id, package_id, total_sessions, remaining_sessions, sold_price, sold_at, expire_at, status, created_at, updated_at)
                 VALUES
                 (:card_no, :customer_id, :store_id, :package_id, :total_sessions, :remaining_sessions, :sold_price, :sold_at, :expire_at, :status, :created_at, :updated_at)'
            );
            $insertCard->execute([
                'card_no' => $cardNo,
                'customer_id' => $customerId,
                'store_id' => $storeId > 0 ? $storeId : (int) ($package['store_id'] ?? 0),
                'package_id' => $packageId,
                'total_sessions' => $totalSessions,
                'remaining_sessions' => $totalSessions,
                'sold_price' => 0.00,
                'sold_at' => $now,
                'expire_at' => $expireAt,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $memberCardId = (int) $pdo->lastInsertId();
            $createdIds[] = $memberCardId;

            AssetService::insertMemberCardLog(
                $pdo,
                $memberCardId,
                $customerId,
                'gift_open',
                $totalSessions,
                0,
                $totalSessions,
                $operatorUserId,
                Request::str($giftCard, 'note', '建档赠送会员卡'),
                $now
            );
        }

        return $createdIds;
    }

    /**
     * @param array<int, mixed> $giftCoupons
     * @return array<int, int>
     */
    private static function giftCoupons(PDO $pdo, int $customerId, int $storeId, array $giftCoupons, int $operatorUserId, string $now): array
    {
        $createdIds = [];
        if (empty($giftCoupons)) {
            return $createdIds;
        }

        foreach ($giftCoupons as $giftCoupon) {
            if (!is_array($giftCoupon)) {
                continue;
            }

            $couponName = Request::str($giftCoupon, 'coupon_name');
            if ($couponName === '') {
                continue;
            }

            $faceValue = max(0.0, (float) ($giftCoupon['face_value'] ?? 0));
            $minSpend = max(0.0, (float) ($giftCoupon['min_spend'] ?? 0));
            $remainCount = max(1, Request::int($giftCoupon, 'count', 1));
            $couponType = Request::str($giftCoupon, 'coupon_type', 'cash');
            if (!in_array($couponType, ['cash', 'discount'], true)) {
                $couponType = 'cash';
            }

            $expireAt = self::nullableDateTime(Request::str($giftCoupon, 'expire_at'));
            $coupon = AssetService::issueCoupon(
                $pdo,
                $customerId,
                $storeId,
                [
                    'coupon_name' => $couponName,
                    'coupon_type' => $couponType,
                    'face_value' => round($faceValue, 2),
                    'min_spend' => round($minSpend, 2),
                    'remain_count' => $remainCount,
                    'expire_at' => $expireAt,
                ],
                $operatorUserId,
                'gift',
                'gift',
                Request::str($giftCoupon, 'note', '建档赠送优惠券'),
                $now
            );
            $createdIds[] = (int) ($coupon['coupon_id'] ?? 0);
        }

        return $createdIds;
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>|null
     */
    private static function findMemberCardForUpdate(PDO $pdo, int $customerId, array $usage): ?array
    {
        $memberCardId = Request::int($usage, 'member_card_id', 0);
        $cardNo = Request::str($usage, 'card_no');

        if ($memberCardId <= 0 && $cardNo === '') {
            return null;
        }

        $sql = 'SELECT *
                FROM qiling_member_cards
                WHERE customer_id = :customer_id';
        $params = [
            'customer_id' => $customerId,
        ];

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

    /**
     * @return array<string, mixed>|null
     */
    private static function findCouponForUpdate(PDO $pdo, int $customerId, string $couponCode): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_coupons
             WHERE customer_id = :customer_id
               AND coupon_code = :coupon_code
               AND (status = :status_active OR status = :status_used)
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'coupon_code' => $couponCode,
            'status_active' => 'active',
            'status_used' => 'used',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function findCouponByReferenceForUpdate(PDO $pdo, int $customerId, array $data): ?array
    {
        $couponId = Request::int($data, 'coupon_id', 0);
        $couponCode = Request::str($data, 'coupon_code');

        if ($couponId <= 0 && $couponCode === '') {
            throw new \RuntimeException('coupon_id or coupon_code is required');
        }

        $sql = 'SELECT *
                FROM qiling_coupons
                WHERE customer_id = :customer_id';
        $params = [
            'customer_id' => $customerId,
        ];

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

    private static function normalizeCouponStatus(string $status, int $remainCount): string
    {
        if (!in_array($status, ['active', 'used', 'expired', 'cancelled'], true)) {
            return $remainCount > 0 ? 'active' : 'used';
        }

        return $status;
    }

    private static function generateConsumeNo(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $consumeNo = 'QLCR' . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM qiling_customer_consume_records WHERE consume_no = :consume_no LIMIT 1');
            $stmt->execute(['consume_no' => $consumeNo]);
            if (!$stmt->fetchColumn()) {
                return $consumeNo;
            }
        }

        throw new \RuntimeException('failed to generate unique consume_no');
    }

    private static function normalizeGender(string $gender): string
    {
        if (!in_array($gender, ['male', 'female', 'unknown'], true)) {
            return 'unknown';
        }

        return $gender;
    }

    private static function nullableDate(string $date): ?string
    {
        if ($date === '') {
            return null;
        }

        return $date;
    }

    private static function nullableDateTime(string $dateTime): ?string
    {
        if ($dateTime === '') {
            return null;
        }

        return $dateTime;
    }
}

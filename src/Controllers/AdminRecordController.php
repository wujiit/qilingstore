<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\AssetService;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class AdminRecordController
{
    public static function searchCustomers(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);

        $keyword = isset($_GET['keyword']) && is_string($_GET['keyword']) ? trim($_GET['keyword']) : '';
        if ($keyword === '') {
            Response::json(['message' => 'keyword is required'], 422);
            return;
        }

        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $limit = max(1, min($limit, 200));

        $pdo = Database::pdo();
        $scopeStoreId = DataScope::resolveFilterStoreId($user, null);

        $sql = 'SELECT DISTINCT c.id, c.customer_no, c.name, c.mobile, c.store_id, s.store_name, c.total_spent, c.visit_count, c.last_visit_at
                FROM qiling_customers c
                LEFT JOIN qiling_stores s ON s.id = c.store_id
                LEFT JOIN qiling_member_cards mc ON mc.customer_id = c.id
                WHERE (c.customer_no LIKE :kw
                   OR c.mobile LIKE :kw
                   OR c.name LIKE :kw
                   OR mc.card_no LIKE :kw)';
        $params = [
            'kw' => '%' . $keyword . '%',
        ];
        if ($scopeStoreId !== null) {
            $sql .= ' AND c.store_id = :scope_store_id';
            $params['scope_store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($customers as &$customer) {
            $cardsStmt = $pdo->prepare(
                'SELECT id, card_no, package_id, total_sessions, remaining_sessions, sold_price, sold_at, expire_at, status
                 FROM qiling_member_cards
                 WHERE customer_id = :customer_id
                 ORDER BY id DESC'
            );
            $cardsStmt->execute(['customer_id' => (int) $customer['id']]);
            $customer['member_cards'] = $cardsStmt->fetchAll(PDO::FETCH_ASSOC);

            $walletStmt = $pdo->prepare(
                'SELECT balance, total_recharge, total_gift, total_spent
                 FROM qiling_customer_wallets
                 WHERE customer_id = :customer_id
                 LIMIT 1'
            );
            $walletStmt->execute(['customer_id' => (int) $customer['id']]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
            $customer['wallet'] = is_array($wallet)
                ? $wallet
                : [
                    'balance' => '0.00',
                    'total_recharge' => '0.00',
                    'total_gift' => '0.00',
                    'total_spent' => '0.00',
                ];

            $couponStmt = $pdo->prepare(
                'SELECT id, coupon_code, coupon_name, coupon_type, face_value, min_spend, remain_count, expire_at, status
                 FROM qiling_coupons
                 WHERE customer_id = :customer_id
                 ORDER BY id DESC
                 LIMIT 100'
            );
            $couponStmt->execute(['customer_id' => (int) $customer['id']]);
            $customer['coupons'] = $couponStmt->fetchAll(PDO::FETCH_ASSOC);

            $consumeStmt = $pdo->prepare(
                'SELECT id, consume_no, consume_amount, deduct_balance_amount, deduct_coupon_amount, deduct_member_card_sessions, note, created_at
                 FROM qiling_customer_consume_records
                 WHERE customer_id = :customer_id
                 ORDER BY id DESC
                 LIMIT 30'
            );
            $consumeStmt->execute(['customer_id' => (int) $customer['id']]);
            $customer['consume_records'] = $consumeStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        Response::json([
            'keyword' => $keyword,
            'total' => count($customers),
            'data' => $customers,
        ]);
    }

    public static function adjustMemberCard(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $mode = Request::str($data, 'mode', 'set_remaining');
        if (!in_array($mode, ['set_remaining', 'delta_sessions'], true)) {
            Response::json(['message' => 'mode must be set_remaining or delta_sessions'], 422);
            return;
        }

        $value = Request::int($data, 'value', 0);
        $note = Request::str($data, 'note', '后台手工调整');
        $forceStatus = Request::str($data, 'status');
        $totalSessionsInput = Request::int($data, 'total_sessions', 0);
        $expireAtInput = Request::str($data, 'expire_at');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $card = self::findMemberCardForUpdate($pdo, $data);
            if (!is_array($card)) {
                $pdo->rollBack();
                Response::json(['message' => 'member card not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($card['store_id'] ?? 0));

            $cardId = (int) $card['id'];
            $customerId = (int) $card['customer_id'];
            $beforeRemaining = (int) $card['remaining_sessions'];
            $beforeTotal = (int) $card['total_sessions'];

            $newTotal = $beforeTotal;
            if ($totalSessionsInput > 0) {
                $newTotal = $totalSessionsInput;
            }

            if ($mode === 'set_remaining') {
                $newRemaining = max(0, min($value, $newTotal));
            } else {
                $newRemaining = $beforeRemaining + $value;
                $newRemaining = max(0, min($newRemaining, $newTotal));
            }

            $delta = $newRemaining - $beforeRemaining;

            $newStatus = $newRemaining > 0 ? 'active' : 'depleted';
            if ($forceStatus !== '' && in_array($forceStatus, ['active', 'depleted', 'expired', 'cancelled'], true)) {
                $newStatus = $forceStatus;
            }

            $expireAt = $expireAtInput !== '' ? $expireAtInput : ($card['expire_at'] ?? null);
            $now = gmdate('Y-m-d H:i:s');

            $updateStmt = $pdo->prepare(
                'UPDATE qiling_member_cards
                 SET total_sessions = :total_sessions,
                     remaining_sessions = :remaining_sessions,
                     expire_at = :expire_at,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'total_sessions' => $newTotal,
                'remaining_sessions' => $newRemaining,
                'expire_at' => $expireAt,
                'status' => $newStatus,
                'updated_at' => $now,
                'id' => $cardId,
            ]);

            AssetService::insertMemberCardLog(
                $pdo,
                $cardId,
                $customerId,
                'manual_adjust',
                $delta,
                $beforeRemaining,
                $newRemaining,
                (int) $user['id'],
                $note,
                $now
            );

            Audit::log((int) $user['id'], 'admin.member_card.adjust', 'member_card', $cardId, 'Admin adjust member card', [
                'mode' => $mode,
                'value' => $value,
                'before_remaining' => $beforeRemaining,
                'after_remaining' => $newRemaining,
                'before_total' => $beforeTotal,
                'after_total' => $newTotal,
                'status' => $newStatus,
            ]);

            $pdo->commit();
            Response::json([
                'member_card_id' => $cardId,
                'card_no' => $card['card_no'],
                'total_sessions' => $newTotal,
                'remaining_sessions' => $newRemaining,
                'delta_sessions' => $delta,
                'status' => $newStatus,
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
            Response::serverError('adjust member card failed', $e);
        }
    }

    public static function manualConsume(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $consumeSessions = Request::int($data, 'consume_sessions', 1);
        if ($consumeSessions <= 0) {
            Response::json(['message' => 'consume_sessions must be positive'], 422);
            return;
        }

        $appointmentId = Request::int($data, 'appointment_id', 0);
        $note = Request::str($data, 'note', '后台手工补录消费');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $card = self::findMemberCardForUpdate($pdo, $data);
            if (!is_array($card)) {
                $pdo->rollBack();
                Response::json(['message' => 'member card not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($card['store_id'] ?? 0));

            $cardId = (int) $card['id'];
            $customerId = (int) $card['customer_id'];
            $beforeRemaining = (int) $card['remaining_sessions'];
            if ($beforeRemaining < $consumeSessions) {
                throw new \RuntimeException('remaining sessions not enough');
            }

            $afterRemaining = $beforeRemaining - $consumeSessions;
            $status = $afterRemaining > 0 ? 'active' : 'depleted';
            $now = gmdate('Y-m-d H:i:s');

            $updateCard = $pdo->prepare(
                'UPDATE qiling_member_cards
                 SET remaining_sessions = :remaining_sessions,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCard->execute([
                'remaining_sessions' => $afterRemaining,
                'status' => $status,
                'updated_at' => $now,
                'id' => $cardId,
            ]);

            AssetService::insertMemberCardLog(
                $pdo,
                $cardId,
                $customerId,
                'manual_consume_admin',
                -$consumeSessions,
                $beforeRemaining,
                $afterRemaining,
                (int) $user['id'],
                $note,
                $now
            );

            $consumeId = null;
            if ($appointmentId > 0) {
                $appointmentStmt = $pdo->prepare(
                    'SELECT id, customer_id, store_id
                     FROM qiling_appointments
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE'
                );
                $appointmentStmt->execute(['id' => $appointmentId]);
                $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($appointment)) {
                    throw new \RuntimeException('appointment not found');
                }
                DataScope::assertStoreAccess($user, (int) ($card['store_id'] ?? 0));
                DataScope::assertStoreAccess($user, (int) ($appointment['store_id'] ?? 0));
                if ((int) $appointment['customer_id'] !== $customerId) {
                    throw new \RuntimeException('appointment customer mismatch with member card');
                }

                $existsConsume = $pdo->prepare(
                    'SELECT id
                     FROM qiling_appointment_consumes
                     WHERE appointment_id = :appointment_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $existsConsume->execute(['appointment_id' => $appointmentId]);
                if ($existsConsume->fetchColumn()) {
                    throw new \RuntimeException('appointment consume record already exists');
                }

                $insertConsume = $pdo->prepare(
                    'INSERT INTO qiling_appointment_consumes
                     (appointment_id, member_card_id, customer_id, consume_sessions, before_sessions, after_sessions, operator_user_id, note, created_at)
                     VALUES
                     (:appointment_id, :member_card_id, :customer_id, :consume_sessions, :before_sessions, :after_sessions, :operator_user_id, :note, :created_at)'
                );
                $insertConsume->execute([
                    'appointment_id' => $appointmentId,
                    'member_card_id' => $cardId,
                    'customer_id' => $customerId,
                    'consume_sessions' => $consumeSessions,
                    'before_sessions' => $beforeRemaining,
                    'after_sessions' => $afterRemaining,
                    'operator_user_id' => (int) $user['id'],
                    'note' => $note,
                    'created_at' => $now,
                ]);
                $consumeId = (int) $pdo->lastInsertId();
            }

            Audit::log((int) $user['id'], 'admin.member_card.consume_manual', 'member_card', $cardId, 'Admin manual consume', [
                'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                'consume_sessions' => $consumeSessions,
                'before_remaining' => $beforeRemaining,
                'after_remaining' => $afterRemaining,
            ]);

            $pdo->commit();
            Response::json([
                'member_card_id' => $cardId,
                'card_no' => $card['card_no'],
                'consume_sessions' => $consumeSessions,
                'remaining_sessions' => $afterRemaining,
                'status' => $status,
                'appointment_consume_id' => $consumeId,
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
            Response::serverError('manual consume failed', $e);
        }
    }

    public static function adjustAppointmentConsume(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $appointmentId = Request::int($data, 'appointment_id', 0);
        $newConsumeSessions = Request::int($data, 'consume_sessions', 0);
        if ($appointmentId <= 0 || $newConsumeSessions <= 0) {
            Response::json(['message' => 'appointment_id and positive consume_sessions are required'], 422);
            return;
        }

        $note = Request::str($data, 'note', '后台手工修正消费记录');

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $consumeStmt = $pdo->prepare(
                'SELECT id, appointment_id, member_card_id, customer_id, consume_sessions, rolled_back_at
                 FROM qiling_appointment_consumes
                 WHERE appointment_id = :appointment_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $consumeStmt->execute(['appointment_id' => $appointmentId]);
            $consume = $consumeStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($consume)) {
                $pdo->rollBack();
                Response::json(['message' => 'appointment consume record not found'], 404);
                return;
            }

            if (!empty($consume['rolled_back_at'])) {
                throw new \RuntimeException('consume record already rolled back and cannot be adjusted');
            }

            $memberCardId = (int) $consume['member_card_id'];
            $cardStmt = $pdo->prepare(
                'SELECT id, card_no, total_sessions, remaining_sessions, status
                 FROM qiling_member_cards
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $cardStmt->execute(['id' => $memberCardId]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($card)) {
                throw new \RuntimeException('member card not found');
            }
            DataScope::assertStoreAccess($user, (int) ($card['store_id'] ?? 0));

            $oldConsumeSessions = (int) $consume['consume_sessions'];
            $cardBefore = (int) $card['remaining_sessions'];
            $diff = $newConsumeSessions - $oldConsumeSessions;

            $cardAfter = $cardBefore - $diff;
            $maxTotal = (int) $card['total_sessions'];
            if ($maxTotal > 0 && $cardAfter > $maxTotal) {
                $cardAfter = $maxTotal;
            }
            if ($cardAfter < 0) {
                throw new \RuntimeException('adjustment would make remaining sessions negative');
            }

            $status = $cardAfter > 0 ? 'active' : 'depleted';
            $now = gmdate('Y-m-d H:i:s');

            $updateCardStmt = $pdo->prepare(
                'UPDATE qiling_member_cards
                 SET remaining_sessions = :remaining_sessions,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateCardStmt->execute([
                'remaining_sessions' => $cardAfter,
                'status' => $status,
                'updated_at' => $now,
                'id' => $memberCardId,
            ]);

            $updateConsumeStmt = $pdo->prepare(
                'UPDATE qiling_appointment_consumes
                 SET consume_sessions = :consume_sessions,
                     before_sessions = :before_sessions,
                     after_sessions = :after_sessions,
                     operator_user_id = :operator_user_id,
                     note = :note
                 WHERE id = :id'
            );
            $updateConsumeStmt->execute([
                'consume_sessions' => $newConsumeSessions,
                'before_sessions' => $cardAfter + $newConsumeSessions,
                'after_sessions' => $cardAfter,
                'operator_user_id' => (int) $user['id'],
                'note' => $note,
                'id' => (int) $consume['id'],
            ]);

            AssetService::insertMemberCardLog(
                $pdo,
                $memberCardId,
                (int) $consume['customer_id'],
                'manual_consume_adjust',
                -$diff,
                $cardBefore,
                $cardAfter,
                (int) $user['id'],
                $note,
                $now
            );

            Audit::log((int) $user['id'], 'admin.appointment_consume.adjust', 'appointment', $appointmentId, 'Admin adjust appointment consume', [
                'consume_id' => (int) $consume['id'],
                'old_consume_sessions' => $oldConsumeSessions,
                'new_consume_sessions' => $newConsumeSessions,
                'card_before' => $cardBefore,
                'card_after' => $cardAfter,
            ]);

            $pdo->commit();
            Response::json([
                'appointment_id' => $appointmentId,
                'consume_id' => (int) $consume['id'],
                'old_consume_sessions' => $oldConsumeSessions,
                'new_consume_sessions' => $newConsumeSessions,
                'remaining_sessions' => $cardAfter,
                'member_card_status' => $status,
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
            Response::serverError('adjust appointment consume failed', $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function findMemberCardForUpdate(PDO $pdo, array $data): ?array
    {
        $memberCardId = Request::int($data, 'member_card_id', 0);
        $cardNo = Request::str($data, 'card_no');
        $customerMobile = Request::str($data, 'customer_mobile');

        if ($memberCardId <= 0 && $cardNo === '') {
            throw new \RuntimeException('member_card_id or card_no is required');
        }

        $sql = 'SELECT mc.*, c.mobile AS customer_mobile
                FROM qiling_member_cards mc
                INNER JOIN qiling_customers c ON c.id = mc.customer_id
                WHERE 1 = 1';
        $params = [];

        if ($memberCardId > 0) {
            $sql .= ' AND mc.id = :id';
            $params['id'] = $memberCardId;
        }

        if ($cardNo !== '') {
            $sql .= ' AND mc.card_no = :card_no';
            $params['card_no'] = $cardNo;
        }

        if ($customerMobile !== '') {
            $sql .= ' AND c.mobile = :customer_mobile';
            $params['customer_mobile'] = $customerMobile;
        }

        $sql .= ' LIMIT 1 FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

}

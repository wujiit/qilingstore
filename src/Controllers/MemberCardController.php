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

final class MemberCardController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);

        $sql = 'SELECT mc.*, c.name AS customer_name, c.mobile AS customer_mobile, p.package_name, p.package_code
                FROM qiling_member_cards mc
                INNER JOIN qiling_customers c ON c.id = mc.customer_id
                INNER JOIN qiling_service_packages p ON p.id = mc.package_id';
        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE mc.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY mc.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => $rows]);
    }

    public static function create(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $customerId = Request::int($data, 'customer_id', 0);
        $packageId = Request::int($data, 'package_id', 0);

        if ($customerId <= 0 || $packageId <= 0) {
            Response::json(['message' => 'customer_id and package_id are required'], 422);
            return;
        }

        $pdo = Database::pdo();

        $customerStmt = $pdo->prepare('SELECT id, store_id FROM qiling_customers WHERE id = :id LIMIT 1');
        $customerStmt->execute(['id' => $customerId]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($customer)) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }
        DataScope::assertStoreAccess($user, (int) ($customer['store_id'] ?? 0));

        $packageStmt = $pdo->prepare('SELECT id, store_id, total_sessions, sale_price, valid_days FROM qiling_service_packages WHERE id = :id LIMIT 1');
        $packageStmt->execute(['id' => $packageId]);
        $package = $packageStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($package)) {
            Response::json(['message' => 'package not found'], 404);
            return;
        }

        $customerStoreId = (int) ($customer['store_id'] ?? 0);
        $packageStoreId = (int) ($package['store_id'] ?? 0);
        if ($packageStoreId > 0 && $customerStoreId > 0 && $packageStoreId !== $customerStoreId) {
            Response::json(['message' => 'package store mismatch with customer store'], 422);
            return;
        }

        $totalSessions = Request::int($data, 'total_sessions', (int) $package['total_sessions']);
        if ($totalSessions <= 0) {
            Response::json(['message' => 'total_sessions must be positive'], 422);
            return;
        }

        $soldPrice = (float) ($data['sold_price'] ?? $package['sale_price']);
        if ($soldPrice < 0) {
            $soldPrice = 0;
        }

        $validDays = Request::int($data, 'valid_days', (int) $package['valid_days']);
        if ($validDays <= 0) {
            $validDays = 365;
        }

        $now = gmdate('Y-m-d H:i:s');
        $expireAt = gmdate('Y-m-d H:i:s', strtotime('+' . $validDays . ' days'));
        $cardNo = 'QLMC' . gmdate('ymd') . random_int(1000, 9999);

        $insert = $pdo->prepare(
            'INSERT INTO qiling_member_cards
             (card_no, customer_id, store_id, package_id, total_sessions, remaining_sessions, sold_price, sold_at, expire_at, status, created_at, updated_at)
             VALUES
             (:card_no, :customer_id, :store_id, :package_id, :total_sessions, :remaining_sessions, :sold_price, :sold_at, :expire_at, :status, :created_at, :updated_at)'
        );
        $insert->execute([
            'card_no' => $cardNo,
            'customer_id' => $customerId,
            'store_id' => (int) ($customer['store_id'] ?? $package['store_id'] ?? 0),
            'package_id' => $packageId,
            'total_sessions' => $totalSessions,
            'remaining_sessions' => $totalSessions,
            'sold_price' => $soldPrice,
            'sold_at' => $now,
            'expire_at' => $expireAt,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $memberCardId = (int) $pdo->lastInsertId();

        self::insertLog($memberCardId, $customerId, 'open', $totalSessions, 0, $totalSessions, (int) $user['id'], '开卡', $now);

        Audit::log((int) $user['id'], 'member_card.create', 'member_card', $memberCardId, 'Create member card', ['card_no' => $cardNo]);

        Response::json(['member_card_id' => $memberCardId, 'card_no' => $cardNo], 201);
    }

    public static function consume(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $memberCardId = Request::int($data, 'member_card_id', 0);
        $consumeSessions = Request::int($data, 'consume_sessions', 1);

        if ($memberCardId <= 0 || $consumeSessions <= 0) {
            Response::json(['message' => 'member_card_id and positive consume_sessions are required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT id, card_no, customer_id, store_id, remaining_sessions, status FROM qiling_member_cards WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $memberCardId]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($card)) {
                $pdo->rollBack();
                Response::json(['message' => 'member card not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($card['store_id'] ?? 0));

            if (($card['status'] ?? '') !== 'active') {
                $pdo->rollBack();
                Response::json(['message' => 'member card is not active'], 409);
                return;
            }

            $beforeSessions = (int) $card['remaining_sessions'];
            if ($beforeSessions < $consumeSessions) {
                $pdo->rollBack();
                Response::json(['message' => 'remaining sessions not enough'], 409);
                return;
            }

            $afterSessions = $beforeSessions - $consumeSessions;
            $status = $afterSessions > 0 ? 'active' : 'depleted';
            $now = gmdate('Y-m-d H:i:s');

            $update = $pdo->prepare('UPDATE qiling_member_cards SET remaining_sessions = :remaining_sessions, status = :status, updated_at = :updated_at WHERE id = :id');
            $update->execute([
                'remaining_sessions' => $afterSessions,
                'status' => $status,
                'updated_at' => $now,
                'id' => $memberCardId,
            ]);

            $note = Request::str($data, 'note', '次卡核销');
            self::insertLog($memberCardId, (int) $card['customer_id'], 'consume', -$consumeSessions, $beforeSessions, $afterSessions, (int) $user['id'], $note, $now);

            Audit::log((int) $user['id'], 'member_card.consume', 'member_card', $memberCardId, 'Consume member card', [
                'card_no' => $card['card_no'],
                'consume_sessions' => $consumeSessions,
                'remaining_sessions' => $afterSessions,
            ]);

            $pdo->commit();

            Response::json([
                'member_card_id' => $memberCardId,
                'remaining_sessions' => $afterSessions,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('consume failed', $e);
        }
    }

    public static function logs(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);

        $sql = 'SELECT l.*, mc.card_no
                FROM qiling_member_card_logs l
                INNER JOIN qiling_member_cards mc ON mc.id = l.member_card_id';
        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE mc.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT 500';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => $rows]);
    }

    private static function insertLog(
        int $memberCardId,
        int $customerId,
        string $actionType,
        int $deltaSessions,
        int $beforeSessions,
        int $afterSessions,
        int $operatorUserId,
        string $note,
        string $now
    ): void {
        AssetService::insertMemberCardLog(
            Database::pdo(),
            $memberCardId,
            $customerId,
            $actionType,
            $deltaSessions,
            $beforeSessions,
            $afterSessions,
            $operatorUserId,
            $note,
            $now
        );
    }
}

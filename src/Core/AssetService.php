<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class AssetService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function issueCoupon(
        PDO $pdo,
        int $customerId,
        int $storeId,
        array $payload,
        int $operatorUserId,
        string $sourceType,
        string $actionType,
        string $note,
        string $now
    ): array {
        $couponName = trim((string) ($payload['coupon_name'] ?? ''));
        if ($couponName === '') {
            throw new \RuntimeException('coupon_name is required');
        }

        $couponType = trim((string) ($payload['coupon_type'] ?? 'cash'));
        if (!in_array($couponType, ['cash', 'discount'], true)) {
            $couponType = 'cash';
        }

        $faceValue = round(max(0.0, (float) ($payload['face_value'] ?? 0)), 2);
        $minSpend = round(max(0.0, (float) ($payload['min_spend'] ?? 0)), 2);
        $remainCount = max(1, (int) ($payload['remain_count'] ?? $payload['count'] ?? 1));

        $expireAt = null;
        $expireDays = isset($payload['expire_days']) && is_numeric($payload['expire_days'])
            ? (int) $payload['expire_days']
            : 0;
        if ($expireDays > 0) {
            $expireAt = gmdate('Y-m-d H:i:s', strtotime($now . ' +' . $expireDays . ' days'));
        } elseif (isset($payload['expire_at']) && is_string($payload['expire_at'])) {
            $expireAtRaw = trim((string) $payload['expire_at']);
            $expireAt = $expireAtRaw !== '' ? $expireAtRaw : null;
        }

        $couponCode = self::generateCouponCode($pdo);
        $status = $remainCount > 0 ? 'active' : 'used';

        $insert = $pdo->prepare(
            'INSERT INTO qiling_coupons
             (coupon_code, customer_id, store_id, coupon_name, coupon_type, face_value, min_spend, remain_count, expire_at, status, source_type, created_at, updated_at)
             VALUES
             (:coupon_code, :customer_id, :store_id, :coupon_name, :coupon_type, :face_value, :min_spend, :remain_count, :expire_at, :status, :source_type, :created_at, :updated_at)'
        );
        $insert->execute([
            'coupon_code' => $couponCode,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'coupon_name' => $couponName,
            'coupon_type' => $couponType,
            'face_value' => $faceValue,
            'min_spend' => $minSpend,
            'remain_count' => $remainCount,
            'expire_at' => $expireAt,
            'status' => $status,
            'source_type' => $sourceType,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $couponId = (int) $pdo->lastInsertId();
        self::insertCouponLog(
            $pdo,
            $couponId,
            $customerId,
            0,
            $actionType,
            $remainCount,
            0,
            $remainCount,
            $operatorUserId,
            $note,
            $now
        );

        return [
            'coupon_id' => $couponId,
            'coupon_code' => $couponCode,
            'coupon_name' => $couponName,
            'coupon_type' => $couponType,
            'face_value' => $faceValue,
            'min_spend' => $minSpend,
            'remain_count' => $remainCount,
            'expire_at' => $expireAt,
            'status' => $status,
            'source_type' => $sourceType,
        ];
    }

    public static function insertCouponLog(
        PDO $pdo,
        int $couponId,
        int $customerId,
        int $orderId,
        string $actionType,
        int $deltaCount,
        int $beforeCount,
        int $afterCount,
        int $operatorUserId,
        string $note,
        string $createdAt
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_coupon_logs
             (coupon_id, customer_id, order_id, action_type, delta_count, before_count, after_count, operator_user_id, note, created_at)
             VALUES
             (:coupon_id, :customer_id, :order_id, :action_type, :delta_count, :before_count, :after_count, :operator_user_id, :note, :created_at)'
        );
        $stmt->execute([
            'coupon_id' => $couponId,
            'customer_id' => $customerId,
            'order_id' => $orderId > 0 ? $orderId : null,
            'action_type' => $actionType,
            'delta_count' => $deltaCount,
            'before_count' => $beforeCount,
            'after_count' => $afterCount,
            'operator_user_id' => $operatorUserId,
            'note' => $note,
            'created_at' => $createdAt,
        ]);
    }

    public static function insertMemberCardLog(
        PDO $pdo,
        int $memberCardId,
        int $customerId,
        string $actionType,
        int $deltaSessions,
        int $beforeSessions,
        int $afterSessions,
        int $operatorUserId,
        string $note,
        string $createdAt
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_member_card_logs
             (member_card_id, customer_id, action_type, delta_sessions, before_sessions, after_sessions, operator_user_id, note, created_at)
             VALUES
             (:member_card_id, :customer_id, :action_type, :delta_sessions, :before_sessions, :after_sessions, :operator_user_id, :note, :created_at)'
        );
        $stmt->execute([
            'member_card_id' => $memberCardId,
            'customer_id' => $customerId,
            'action_type' => $actionType,
            'delta_sessions' => $deltaSessions,
            'before_sessions' => $beforeSessions,
            'after_sessions' => $afterSessions,
            'operator_user_id' => $operatorUserId,
            'note' => $note,
            'created_at' => $createdAt,
        ]);
    }

    public static function generateCouponCode(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $couponCode = 'QLCP' . gmdate('ymd') . random_int(100000, 999999);
            $stmt = $pdo->prepare('SELECT id FROM qiling_coupons WHERE coupon_code = :coupon_code LIMIT 1');
            $stmt->execute(['coupon_code' => $couponCode]);
            if (!$stmt->fetchColumn()) {
                return $couponCode;
            }
        }

        throw new \RuntimeException('failed to generate unique coupon_code');
    }
}

<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class PointsService
{
    /**
     * @return array<string, mixed>
     */
    public static function change(
        PDO $pdo,
        int $operatorUserId,
        int $customerId,
        int $storeId,
        int $deltaPoints,
        string $changeType,
        string $note = '',
        string $relatedType = '',
        ?int $relatedId = null
    ): array {
        if ($customerId <= 0) {
            throw new \RuntimeException('customer_id is required');
        }
        if ($deltaPoints === 0) {
            throw new \RuntimeException('delta_points cannot be zero');
        }

        $customer = self::lockCustomer($pdo, $customerId);
        if (!is_array($customer)) {
            throw new \RuntimeException('customer not found');
        }
        $customerStoreId = (int) ($customer['store_id'] ?? 0);
        $storeId = $storeId > 0 ? $storeId : $customerStoreId;

        $now = gmdate('Y-m-d H:i:s');
        $account = self::lockOrCreateAccount($pdo, $customerId, $now);
        $before = (int) ($account['current_points'] ?? 0);
        $after = $before + $deltaPoints;
        if ($after < 0) {
            throw new \RuntimeException('points not enough');
        }

        $lifetime = (int) ($account['lifetime_points'] ?? 0);
        if ($deltaPoints > 0) {
            $lifetime += $deltaPoints;
        }

        $grade = self::resolveMatchedGrade($pdo, $storeId, $after);
        $gradeId = is_array($grade) ? (int) ($grade['id'] ?? 0) : null;

        $update = $pdo->prepare(
            'UPDATE qiling_customer_point_accounts
             SET current_points = :current_points,
                 lifetime_points = :lifetime_points,
                 grade_id = :grade_id,
                 updated_at = :updated_at
             WHERE customer_id = :customer_id'
        );
        $update->execute([
            'current_points' => $after,
            'lifetime_points' => $lifetime,
            'grade_id' => $gradeId > 0 ? $gradeId : null,
            'updated_at' => $now,
            'customer_id' => $customerId,
        ]);

        $insertLog = $pdo->prepare(
            'INSERT INTO qiling_customer_point_logs
             (customer_id, store_id, change_type, delta_points, before_points, after_points, operator_user_id, related_type, related_id, note, created_at)
             VALUES
             (:customer_id, :store_id, :change_type, :delta_points, :before_points, :after_points, :operator_user_id, :related_type, :related_id, :note, :created_at)'
        );
        $insertLog->execute([
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'change_type' => $changeType,
            'delta_points' => $deltaPoints,
            'before_points' => $before,
            'after_points' => $after,
            'operator_user_id' => $operatorUserId,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'note' => $note,
            'created_at' => $now,
        ]);

        return [
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'delta_points' => $deltaPoints,
            'current_points' => $after,
            'lifetime_points' => $lifetime,
            'grade_id' => $gradeId > 0 ? $gradeId : null,
            'grade_name' => is_array($grade) ? (string) ($grade['grade_name'] ?? '') : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function account(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.customer_id, a.current_points, a.lifetime_points, a.grade_id,
                    g.grade_name, g.discount_rate
             FROM qiling_customer_point_accounts a
             LEFT JOIN qiling_customer_grades g ON g.id = a.grade_id
             WHERE a.customer_id = :customer_id
             LIMIT 1'
        );
        $stmt->execute(['customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'customer_id' => $customerId,
                'current_points' => 0,
                'lifetime_points' => 0,
                'grade_id' => null,
                'grade_name' => '',
                'discount_rate' => 100.00,
            ];
        }

        return [
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'current_points' => (int) ($row['current_points'] ?? 0),
            'lifetime_points' => (int) ($row['lifetime_points'] ?? 0),
            'grade_id' => isset($row['grade_id']) ? (int) $row['grade_id'] : null,
            'grade_name' => (string) ($row['grade_name'] ?? ''),
            'discount_rate' => round((float) ($row['discount_rate'] ?? 100), 2),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockCustomer(PDO $pdo, int $customerId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, store_id FROM qiling_customers WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function lockOrCreateAccount(PDO $pdo, int $customerId, string $now): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, customer_id, current_points, lifetime_points, grade_id
             FROM qiling_customer_point_accounts
             WHERE customer_id = :customer_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        $insert = $pdo->prepare(
            'INSERT INTO qiling_customer_point_accounts (customer_id, current_points, lifetime_points, grade_id, created_at, updated_at)
             VALUES (:customer_id, 0, 0, NULL, :created_at, :updated_at)'
        );
        $insert->execute([
            'customer_id' => $customerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stmt->execute(['customer_id' => $customerId]);
        $created = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($created)) {
            throw new \RuntimeException('point account create failed');
        }

        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveMatchedGrade(PDO $pdo, int $storeId, int $currentPoints): ?array
    {
        if ($storeId > 0) {
            $storeStmt = $pdo->prepare(
                'SELECT id, grade_name, discount_rate
                 FROM qiling_customer_grades
                 WHERE store_id = :store_id
                   AND enabled = 1
                   AND threshold_points <= :threshold_points
                 ORDER BY threshold_points DESC, id DESC
                 LIMIT 1'
            );
            $storeStmt->execute([
                'store_id' => $storeId,
                'threshold_points' => $currentPoints,
            ]);
            $storeGrade = $storeStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($storeGrade)) {
                return $storeGrade;
            }
        }

        $globalStmt = $pdo->prepare(
            'SELECT id, grade_name, discount_rate
             FROM qiling_customer_grades
             WHERE store_id = 0
               AND enabled = 1
               AND threshold_points <= :threshold_points
             ORDER BY threshold_points DESC, id DESC
             LIMIT 1'
        );
        $globalStmt->execute([
            'threshold_points' => $currentPoints,
        ]);
        $globalGrade = $globalStmt->fetch(PDO::FETCH_ASSOC);

        return is_array($globalGrade) ? $globalGrade : null;
    }
}

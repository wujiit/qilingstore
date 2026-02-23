<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class CustomerService
{
    /**
     * @return array<string, mixed>|null
     */
    public static function findByIdOrMobile(PDO $pdo, int $customerId, string $mobile, bool $forUpdate = false): ?array
    {
        if ($customerId <= 0 && $mobile === '') {
            return null;
        }

        $sql = 'SELECT *
                FROM qiling_customers
                WHERE 1 = 1';
        $params = [];

        if ($customerId > 0) {
            $sql .= ' AND id = :id';
            $params['id'] = $customerId;
        }
        if ($mobile !== '') {
            $sql .= ' AND mobile = :mobile';
            $params['mobile'] = $mobile;
        }

        $sql .= ' LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function loadById(PDO $pdo, int $customerId, bool $forUpdate = false): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        $sql = 'SELECT *
                FROM qiling_customers
                WHERE id = :id
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

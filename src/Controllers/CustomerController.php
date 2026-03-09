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
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CustomerController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $cursor = isset($_GET['cursor']) && is_numeric($_GET['cursor']) ? (int) $_GET['cursor'] : 0;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 1000;
        $limit = max(1, min($limit, 2000));
        $queryLimit = $limit + 1;
        $pdo = Database::pdo();
        CustomerPortalService::ensureTables($pdo);

        $sql = 'SELECT c.*, s.store_name,
                       pt.token_prefix AS portal_token,
                       pt.expire_at AS portal_expire_at
                FROM qiling_customers c
                LEFT JOIN qiling_stores s ON s.id = c.store_id
                LEFT JOIN (
                    SELECT t1.customer_id, t1.token_prefix, t1.expire_at
                    FROM qiling_customer_portal_tokens t1
                    INNER JOIN (
                        SELECT customer_id, MAX(id) AS latest_id
                        FROM qiling_customer_portal_tokens
                        WHERE status = :portal_status
                          AND (expire_at IS NULL OR expire_at >= :portal_now)
                        GROUP BY customer_id
                    ) latest ON latest.customer_id = t1.customer_id
                            AND latest.latest_id = t1.id
                ) pt ON pt.customer_id = c.id
                WHERE 1 = 1';
        $params = [];
        $params['portal_status'] = 'active';
        $params['portal_now'] = gmdate('Y-m-d H:i:s');
        if ($scopeStoreId !== null) {
            $sql .= ' AND c.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($cursor > 0) {
            $sql .= ' AND c.id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $nextCursor = null;
        if ($hasMore && !empty($rows)) {
            $tail = $rows[count($rows) - 1];
            $nextCursor = (int) ($tail['id'] ?? 0);
            if ($nextCursor <= 0) {
                $nextCursor = null;
            }
        }

        $customerIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $customerIds[] = $id;
            }
        }
        $tagMap = self::tagsByCustomerIds($customerIds);

        foreach ($rows as &$row) {
            $customerId = (int) ($row['id'] ?? 0);
            $row['tags'] = $tagMap[$customerId] ?? [];
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => [
                'limit' => $limit,
                'cursor' => $cursor > 0 ? $cursor : null,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }

    public static function create(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $name = Request::str($data, 'name');
        $mobile = Request::str($data, 'mobile');

        if ($name === '' || $mobile === '') {
            Response::json(['message' => 'name and mobile are required'], 422);
            return;
        }

        $pdo = Database::pdo();
        CustomerPortalService::ensureTables($pdo);
        $customerNo = 'QLC' . gmdate('ymd') . random_int(1000, 9999);
        $now = gmdate('Y-m-d H:i:s');
        $birthday = Request::str($data, 'birthday');
        $gender = Request::str($data, 'gender', 'unknown');
        if (!in_array($gender, ['male', 'female', 'unknown'], true)) {
            $gender = 'unknown';
        }

        $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0));
        $tags = Request::strList($data, 'tags');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_customers
                (customer_no, store_id, name, mobile, gender, birthday, source_channel, skin_type, allergies, notes, total_spent, visit_count, status, created_at, updated_at)
                VALUES
                (:customer_no, :store_id, :name, :mobile, :gender, :birthday, :source_channel, :skin_type, :allergies, :notes, 0.00, 0, :status, :created_at, :updated_at)'
            );

            $stmt->execute([
                'customer_no' => $customerNo,
                'store_id' => $storeId,
                'name' => $name,
                'mobile' => $mobile,
                'gender' => $gender,
                'birthday' => $birthday !== '' ? $birthday : null,
                'source_channel' => Request::str($data, 'source_channel'),
                'skin_type' => Request::str($data, 'skin_type'),
                'allergies' => Request::str($data, 'allergies'),
                'notes' => Request::str($data, 'notes'),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $customerId = (int) $pdo->lastInsertId();
            self::attachTags($customerId, $tags);
            $portalTokenInfo = self::createDefaultPortalToken($pdo, $customerId, $storeId, (int) ($user['id'] ?? 0));
            $portalToken = (string) ($portalTokenInfo['token'] ?? '');
            $portalUrl = self::customerPortalUrl($portalToken);

            Audit::log((int) $user['id'], 'customer.create', 'customer', $customerId, 'Create customer', [
                'customer_no' => $customerNo,
                'portal_token_id' => (int) ($portalTokenInfo['id'] ?? 0),
            ]);

            $pdo->commit();
            Response::json([
                'id' => $customerId,
                'customer_no' => $customerNo,
                'portal' => [
                    'token' => $portalToken,
                    'portal_url' => $portalUrl,
                    'token_prefix' => (string) ($portalTokenInfo['token_prefix'] ?? ''),
                    'expire_at' => $portalTokenInfo['expire_at'] ?? null,
                ],
            ], 201);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create customer failed', $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function createDefaultPortalToken(PDO $pdo, int $customerId, int $storeId, int $creatorUserId): array
    {
        return CustomerPortalService::createToken(
            $pdo,
            $customerId,
            $storeId,
            $creatorUserId,
            null,
            'auto create from customer onboarding'
        );
    }

    private static function customerPortalUrl(string $token): string
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

    /** @param array<int, string> $tags */
    private static function attachTags(int $customerId, array $tags): void
    {
        if ($customerId <= 0 || empty($tags)) {
            return;
        }

        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');

        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') {
                continue;
            }

            $tagId = self::findOrCreateTag($tagName, $now);

            $existsStmt = $pdo->prepare('SELECT id FROM qiling_customer_tag_rel WHERE customer_id = :customer_id AND tag_id = :tag_id LIMIT 1');
            $existsStmt->execute([
                'customer_id' => $customerId,
                'tag_id' => $tagId,
            ]);
            if ($existsStmt->fetchColumn()) {
                continue;
            }

            $insertStmt = $pdo->prepare('INSERT INTO qiling_customer_tag_rel (customer_id, tag_id, created_at) VALUES (:customer_id, :tag_id, :created_at)');
            $insertStmt->execute([
                'customer_id' => $customerId,
                'tag_id' => $tagId,
                'created_at' => $now,
            ]);
        }
    }

    private static function findOrCreateTag(string $tagName, string $now): int
    {
        $pdo = Database::pdo();

        $select = $pdo->prepare('SELECT id FROM qiling_customer_tags WHERE tag_name = :tag_name LIMIT 1');
        $select->execute(['tag_name' => $tagName]);
        $id = $select->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $insert = $pdo->prepare('INSERT INTO qiling_customer_tags (tag_name, color, created_at, updated_at) VALUES (:tag_name, :color, :created_at, :updated_at)');
        $insert->execute([
            'tag_name' => $tagName,
            'color' => '#4f46e5',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<int, int> $customerIds
     * @return array<int, array<int, string>>
     */
    private static function tagsByCustomerIds(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => is_numeric($id) ? (int) $id : 0,
            $customerIds
        ), static fn (int $id): bool => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $params = [];
        $holders = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }

        $sql = 'SELECT r.customer_id, t.tag_name
                FROM qiling_customer_tag_rel r
                INNER JOIN qiling_customer_tags t ON t.id = r.tag_id
                WHERE r.customer_id IN (' . implode(',', $holders) . ')
                ORDER BY r.customer_id ASC, t.id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($customerId <= 0) {
                continue;
            }
            if (!array_key_exists($customerId, $map)) {
                $map[$customerId] = [];
            }
            $tagName = trim((string) ($row['tag_name'] ?? ''));
            if ($tagName !== '') {
                $map[$customerId][] = $tagName;
            }
        }

        return $map;
    }
}

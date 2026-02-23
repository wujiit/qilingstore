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
        $pdo = Database::pdo();
        CustomerPortalService::ensureTables($pdo);

        $sql = 'SELECT c.*, s.store_name,
                       pt.token_prefix AS portal_token,
                       pt.expire_at AS portal_expire_at
                FROM qiling_customers c
                LEFT JOIN qiling_stores s ON s.id = c.store_id
                LEFT JOIN qiling_customer_portal_tokens pt ON pt.id = (
                    SELECT t.id
                    FROM qiling_customer_portal_tokens t
                    WHERE t.customer_id = c.id
                      AND t.status = :portal_status
                      AND (t.expire_at IS NULL OR t.expire_at >= :portal_now)
                    ORDER BY t.id DESC
                    LIMIT 1
                )';
        $params = [];
        $params['portal_status'] = 'active';
        $params['portal_now'] = gmdate('Y-m-d H:i:s');
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE c.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY c.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['tags'] = self::tagsByCustomerId((int) $row['id']);
        }

        Response::json(['data' => $rows]);
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
        $attempts = 80;
        for ($i = 0; $i < $attempts; $i++) {
            $token = self::randomNumericToken();
            try {
                return CustomerPortalService::createToken(
                    $pdo,
                    $customerId,
                    $storeId,
                    $creatorUserId,
                    null,
                    'auto create from customer onboarding',
                    $token
                );
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'token already exists') {
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('create default portal token failed');
    }

    private static function randomNumericToken(): string
    {
        $len = random_int(4, 6);
        $min = 10 ** ($len - 1);
        $max = (10 ** $len) - 1;
        return (string) random_int($min, $max);
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

    /** @return array<int, string> */
    private static function tagsByCustomerId(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.tag_name
             FROM qiling_customer_tag_rel r
             INNER JOIN qiling_customer_tags t ON t.id = r.tag_id
             WHERE r.customer_id = :customer_id
             ORDER BY t.id DESC'
        );
        $stmt->execute(['customer_id' => $customerId]);

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $rows)));
    }
}

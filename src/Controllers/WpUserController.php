<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class WpUserController
{
    public static function sync(): void
    {
        $rawBody = Request::rawBody();
        Auth::requireWpSyncSecret($rawBody);

        $data = Request::jsonBody();
        $items = [];

        if (isset($data['users']) && is_array($data['users'])) {
            $items = $data['users'];
        } elseif (!empty($data)) {
            $items = [$data];
        }

        if (empty($items)) {
            Response::json(['message' => 'no users payload'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $synced = 0;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $wpUserId = (int) ($item['wp_user_id'] ?? $item['id'] ?? 0);
                $username = trim((string) ($item['username'] ?? $item['user_login'] ?? ''));
                $email = trim((string) ($item['email'] ?? $item['user_email'] ?? ''));
                $displayName = trim((string) ($item['display_name'] ?? ''));
                $roles = $item['roles'] ?? [];
                $meta = $item['meta'] ?? [];

                if ($wpUserId <= 0 || $username === '' || $email === '') {
                    continue;
                }

                $thisNow = gmdate('Y-m-d H:i:s');

                $sql = 'INSERT INTO qiling_wp_users (wp_user_id, username, email, display_name, roles_json, meta_json, synced_at, created_at, updated_at)
                        VALUES (:wp_user_id, :username, :email, :display_name, :roles_json, :meta_json, :synced_at, :created_at, :updated_at)
                        ON DUPLICATE KEY UPDATE
                            username = VALUES(username),
                            email = VALUES(email),
                            display_name = VALUES(display_name),
                            roles_json = VALUES(roles_json),
                            meta_json = VALUES(meta_json),
                            synced_at = VALUES(synced_at),
                            updated_at = VALUES(updated_at)';

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'wp_user_id' => $wpUserId,
                    'username' => $username,
                    'email' => $email,
                    'display_name' => $displayName,
                    'roles_json' => json_encode($roles, JSON_UNESCAPED_UNICODE),
                    'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'synced_at' => $thisNow,
                    'created_at' => $thisNow,
                    'updated_at' => $thisNow,
                ]);

                // 尝试通过邮箱回写本地账号关联 wp_user_id。
                $linkStmt = $pdo->prepare('UPDATE qiling_users SET wp_user_id = :wp_user_id, updated_at = :updated_at WHERE email = :email');
                $linkStmt->execute([
                    'wp_user_id' => $wpUserId,
                    'updated_at' => $thisNow,
                    'email' => $email,
                ]);

                $synced++;
            }

            $pdo->commit();
            Response::json(['synced' => $synced]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::serverError('sync failed', $e);
        }
    }

    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireAdmin($user);

        $stmt = Database::pdo()->query('SELECT * FROM qiling_wp_users ORDER BY id DESC LIMIT 200');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json(['data' => $rows]);
    }
}

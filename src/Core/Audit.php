<?php

declare(strict_types=1);

namespace Qiling\Core;

final class Audit
{
    /** @param array<string, mixed> $context */
    public static function log(int $actorUserId, string $action, string $entityType, int $entityId, string $message, array $context = []): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO qiling_audit_logs (actor_user_id, action, entity_type, entity_id, message, context_json, created_at)
             VALUES (:actor_user_id, :action, :entity_type, :entity_id, :message, :context_json, :created_at)'
        );

        $stmt->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'message' => $message,
            'context_json' => empty($context) ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

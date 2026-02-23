<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\FollowupService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class FollowupController
{
    public static function plans(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        if (DataScope::isAdmin($user)) {
            $rows = Database::pdo()->query('SELECT * FROM qiling_followup_plans ORDER BY store_id ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
            Response::json(['data' => $rows]);
            return;
        }

        $scopeStoreId = DataScope::resolveFilterStoreId($user, null);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM qiling_followup_plans
             WHERE store_id IN (0, :store_id)
             ORDER BY store_id ASC, id ASC'
        );
        $stmt->execute(['store_id' => $scopeStoreId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(['data' => $rows]);
    }

    public static function upsertPlan(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $storeId = DataScope::resolveInputStoreId($user, Request::int($data, 'store_id', 0), true);
        $triggerType = Request::str($data, 'trigger_type', 'appointment_completed');
        $planName = Request::str($data, 'plan_name', '预约完成回访计划');
        $enabled = Request::int($data, 'enabled', 1) === 1 ? 1 : 0;

        if ($triggerType === '') {
            Response::json(['message' => 'trigger_type is required'], 422);
            return;
        }

        $scheduleDays = self::sanitizeScheduleDays(Request::strList($data, 'schedule_days'));
        if (empty($scheduleDays)) {
            $scheduleDays = [1, 3, 7];
        }

        $now = gmdate('Y-m-d H:i:s');

        $stmt = Database::pdo()->prepare(
            'INSERT INTO qiling_followup_plans
             (store_id, trigger_type, plan_name, schedule_days_json, enabled, created_at, updated_at)
             VALUES
             (:store_id, :trigger_type, :plan_name, :schedule_days_json, :enabled, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                plan_name = VALUES(plan_name),
                schedule_days_json = VALUES(schedule_days_json),
                enabled = VALUES(enabled),
                updated_at = VALUES(updated_at)'
        );

        $stmt->execute([
            'store_id' => $storeId,
            'trigger_type' => $triggerType,
            'plan_name' => $planName,
            'schedule_days_json' => json_encode($scheduleDays, JSON_UNESCAPED_UNICODE),
            'enabled' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log((int) $user['id'], 'followup.plan.upsert', 'followup_plan', 0, 'Upsert followup plan', [
            'store_id' => $storeId,
            'trigger_type' => $triggerType,
            'schedule_days' => $scheduleDays,
            'enabled' => $enabled,
        ]);

        Response::json([
            'store_id' => $storeId,
            'trigger_type' => $triggerType,
            'schedule_days' => $scheduleDays,
            'enabled' => $enabled,
        ]);
    }

    public static function tasks(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $storeId = DataScope::resolveFilterStoreId($user, $storeId);
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT t.*, c.name AS customer_name, c.mobile AS customer_mobile, a.appointment_no
                FROM qiling_followup_tasks t
                INNER JOIN qiling_customers c ON c.id = t.customer_id
                INNER JOIN qiling_appointments a ON a.id = t.appointment_id
                WHERE 1 = 1';

        $params = [];

        if ($status !== '') {
            $sql .= ' AND t.status = :status';
            $params['status'] = $status;
        }

        if ($storeId !== null) {
            $sql .= ' AND t.store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= ' ORDER BY t.due_at ASC LIMIT ' . $limit;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json(['data' => $rows]);
    }

    public static function updateTaskStatus(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $taskId = Request::int($data, 'task_id', 0);
        $status = Request::str($data, 'status');
        $note = Request::str($data, 'note');

        $allowed = ['pending', 'completed', 'skipped', 'cancelled'];
        if ($taskId <= 0 || !in_array($status, $allowed, true)) {
            Response::json(['message' => 'task_id and valid status are required'], 422);
            return;
        }

        $taskStmt = Database::pdo()->prepare('SELECT id, store_id FROM qiling_followup_tasks WHERE id = :id LIMIT 1');
        $taskStmt->execute(['id' => $taskId]);
        $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($task)) {
            Response::json(['message' => 'task not found'], 404);
            return;
        }
        DataScope::assertStoreAccess($user, (int) ($task['store_id'] ?? 0));

        $now = gmdate('Y-m-d H:i:s');
        $completedAt = in_array($status, ['completed', 'skipped', 'cancelled'], true) ? $now : null;
        $completedBy = in_array($status, ['completed', 'skipped', 'cancelled'], true) ? (int) $user['id'] : null;
        $notifyStatus = $status === 'pending' ? 'pending' : null;
        $notifyError = $status === 'pending' ? '' : null;

        $stmt = Database::pdo()->prepare(
            'UPDATE qiling_followup_tasks
             SET status = :status,
                 notify_status = COALESCE(:notify_status, notify_status),
                 notified_at = CASE WHEN :notified_at_reset = 1 THEN NULL ELSE notified_at END,
                 notify_channel_id = CASE WHEN :notify_channel_reset = 1 THEN NULL ELSE notify_channel_id END,
                 notify_error = COALESCE(:notify_error, notify_error),
                 result_note = :result_note,
                 completed_at = :completed_at,
                 completed_by = :completed_by,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'notify_status' => $notifyStatus,
            'notified_at_reset' => $status === 'pending' ? 1 : 0,
            'notify_channel_reset' => $status === 'pending' ? 1 : 0,
            'notify_error' => $notifyError,
            'result_note' => $note,
            'completed_at' => $completedAt,
            'completed_by' => $completedBy,
            'updated_at' => $now,
            'id' => $taskId,
        ]);

        Audit::log((int) $user['id'], 'followup.task.status', 'followup_task', $taskId, 'Update followup task status', [
            'status' => $status,
            'note' => $note,
        ]);

        Response::json([
            'task_id' => $taskId,
            'status' => $status,
        ]);
    }

    public static function generate(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        DataScope::requireManager($user);
        $data = Request::jsonBody();

        $appointmentId = Request::int($data, 'appointment_id', 0);
        $limit = Request::int($data, 'limit', 200);
        $storeId = DataScope::resolveFilterStoreId(
            $user,
            Request::int($data, 'store_id', 0) > 0 ? Request::int($data, 'store_id', 0) : null
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if ($appointmentId > 0) {
                $stmt = $pdo->prepare('SELECT id, customer_id, store_id, status, updated_at FROM qiling_appointments WHERE id = :id LIMIT 1 FOR UPDATE');
                $stmt->execute(['id' => $appointmentId]);
                $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($appointment)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'appointment not found'], 404);
                    return;
                }
                DataScope::assertStoreAccess($user, (int) ($appointment['store_id'] ?? 0));

                if (($appointment['status'] ?? '') !== 'completed') {
                    $pdo->rollBack();
                    Response::json(['message' => 'appointment is not completed'], 409);
                    return;
                }

                $result = FollowupService::generateForAppointment(
                    $pdo,
                    (int) $appointment['id'],
                    (int) $appointment['customer_id'],
                    (int) $appointment['store_id'],
                    (string) $appointment['updated_at']
                );

                Audit::log((int) $user['id'], 'followup.generate', 'appointment', (int) $appointment['id'], 'Generate followup tasks for appointment', $result);

                $pdo->commit();
                Response::json([
                    'mode' => 'single',
                    'appointment_id' => (int) $appointment['id'],
                    'result' => $result,
                ]);
                return;
            }

            $result = FollowupService::generateForCompletedAppointments($pdo, $limit, $storeId);
            Audit::log((int) $user['id'], 'followup.generate.batch', 'followup', 0, 'Generate followup tasks batch', $result);

            $pdo->commit();
            Response::json([
                'mode' => 'batch',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('generate followup failed', $e);
        }
    }

    /**
     * @param array<int, string> $scheduleDays
     * @return array<int, int>
     */
    private static function sanitizeScheduleDays(array $scheduleDays): array
    {
        $days = [];

        foreach ($scheduleDays as $item) {
            if (!is_numeric($item)) {
                continue;
            }

            $day = (int) $item;
            if ($day <= 0 || $day > 365) {
                continue;
            }

            $days[] = $day;
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }
}

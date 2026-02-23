<?php

declare(strict_types=1);

namespace Qiling\Core;

use PDO;

final class FollowupService
{
    /**
     * @return array<string, mixed>
     */
    public static function generateForAppointment(PDO $pdo, int $appointmentId, int $customerId, int $storeId, string $baseAt): array
    {
        $plan = self::resolvePlan($pdo, $storeId, 'appointment_completed');
        if (!$plan || (int) ($plan['enabled'] ?? 0) !== 1) {
            return [
                'generated' => 0,
                'reactivated' => 0,
                'skipped' => 'plan_disabled',
            ];
        }

        $scheduleDays = self::sanitizeScheduleDays($plan['schedule_days_json'] ?? null);
        if (empty($scheduleDays)) {
            return [
                'generated' => 0,
                'reactivated' => 0,
                'skipped' => 'no_schedule_days',
            ];
        }

        $customerName = self::resolveCustomerName($pdo, $customerId);
        $now = gmdate('Y-m-d H:i:s');

        $generated = 0;
        $reactivated = 0;

        foreach ($scheduleDays as $day) {
            $dueAt = gmdate('Y-m-d H:i:s', strtotime($baseAt . ' +' . $day . ' days'));
            $title = 'D+' . $day . '回访';
            if ($customerName !== '') {
                $title .= ' - ' . $customerName;
            }

            $content = '预约完成后 D+' . $day . ' 回访任务';

            $existsStmt = $pdo->prepare(
                'SELECT id, status
                 FROM qiling_followup_tasks
                 WHERE appointment_id = :appointment_id
                   AND schedule_day = :schedule_day
                 LIMIT 1
                 FOR UPDATE'
            );
            $existsStmt->execute([
                'appointment_id' => $appointmentId,
                'schedule_day' => $day,
            ]);
            $exists = $existsStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($exists)) {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO qiling_followup_tasks
                     (plan_id, appointment_id, customer_id, store_id, schedule_day, due_at, status, notify_status, notified_at, notify_channel_id, notify_error, assigned_staff_id, title, content, result_note, completed_at, completed_by, created_at, updated_at)
                     VALUES
                     (:plan_id, :appointment_id, :customer_id, :store_id, :schedule_day, :due_at, :status, :notify_status, :notified_at, :notify_channel_id, :notify_error, :assigned_staff_id, :title, :content, :result_note, :completed_at, :completed_by, :created_at, :updated_at)'
                );
                $insertStmt->execute([
                    'plan_id' => (int) $plan['id'],
                    'appointment_id' => $appointmentId,
                    'customer_id' => $customerId,
                    'store_id' => $storeId,
                    'schedule_day' => $day,
                    'due_at' => $dueAt,
                    'status' => 'pending',
                    'notify_status' => 'pending',
                    'notified_at' => null,
                    'notify_channel_id' => null,
                    'notify_error' => '',
                    'assigned_staff_id' => null,
                    'title' => $title,
                    'content' => $content,
                    'result_note' => '',
                    'completed_at' => null,
                    'completed_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $generated++;
                continue;
            }

            if (($exists['status'] ?? '') === 'cancelled') {
                $updateStmt = $pdo->prepare(
                    'UPDATE qiling_followup_tasks
                     SET status = :status,
                         notify_status = :notify_status,
                         notified_at = :notified_at,
                         notify_channel_id = :notify_channel_id,
                         notify_error = :notify_error,
                         due_at = :due_at,
                         title = :title,
                         content = :content,
                         result_note = :result_note,
                         completed_at = :completed_at,
                         completed_by = :completed_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    'status' => 'pending',
                    'notify_status' => 'pending',
                    'notified_at' => null,
                    'notify_channel_id' => null,
                    'notify_error' => '',
                    'due_at' => $dueAt,
                    'title' => $title,
                    'content' => $content,
                    'result_note' => '',
                    'completed_at' => null,
                    'completed_by' => null,
                    'updated_at' => $now,
                    'id' => (int) $exists['id'],
                ]);
                $reactivated++;
            }
        }

        return [
            'plan_id' => (int) $plan['id'],
            'schedule_days' => $scheduleDays,
            'generated' => $generated,
            'reactivated' => $reactivated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cancelPendingForAppointment(PDO $pdo, int $appointmentId, int $operatorUserId, string $reason): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'UPDATE qiling_followup_tasks
             SET status = :status,
                 result_note = :result_note,
                 updated_at = :updated_at,
                 completed_at = :completed_at,
                 completed_by = :completed_by
             WHERE appointment_id = :appointment_id
               AND status = :pending_status'
        );
        $stmt->execute([
            'status' => 'cancelled',
            'result_note' => $reason,
            'updated_at' => $now,
            'completed_at' => $now,
            'completed_by' => $operatorUserId,
            'appointment_id' => $appointmentId,
            'pending_status' => 'pending',
        ]);

        return [
            'cancelled' => $stmt->rowCount(),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function generateForCompletedAppointments(PDO $pdo, int $limit = 200, ?int $storeId = null): array
    {
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT id, customer_id, store_id, updated_at
                FROM qiling_appointments
                WHERE status = :status';
        $params = [
            'status' => 'completed',
        ];

        if ($storeId !== null && $storeId > 0) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $sql .= '
                ORDER BY id DESC
                LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalGenerated = 0;
        $totalReactivated = 0;

        foreach ($appointments as $appointment) {
            $result = self::generateForAppointment(
                $pdo,
                (int) $appointment['id'],
                (int) $appointment['customer_id'],
                (int) $appointment['store_id'],
                (string) $appointment['updated_at']
            );

            $totalGenerated += (int) ($result['generated'] ?? 0);
            $totalReactivated += (int) ($result['reactivated'] ?? 0);
        }

        return [
            'appointments' => count($appointments),
            'generated' => $totalGenerated,
            'reactivated' => $totalReactivated,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolvePlan(PDO $pdo, int $storeId, string $triggerType): ?array
    {
        $storeStmt = $pdo->prepare(
            'SELECT *
             FROM qiling_followup_plans
             WHERE store_id = :store_id
               AND trigger_type = :trigger_type
             LIMIT 1'
        );
        $storeStmt->execute([
            'store_id' => $storeId,
            'trigger_type' => $triggerType,
        ]);
        $plan = $storeStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($plan)) {
            return $plan;
        }

        $globalStmt = $pdo->prepare(
            'SELECT *
             FROM qiling_followup_plans
             WHERE store_id = 0
               AND trigger_type = :trigger_type
             LIMIT 1'
        );
        $globalStmt->execute(['trigger_type' => $triggerType]);
        $globalPlan = $globalStmt->fetch(PDO::FETCH_ASSOC);

        return is_array($globalPlan) ? $globalPlan : null;
    }

    /**
     * @return array<int, int>
     */
    private static function sanitizeScheduleDays($scheduleDaysJson): array
    {
        $default = [1, 3, 7];

        if (!is_string($scheduleDaysJson) || trim($scheduleDaysJson) === '') {
            return $default;
        }

        $decoded = json_decode($scheduleDaysJson, true);
        if (!is_array($decoded)) {
            return $default;
        }

        $days = [];
        foreach ($decoded as $item) {
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

        return empty($days) ? $default : $days;
    }

    private static function resolveCustomerName(PDO $pdo, int $customerId): string
    {
        $stmt = $pdo->prepare('SELECT name FROM qiling_customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : '';
    }
}

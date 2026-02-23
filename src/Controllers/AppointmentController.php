<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\AssetService;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\FollowupService;
use Qiling\Core\Push\PushService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class AppointmentController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);

        $sql = 'SELECT a.*, c.name AS customer_name, c.mobile AS customer_mobile,
                       s.service_name, st.staff_no, u.username AS staff_username,
                       ac.member_card_id AS consumed_member_card_id,
                       ac.consume_sessions,
                       ac.before_sessions,
                       ac.after_sessions,
                       ac.rolled_back_at,
                       ac.rollback_note,
                       mc.card_no AS consumed_card_no
                FROM qiling_appointments a
                INNER JOIN qiling_customers c ON c.id = a.customer_id
                LEFT JOIN qiling_services s ON s.id = a.service_id
                LEFT JOIN qiling_staff st ON st.id = a.staff_id
                LEFT JOIN qiling_users u ON u.id = st.user_id
                LEFT JOIN qiling_appointment_consumes ac ON ac.appointment_id = a.id
                LEFT JOIN qiling_member_cards mc ON mc.id = ac.member_card_id';
        $params = [];
        if ($scopeStoreId !== null) {
            $sql .= ' WHERE a.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        $sql .= ' ORDER BY a.id DESC';

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
        if ($customerId <= 0) {
            Response::json(['message' => 'customer_id is required'], 422);
            return;
        }

        $startAt = Request::str($data, 'start_at');
        $endAt = Request::str($data, 'end_at');
        $startTs = strtotime($startAt);
        $endTs = strtotime($endAt);

        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            Response::json(['message' => 'invalid start_at or end_at'], 422);
            return;
        }

        $storeIdInput = Request::int($data, 'store_id', 0);
        $staffId = Request::int($data, 'staff_id', 0);
        $serviceId = Request::int($data, 'service_id', 0);

        $pdo = Database::pdo();

        $existsCustomer = $pdo->prepare('SELECT id, store_id FROM qiling_customers WHERE id = :id LIMIT 1');
        $existsCustomer->execute(['id' => $customerId]);
        $customer = $existsCustomer->fetch(PDO::FETCH_ASSOC);
        if (!is_array($customer)) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }
        $customerStoreId = (int) ($customer['store_id'] ?? 0);
        DataScope::assertStoreAccess($user, $customerStoreId);
        $storeId = DataScope::resolveInputStoreId($user, $storeIdInput > 0 ? $storeIdInput : $customerStoreId);
        if ($customerStoreId > 0 && $storeId !== $customerStoreId) {
            Response::json(['message' => 'customer store mismatch with appointment store'], 422);
            return;
        }

        if ($staffId > 0) {
            $existsStaff = $pdo->prepare('SELECT id, store_id FROM qiling_staff WHERE id = :id LIMIT 1');
            $existsStaff->execute(['id' => $staffId]);
            $staff = $existsStaff->fetch(PDO::FETCH_ASSOC);
            if (!is_array($staff)) {
                Response::json(['message' => 'staff not found'], 404);
                return;
            }
            $staffStoreId = (int) ($staff['store_id'] ?? 0);
            DataScope::assertStoreAccess($user, $staffStoreId);
            if ($staffStoreId > 0 && $staffStoreId !== $storeId) {
                Response::json(['message' => 'staff store mismatch with appointment store'], 422);
                return;
            }

            if (self::staffConflict($staffId, gmdate('Y-m-d H:i:s', $startTs), gmdate('Y-m-d H:i:s', $endTs))) {
                Response::json(['message' => 'staff schedule conflict'], 409);
                return;
            }
        }

        if ($serviceId > 0) {
            $existsService = $pdo->prepare('SELECT id, store_id FROM qiling_services WHERE id = :id LIMIT 1');
            $existsService->execute(['id' => $serviceId]);
            $service = $existsService->fetch(PDO::FETCH_ASSOC);
            if (!is_array($service)) {
                Response::json(['message' => 'service not found'], 404);
                return;
            }
            $serviceStoreId = (int) ($service['store_id'] ?? 0);
            if ($serviceStoreId > 0 && $serviceStoreId !== $storeId) {
                Response::json(['message' => 'service store mismatch with appointment store'], 422);
                return;
            }
        }

        $appointmentNo = 'QLA' . gmdate('ymd') . random_int(1000, 9999);
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_appointments
             (appointment_no, store_id, customer_id, staff_id, service_id, start_at, end_at, status, source_channel, notes, created_by, created_at, updated_at)
             VALUES
             (:appointment_no, :store_id, :customer_id, :staff_id, :service_id, :start_at, :end_at, :status, :source_channel, :notes, :created_by, :created_at, :updated_at)'
        );

        $stmt->execute([
            'appointment_no' => $appointmentNo,
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'staff_id' => $staffId > 0 ? $staffId : null,
            'service_id' => $serviceId > 0 ? $serviceId : null,
            'start_at' => gmdate('Y-m-d H:i:s', $startTs),
            'end_at' => gmdate('Y-m-d H:i:s', $endTs),
            'status' => 'booked',
            'source_channel' => Request::str($data, 'source_channel'),
            'notes' => Request::str($data, 'notes'),
            'created_by' => (int) $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $pdo->lastInsertId();

        Audit::log((int) $user['id'], 'appointment.create', 'appointment', $id, 'Create appointment', ['appointment_no' => $appointmentNo]);

        $pushResult = null;
        $pushWarning = '';
        try {
            $pushResult = PushService::notifyAppointmentCreated($pdo, $id, 'appointment_created_manual');
        } catch (\Throwable $pushError) {
            $pushWarning = $pushError->getMessage();
        }

        $response = ['id' => $id, 'appointment_no' => $appointmentNo];
        if (is_array($pushResult)) {
            $response['push'] = $pushResult;
        }
        if ($pushWarning !== '') {
            $response['push_warning'] = '预约已创建，消息推送失败：' . $pushWarning;
        }

        Response::json($response, 201);
    }

    public static function updateStatus(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $appointmentId = Request::int($data, 'appointment_id', 0);
        $status = Request::str($data, 'status');

        $allowed = ['booked', 'completed', 'cancelled', 'no_show'];
        if ($appointmentId <= 0 || !in_array($status, $allowed, true)) {
            Response::json(['message' => 'appointment_id and valid status are required'], 422);
            return;
        }

        $memberCardId = Request::int($data, 'member_card_id', 0);
        $consumeSessions = Request::int($data, 'consume_sessions', 1);
        $consumeNote = Request::str($data, 'consume_note', '预约完成自动核销');

        if ($status === 'completed' && $memberCardId > 0 && $consumeSessions <= 0) {
            Response::json(['message' => 'consume_sessions must be positive'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $appointmentStmt = $pdo->prepare(
                'SELECT id, appointment_no, customer_id, store_id, status
                 FROM qiling_appointments
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $appointmentStmt->execute(['id' => $appointmentId]);
            $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($appointment)) {
                $pdo->rollBack();
                Response::json(['message' => 'appointment not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($appointment['store_id'] ?? 0));

            $consumeResult = null;
            $rollbackResult = null;

            if ($status === 'completed' && $memberCardId > 0) {
                $consumeResult = self::consumeCardForAppointment(
                    $pdo,
                    $appointmentId,
                    (int) $appointment['customer_id'],
                    $memberCardId,
                    $consumeSessions,
                    (int) $user['id'],
                    $consumeNote
                );
            }

            if (in_array($status, ['booked', 'cancelled', 'no_show'], true)) {
                $rollbackResult = self::rollbackConsumeForAppointment(
                    $pdo,
                    $appointmentId,
                    (int) $appointment['customer_id'],
                    (int) $user['id'],
                    '预约状态变更自动回退'
                );
            }

            $followupResult = null;
            $followupCancelResult = null;
            $statusChangedAt = gmdate('Y-m-d H:i:s');

            $stmt = $pdo->prepare('UPDATE qiling_appointments SET status = :status, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'updated_at' => $statusChangedAt,
                'id' => $appointmentId,
            ]);

            if ($status === 'completed') {
                $followupResult = FollowupService::generateForAppointment(
                    $pdo,
                    $appointmentId,
                    (int) $appointment['customer_id'],
                    (int) ($appointment['store_id'] ?? 0),
                    $statusChangedAt
                );
            } elseif (in_array($status, ['booked', 'cancelled', 'no_show'], true)) {
                $followupCancelResult = FollowupService::cancelPendingForAppointment(
                    $pdo,
                    $appointmentId,
                    (int) $user['id'],
                    '预约状态变更为 ' . $status . '，自动取消未执行回访任务'
                );
            }

            Audit::log((int) $user['id'], 'appointment.status', 'appointment', $appointmentId, 'Update appointment status', [
                'from_status' => $appointment['status'],
                'status' => $status,
            ]);

            if (is_array($consumeResult)) {
                Audit::log((int) $user['id'], 'appointment.consume', 'appointment', $appointmentId, 'Consume member card via appointment', $consumeResult);
            }
            if (is_array($rollbackResult)) {
                Audit::log((int) $user['id'], 'appointment.rollback', 'appointment', $appointmentId, 'Rollback consumed member card via appointment status change', $rollbackResult);
            }
            if (is_array($followupResult)) {
                Audit::log((int) $user['id'], 'followup.generate.auto', 'appointment', $appointmentId, 'Generate followup tasks automatically on appointment completion', $followupResult);
            }
            if (is_array($followupCancelResult)) {
                Audit::log((int) $user['id'], 'followup.cancel.auto', 'appointment', $appointmentId, 'Cancel pending followup tasks automatically on appointment status change', $followupCancelResult);
            }

            $pdo->commit();

            Response::json([
                'appointment_id' => $appointmentId,
                'status' => $status,
                'consume' => $consumeResult,
                'rollback' => $rollbackResult,
                'followup' => $followupResult,
                'followup_cancel' => $followupCancelResult,
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e->getMessage();
            $statusCode = 409;
            if ($message === 'member card not found') {
                $statusCode = 404;
            }

            Response::json(['message' => $message], $statusCode);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::serverError('update status failed', $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function consumeCardForAppointment(
        PDO $pdo,
        int $appointmentId,
        int $customerId,
        int $memberCardId,
        int $consumeSessions,
        int $operatorUserId,
        string $note
    ): array {
        $consumeExistsStmt = $pdo->prepare('SELECT id FROM qiling_appointment_consumes WHERE appointment_id = :appointment_id LIMIT 1 FOR UPDATE');
        $consumeExistsStmt->execute(['appointment_id' => $appointmentId]);
        if ($consumeExistsStmt->fetchColumn()) {
            throw new \RuntimeException('appointment already consumed');
        }

        $cardStmt = $pdo->prepare(
            'SELECT id, card_no, customer_id, remaining_sessions, status
             FROM qiling_member_cards
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $cardStmt->execute(['id' => $memberCardId]);
        $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($card)) {
            throw new \RuntimeException('member card not found');
        }

        if ((int) $card['customer_id'] !== $customerId) {
            throw new \RuntimeException('member card does not belong to appointment customer');
        }

        if (($card['status'] ?? '') !== 'active') {
            throw new \RuntimeException('member card is not active');
        }

        $beforeSessions = (int) $card['remaining_sessions'];
        if ($beforeSessions < $consumeSessions) {
            throw new \RuntimeException('remaining sessions not enough');
        }

        $afterSessions = $beforeSessions - $consumeSessions;
        $cardStatus = $afterSessions > 0 ? 'active' : 'depleted';
        $now = gmdate('Y-m-d H:i:s');

        $updateCardStmt = $pdo->prepare(
            'UPDATE qiling_member_cards
             SET remaining_sessions = :remaining_sessions,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateCardStmt->execute([
            'remaining_sessions' => $afterSessions,
            'status' => $cardStatus,
            'updated_at' => $now,
            'id' => $memberCardId,
        ]);

        AssetService::insertMemberCardLog(
            $pdo,
            $memberCardId,
            $customerId,
            'appointment_consume',
            -$consumeSessions,
            $beforeSessions,
            $afterSessions,
            $operatorUserId,
            $note,
            $now
        );

        $insertConsumeStmt = $pdo->prepare(
            'INSERT INTO qiling_appointment_consumes
             (appointment_id, member_card_id, customer_id, consume_sessions, before_sessions, after_sessions, operator_user_id, note, created_at)
             VALUES
             (:appointment_id, :member_card_id, :customer_id, :consume_sessions, :before_sessions, :after_sessions, :operator_user_id, :note, :created_at)'
        );
        $insertConsumeStmt->execute([
            'appointment_id' => $appointmentId,
            'member_card_id' => $memberCardId,
            'customer_id' => $customerId,
            'consume_sessions' => $consumeSessions,
            'before_sessions' => $beforeSessions,
            'after_sessions' => $afterSessions,
            'operator_user_id' => $operatorUserId,
            'note' => $note,
            'created_at' => $now,
        ]);

        return [
            'member_card_id' => $memberCardId,
            'card_no' => $card['card_no'],
            'consume_sessions' => $consumeSessions,
            'before_sessions' => $beforeSessions,
            'after_sessions' => $afterSessions,
            'card_status' => $cardStatus,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function rollbackConsumeForAppointment(
        PDO $pdo,
        int $appointmentId,
        int $customerId,
        int $operatorUserId,
        string $note
    ): ?array {
        $consumeStmt = $pdo->prepare(
            'SELECT id, member_card_id, customer_id, consume_sessions, rolled_back_at
             FROM qiling_appointment_consumes
             WHERE appointment_id = :appointment_id
             LIMIT 1
             FOR UPDATE'
        );
        $consumeStmt->execute(['appointment_id' => $appointmentId]);
        $consume = $consumeStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($consume)) {
            return null;
        }

        if (!empty($consume['rolled_back_at'])) {
            return [
                'appointment_consume_id' => (int) $consume['id'],
                'already_rolled_back' => true,
            ];
        }

        if ((int) $consume['customer_id'] !== $customerId) {
            throw new \RuntimeException('appointment consume customer mismatch');
        }

        $memberCardId = (int) $consume['member_card_id'];
        $consumeSessions = (int) $consume['consume_sessions'];
        if ($consumeSessions <= 0) {
            throw new \RuntimeException('invalid consume sessions');
        }

        $cardStmt = $pdo->prepare(
            'SELECT id, card_no, total_sessions, remaining_sessions, status
             FROM qiling_member_cards
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $cardStmt->execute(['id' => $memberCardId]);
        $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($card)) {
            throw new \RuntimeException('member card not found');
        }

        $beforeSessions = (int) $card['remaining_sessions'];
        $totalSessions = max(0, (int) $card['total_sessions']);
        $afterSessions = $beforeSessions + $consumeSessions;
        if ($totalSessions > 0) {
            $afterSessions = min($afterSessions, $totalSessions);
        }
        $cardStatus = $afterSessions > 0 ? 'active' : 'depleted';
        $now = gmdate('Y-m-d H:i:s');

        $updateCardStmt = $pdo->prepare(
            'UPDATE qiling_member_cards
             SET remaining_sessions = :remaining_sessions,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateCardStmt->execute([
            'remaining_sessions' => $afterSessions,
            'status' => $cardStatus,
            'updated_at' => $now,
            'id' => $memberCardId,
        ]);

        AssetService::insertMemberCardLog(
            $pdo,
            $memberCardId,
            $customerId,
            'appointment_rollback',
            $consumeSessions,
            $beforeSessions,
            $afterSessions,
            $operatorUserId,
            $note,
            $now
        );

        $updateConsumeStmt = $pdo->prepare(
            'UPDATE qiling_appointment_consumes
             SET rolled_back_at = :rolled_back_at,
                 rollback_operator_user_id = :rollback_operator_user_id,
                 rollback_note = :rollback_note,
                 rollback_before_sessions = :rollback_before_sessions,
                 rollback_after_sessions = :rollback_after_sessions
             WHERE id = :id'
        );
        $updateConsumeStmt->execute([
            'rolled_back_at' => $now,
            'rollback_operator_user_id' => $operatorUserId,
            'rollback_note' => $note,
            'rollback_before_sessions' => $beforeSessions,
            'rollback_after_sessions' => $afterSessions,
            'id' => (int) $consume['id'],
        ]);

        return [
            'appointment_consume_id' => (int) $consume['id'],
            'member_card_id' => $memberCardId,
            'card_no' => $card['card_no'],
            'rollback_sessions' => $consumeSessions,
            'before_sessions' => $beforeSessions,
            'after_sessions' => $afterSessions,
            'card_status' => $cardStatus,
        ];
    }

    private static function staffConflict(int $staffId, string $startAt, string $endAt): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id
             FROM qiling_appointments
             WHERE staff_id = :staff_id
               AND status = :booked_status
               AND NOT (end_at <= :start_at OR start_at >= :end_at)
             LIMIT 1'
        );

        $stmt->execute([
            'staff_id' => $staffId,
            'booked_status' => 'booked',
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

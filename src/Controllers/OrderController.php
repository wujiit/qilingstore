<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\Auth;
use Qiling\Core\CommissionService;
use Qiling\Core\DataScope;
use Qiling\Core\Database;
use Qiling\Core\OpenGiftService;
use Qiling\Core\PointsService;
use Qiling\Core\PrintService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class OrderController
{
    public static function index(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $storeId = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int) $_GET['store_id'] : null;
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $storeId);
        $customerId = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
        $status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $limit = max(1, min($limit, 1000));

        $sql = 'SELECT o.*, c.customer_no, c.name AS customer_name, c.mobile AS customer_mobile, s.store_name
                FROM qiling_orders o
                INNER JOIN qiling_customers c ON c.id = o.customer_id
                LEFT JOIN qiling_stores s ON s.id = o.store_id
                WHERE 1 = 1';
        $params = [];

        if ($scopeStoreId !== null) {
            $sql .= ' AND o.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }

        if ($customerId !== null) {
            $sql .= ' AND o.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        if ($status !== '') {
            $sql .= ' AND o.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY o.id DESC LIMIT ' . $limit;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::json(['data' => $rows]);
    }

    public static function items(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $orderId = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($orderId <= 0) {
            Response::json(['message' => 'order_id is required'], 422);
            return;
        }

        self::assertOrderAccess($user, $orderId);

        $stmt = Database::pdo()->prepare(
            'SELECT oi.*, st.staff_no, u.username AS staff_username
             FROM qiling_order_items oi
             LEFT JOIN qiling_staff st ON st.id = oi.staff_id
             LEFT JOIN qiling_users u ON u.id = st.user_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public static function payments(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());

        $orderId = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($orderId <= 0) {
            Response::json(['message' => 'order_id is required'], 422);
            return;
        }

        self::assertOrderAccess($user, $orderId);

        $stmt = Database::pdo()->prepare(
            'SELECT p.*
             FROM qiling_order_payments p
             WHERE p.order_id = :order_id
             ORDER BY p.id DESC'
        );
        $stmt->execute(['order_id' => $orderId]);

        Response::json(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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

        $itemsPayload = $data['items'] ?? [];
        if (!is_array($itemsPayload) || empty($itemsPayload)) {
            Response::json(['message' => 'items are required'], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $customer = self::lockCustomer($pdo, $customerId);
            if (!is_array($customer)) {
                $pdo->rollBack();
                Response::json(['message' => 'customer not found'], 404);
                return;
            }

            $customerStoreId = (int) ($customer['store_id'] ?? 0);
            DataScope::assertStoreAccess($user, $customerStoreId);
            $storeIdInput = Request::int($data, 'store_id', $customerStoreId);
            $storeId = DataScope::resolveInputStoreId($user, $storeIdInput);
            if ($customerStoreId > 0 && $storeId !== $customerStoreId) {
                $pdo->rollBack();
                Response::json(['message' => 'customer store mismatch with order store'], 422);
                return;
            }

            $appointmentId = Request::int($data, 'appointment_id', 0);
            if ($appointmentId > 0) {
                $existsAppointment = $pdo->prepare('SELECT id, store_id FROM qiling_appointments WHERE id = :id LIMIT 1');
                $existsAppointment->execute(['id' => $appointmentId]);
                $appointment = $existsAppointment->fetch(PDO::FETCH_ASSOC);
                if (!is_array($appointment)) {
                    $pdo->rollBack();
                    Response::json(['message' => 'appointment not found'], 404);
                    return;
                }
                if ((int) ($appointment['store_id'] ?? 0) !== $storeId) {
                    $pdo->rollBack();
                    Response::json(['message' => 'appointment store mismatch with order store'], 422);
                    return;
                }
            }

            $normalizedItems = [];
            $subtotalAmount = 0.0;
            $itemsDiscountAmount = 0.0;

            foreach ($itemsPayload as $itemPayload) {
                if (!is_array($itemPayload)) {
                    continue;
                }

                $item = self::normalizeItem($pdo, $itemPayload, $storeId, $customerId, $user);
                $normalizedItems[] = $item;
                $subtotalAmount += (float) $item['line_amount'];
                $itemsDiscountAmount += (float) $item['discount_amount'];
            }

            if (empty($normalizedItems)) {
                $pdo->rollBack();
                Response::json(['message' => 'valid items are required'], 422);
                return;
            }

            $orderDiscountAmount = max(0.0, (float) ($data['order_discount_amount'] ?? 0));
            $couponAmount = max(0.0, (float) ($data['coupon_amount'] ?? 0));
            $discountAmount = round($itemsDiscountAmount + $orderDiscountAmount, 2);
            $payableAmount = round(max(0.0, $subtotalAmount - $discountAmount - $couponAmount), 2);
            $now = gmdate('Y-m-d H:i:s');
            $orderNo = 'QLO' . gmdate('ymd') . random_int(1000, 9999);

            $insertOrder = $pdo->prepare(
                'INSERT INTO qiling_orders
                 (order_no, store_id, customer_id, appointment_id, status, subtotal_amount, discount_amount, coupon_amount, payable_amount, paid_amount, paid_at, note, created_by, created_at, updated_at)
                 VALUES
                 (:order_no, :store_id, :customer_id, :appointment_id, :status, :subtotal_amount, :discount_amount, :coupon_amount, :payable_amount, :paid_amount, :paid_at, :note, :created_by, :created_at, :updated_at)'
            );
            $insertOrder->execute([
                'order_no' => $orderNo,
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                'status' => $payableAmount > 0 ? 'unpaid' : 'paid',
                'subtotal_amount' => round($subtotalAmount, 2),
                'discount_amount' => $discountAmount,
                'coupon_amount' => round($couponAmount, 2),
                'payable_amount' => $payableAmount,
                'paid_amount' => $payableAmount > 0 ? 0.00 : $payableAmount,
                'paid_at' => $payableAmount > 0 ? null : $now,
                'note' => Request::str($data, 'note'),
                'created_by' => (int) $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO qiling_order_items
                 (order_id, store_id, customer_id, item_type, item_ref_id, item_name, qty, unit_price, line_amount, discount_amount, final_amount, staff_id, commission_rate, commission_amount, note, created_at)
                 VALUES
                 (:order_id, :store_id, :customer_id, :item_type, :item_ref_id, :item_name, :qty, :unit_price, :line_amount, :discount_amount, :final_amount, :staff_id, :commission_rate, :commission_amount, :note, :created_at)'
            );

            foreach ($normalizedItems as $item) {
                $insertItem->execute([
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'customer_id' => $customerId,
                    'item_type' => $item['item_type'],
                    'item_ref_id' => $item['item_ref_id'],
                    'item_name' => $item['item_name'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'line_amount' => $item['line_amount'],
                    'discount_amount' => $item['discount_amount'],
                    'final_amount' => $item['final_amount'],
                    'staff_id' => $item['staff_id'],
                    'commission_rate' => $item['commission_rate'],
                    'commission_amount' => $item['commission_amount'],
                    'note' => $item['note'],
                    'created_at' => $now,
                ]);
            }

            if ($payableAmount <= 0) {
                $customerUpdate = $pdo->prepare(
                    'UPDATE qiling_customers
                     SET total_spent = total_spent + :total_spent,
                         visit_count = visit_count + 1,
                         last_visit_at = :last_visit_at,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $customerUpdate->execute([
                    'total_spent' => 0.00,
                    'last_visit_at' => $now,
                    'updated_at' => $now,
                    'id' => $customerId,
                ]);
            }

            Audit::log((int) $user['id'], 'order.create', 'order', $orderId, 'Create order', [
                'order_no' => $orderNo,
                'items' => count($normalizedItems),
                'payable_amount' => $payableAmount,
            ]);

            $pdo->commit();
            Response::json([
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'status' => $payableAmount > 0 ? 'unpaid' : 'paid',
                'subtotal_amount' => round($subtotalAmount, 2),
                'discount_amount' => $discountAmount,
                'coupon_amount' => round($couponAmount, 2),
                'payable_amount' => $payableAmount,
            ], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('create order failed', $e);
        }
    }

    public static function pay(): void
    {
        $user = Auth::requireUser(Auth::userFromBearerToken());
        $data = Request::jsonBody();

        $orderId = Request::int($data, 'order_id', 0);
        if ($orderId <= 0) {
            Response::json(['message' => 'order_id is required'], 422);
            return;
        }

        $payMethod = Request::str($data, 'pay_method', 'cash');
        if (!in_array($payMethod, ['cash', 'wechat', 'alipay', 'card', 'bank', 'other'], true)) {
            $payMethod = 'other';
        }

        $amountInput = (float) ($data['amount'] ?? 0);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pointsResult = null;
            $openGiftResult = null;
            $printJob = null;
            $sideEffectWarnings = [];

            $stmt = $pdo->prepare(
                'SELECT id, order_no, customer_id, store_id, status, payable_amount, paid_amount
                 FROM qiling_orders
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($order)) {
                $pdo->rollBack();
                Response::json(['message' => 'order not found'], 404);
                return;
            }
            DataScope::assertStoreAccess($user, (int) ($order['store_id'] ?? 0));

            if (in_array((string) $order['status'], ['cancelled', 'refunded'], true)) {
                throw new \RuntimeException('order status does not allow payment');
            }

            $payableAmount = round((float) $order['payable_amount'], 2);
            $paidAmountBefore = round((float) $order['paid_amount'], 2);
            $outstanding = round(max(0.0, $payableAmount - $paidAmountBefore), 2);
            if ($outstanding <= 0.0) {
                throw new \RuntimeException('order already fully paid');
            }

            $amount = $amountInput > 0 ? round($amountInput, 2) : $outstanding;
            if ($amount <= 0) {
                throw new \RuntimeException('payment amount must be positive');
            }
            if ($amount > $outstanding) {
                throw new \RuntimeException('payment amount exceeds outstanding amount');
            }

            $now = gmdate('Y-m-d H:i:s');
            $paymentNo = 'QLPM' . gmdate('ymd') . random_int(1000, 9999);

            $insertPayment = $pdo->prepare(
                'INSERT INTO qiling_order_payments
                 (order_id, payment_no, pay_method, amount, status, paid_at, operator_user_id, note, created_at)
                 VALUES
                 (:order_id, :payment_no, :pay_method, :amount, :status, :paid_at, :operator_user_id, :note, :created_at)'
            );
            $insertPayment->execute([
                'order_id' => $orderId,
                'payment_no' => $paymentNo,
                'pay_method' => $payMethod,
                'amount' => $amount,
                'status' => 'paid',
                'paid_at' => $now,
                'operator_user_id' => (int) $user['id'],
                'note' => Request::str($data, 'note'),
                'created_at' => $now,
            ]);

            $paidAmountAfter = round($paidAmountBefore + $amount, 2);
            $newStatus = $paidAmountAfter >= $payableAmount ? 'paid' : 'partially_paid';

            $updateOrder = $pdo->prepare(
                'UPDATE qiling_orders
                 SET status = :status,
                     paid_amount = :paid_amount,
                     paid_at = :paid_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateOrder->execute([
                'status' => $newStatus,
                'paid_amount' => $paidAmountAfter,
                'paid_at' => $newStatus === 'paid' ? $now : null,
                'updated_at' => $now,
                'id' => $orderId,
            ]);

            if ((string) $order['status'] !== 'paid' && $newStatus === 'paid') {
                $updateCustomer = $pdo->prepare(
                    'UPDATE qiling_customers
                     SET total_spent = total_spent + :total_spent,
                         visit_count = visit_count + 1,
                         last_visit_at = :last_visit_at,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateCustomer->execute([
                    'total_spent' => $payableAmount,
                    'last_visit_at' => $now,
                    'updated_at' => $now,
                    'id' => (int) $order['customer_id'],
                ]);

                $pointsToGive = (int) floor($payableAmount);
                if ($pointsToGive > 0) {
                    try {
                        $pointsResult = PointsService::change(
                            $pdo,
                            (int) $user['id'],
                            (int) $order['customer_id'],
                            (int) $order['store_id'],
                            $pointsToGive,
                            'order_paid',
                            '订单支付赠送积分',
                            'order',
                            $orderId
                        );
                    } catch (\Throwable $t) {
                        $sideEffectWarnings[] = 'points_award_failed: ' . $t->getMessage();
                    }
                }

                try {
                    $openGiftResult = OpenGiftService::trigger(
                        $pdo,
                        'first_paid',
                        (int) $user['id'],
                        (int) $order['customer_id'],
                        (int) $order['store_id'],
                        'order',
                        $orderId
                    );
                } catch (\Throwable $t) {
                    $sideEffectWarnings[] = 'open_gift_failed: ' . $t->getMessage();
                }

                try {
                    $printJob = PrintService::createOrderReceiptJob(
                        $pdo,
                        $orderId,
                        (int) $order['store_id'],
                        (int) $user['id']
                    );
                    if (!is_array($printJob)) {
                        $sideEffectWarnings[] = 'print_job_skipped: no available printer';
                    }
                } catch (\Throwable $t) {
                    $sideEffectWarnings[] = 'print_job_failed: ' . $t->getMessage();
                }
            }

            Audit::log((int) $user['id'], 'order.pay', 'order', $orderId, 'Order payment', [
                'payment_no' => $paymentNo,
                'pay_method' => $payMethod,
                'amount' => $amount,
                'status' => $newStatus,
                'points_awarded' => is_array($pointsResult) ? (int) ($pointsResult['delta_points'] ?? 0) : 0,
                'open_gift_triggered' => is_array($openGiftResult) && (($openGiftResult['triggered'] ?? false) === true) ? 1 : 0,
                'print_job_id' => is_array($printJob) ? (int) ($printJob['print_job_id'] ?? 0) : 0,
            ]);

            $pdo->commit();
            Response::json([
                'order_id' => $orderId,
                'order_no' => $order['order_no'],
                'payment_no' => $paymentNo,
                'status' => $newStatus,
                'paid_amount' => $paidAmountAfter,
                'outstanding_amount' => round(max(0.0, $payableAmount - $paidAmountAfter), 2),
                'points' => $pointsResult,
                'open_gift' => $openGiftResult,
                'print_job' => $printJob,
                'warnings' => $sideEffectWarnings,
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('order payment failed', $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockCustomer(PDO $pdo, int $customerId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, store_id, name FROM qiling_customers WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $itemPayload
     * @return array<string, mixed>
     */
    private static function normalizeItem(PDO $pdo, array $itemPayload, int $storeId, int $customerId, array $user): array
    {
        $itemType = trim(strtolower((string) ($itemPayload['item_type'] ?? 'custom')));
        if (!in_array($itemType, ['service', 'package', 'custom'], true)) {
            $itemType = 'custom';
        }

        $itemRefId = isset($itemPayload['item_ref_id']) && is_numeric($itemPayload['item_ref_id'])
            ? (int) $itemPayload['item_ref_id']
            : null;
        if ($itemRefId !== null && $itemRefId <= 0) {
            $itemRefId = null;
        }

        $qty = isset($itemPayload['qty']) && is_numeric($itemPayload['qty']) ? (int) $itemPayload['qty'] : 1;
        if ($qty <= 0) {
            throw new \RuntimeException('item qty must be positive');
        }

        $itemSnapshot = self::resolveItemSnapshot($pdo, $itemType, $itemRefId, $storeId);
        $defaultUnitPrice = (float) ($itemSnapshot['unit_price'] ?? 0);

        $unitPriceRaw = $itemPayload['unit_price'] ?? null;
        $unitPrice = is_numeric($unitPriceRaw)
            ? round(max(0.0, (float) $unitPriceRaw), 2)
            : round(max(0.0, $defaultUnitPrice), 2);
        $lineAmount = round($qty * $unitPrice, 2);
        $discountAmount = round(max(0.0, (float) ($itemPayload['discount_amount'] ?? 0)), 2);
        if ($discountAmount > $lineAmount) {
            $discountAmount = $lineAmount;
        }
        $finalAmount = round(max(0.0, $lineAmount - $discountAmount), 2);

        $itemName = trim((string) ($itemPayload['item_name'] ?? ''));
        if ($itemName === '') {
            $itemName = (string) ($itemSnapshot['item_name'] ?? '');
        }
        if ($itemName === '') {
            throw new \RuntimeException('item_name is required');
        }

        $staffId = isset($itemPayload['staff_id']) && is_numeric($itemPayload['staff_id']) ? (int) $itemPayload['staff_id'] : 0;
        $staffRoleKey = '';
        if ($staffId > 0) {
            $staffStmt = $pdo->prepare('SELECT id, role_key, store_id FROM qiling_staff WHERE id = :id LIMIT 1');
            $staffStmt->execute(['id' => $staffId]);
            $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($staff)) {
                throw new \RuntimeException('staff not found for order item');
            }
            $staffStoreId = (int) ($staff['store_id'] ?? 0);
            DataScope::assertStoreAccess($user, $staffStoreId);
            if ($staffStoreId > 0 && $staffStoreId !== $storeId) {
                throw new \RuntimeException('staff store mismatch for order item');
            }
            $staffRoleKey = (string) ($staff['role_key'] ?? '');
        } else {
            $staffId = 0;
        }

        $commissionRate = $staffId > 0
            ? CommissionService::resolveRate($pdo, $storeId, $itemType, $itemRefId, $staffRoleKey)
            : 0.0;
        $commissionAmount = round($finalAmount * $commissionRate / 100, 2);

        return [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'item_type' => $itemType,
            'item_ref_id' => $itemRefId,
            'item_name' => $itemName,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'line_amount' => $lineAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'staff_id' => $staffId > 0 ? $staffId : null,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'note' => trim((string) ($itemPayload['note'] ?? '')),
        ];
    }

    /**
     * @return array{item_name:string,unit_price:float}
     */
    private static function resolveItemSnapshot(PDO $pdo, string $itemType, ?int $itemRefId, int $storeId): array
    {
        if ($itemType === 'service') {
            if ($itemRefId === null || $itemRefId <= 0) {
                throw new \RuntimeException('service item_ref_id is required');
            }

            $stmt = $pdo->prepare('SELECT service_name, list_price, store_id FROM qiling_services WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $itemRefId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('service not found for order item');
            }
            $serviceStoreId = (int) ($row['store_id'] ?? 0);
            if ($serviceStoreId > 0 && $serviceStoreId !== $storeId) {
                throw new \RuntimeException('service store mismatch for order item');
            }

            return [
                'item_name' => (string) ($row['service_name'] ?? ''),
                'unit_price' => round(max(0.0, (float) ($row['list_price'] ?? 0)), 2),
            ];
        }

        if ($itemType === 'package') {
            if ($itemRefId === null || $itemRefId <= 0) {
                throw new \RuntimeException('package item_ref_id is required');
            }

            $stmt = $pdo->prepare('SELECT package_name, sale_price, store_id FROM qiling_service_packages WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $itemRefId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('package not found for order item');
            }
            $packageStoreId = (int) ($row['store_id'] ?? 0);
            if ($packageStoreId > 0 && $packageStoreId !== $storeId) {
                throw new \RuntimeException('package store mismatch for order item');
            }

            return [
                'item_name' => (string) ($row['package_name'] ?? ''),
                'unit_price' => round(max(0.0, (float) ($row['sale_price'] ?? 0)), 2),
            ];
        }

        return [
            'item_name' => '',
            'unit_price' => 0.0,
        ];
    }

    private static function assertOrderAccess(array $user, int $orderId): void
    {
        $stmt = Database::pdo()->prepare('SELECT store_id FROM qiling_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $result = $stmt->fetchColumn();
        if ($result === false) {
            Response::json(['message' => 'order not found'], 404);
            exit;
        }

        $storeId = (int) $result;
        DataScope::assertStoreAccess($user, $storeId);
    }
}

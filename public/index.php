<?php

declare(strict_types=1);

use Qiling\Controllers\AdminRecordController;
use Qiling\Controllers\AdminCustomerController;
use Qiling\Controllers\AuthController;
use Qiling\Controllers\AppointmentController;
use Qiling\Controllers\CommissionController;
use Qiling\Controllers\CouponGroupController;
use Qiling\Controllers\CronController;
use Qiling\Controllers\CustomerController;
use Qiling\Controllers\CustomerPortalController;
use Qiling\Controllers\DashboardController;
use Qiling\Controllers\FollowupController;
use Qiling\Controllers\HealthController;
use Qiling\Controllers\MemberCardController;
use Qiling\Controllers\MobileController;
use Qiling\Controllers\OpenGiftController;
use Qiling\Controllers\OrderController;
use Qiling\Controllers\PaymentController;
use Qiling\Controllers\PointsController;
use Qiling\Controllers\PrintController;
use Qiling\Controllers\PushController;
use Qiling\Controllers\ReportController;
use Qiling\Controllers\ServiceController;
use Qiling\Controllers\SiteController;
use Qiling\Controllers\StaffController;
use Qiling\Controllers\StoreController;
use Qiling\Controllers\SystemSettingsController;
use Qiling\Controllers\TransferController;
use Qiling\Controllers\UserController;
use Qiling\Controllers\WpUserController;
use Qiling\Core\Config;
use Qiling\Core\Router;
use Qiling\Support\Response;

$envPath = dirname(__DIR__) . '/.env';
if (!is_file($envPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'message' => 'System is not installed',
        'install_url' => '/install.php',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-QILING-WP-SECRET, X-QILING-WP-TS, X-QILING-WP-SIGN, X-QILING-CRON-KEY');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    $payload = [
        'message' => 'Server error',
    ];
    $debug = strtolower(trim((string) Config::get('APP_DEBUG', 'false')));
    if (in_array($debug, ['1', 'true', 'yes', 'on'], true)) {
        $payload['error'] = $e->getMessage();
    }
    Response::json($payload, 500);
});

$router = new Router();

$adminEntryPath = SiteController::resolveAdminEntryPathSafe();
$router->add('GET', '/', [SiteController::class, 'home']);

$router->add('GET', '/' . $adminEntryPath, [SiteController::class, 'adminEntry']);
$router->add('GET', '/' . $adminEntryPath . '/index.html', [SiteController::class, 'adminEntry']);
$router->add('GET', '/admin', [SiteController::class, 'adminEntry']);
$router->add('GET', '/admin/index.html', [SiteController::class, 'adminEntry']);
$router->add('GET', '/customer', [SiteController::class, 'customerEntry']);
$router->add('GET', '/customer/index.html', [SiteController::class, 'customerEntry']);
$router->add('GET', '/pay', [SiteController::class, 'paymentEntry']);
$router->add('GET', '/pay/', [SiteController::class, 'paymentEntry']);
$router->add('GET', '/pay/index.html', [SiteController::class, 'paymentEntry']);

$router->add('GET', '/health', [HealthController::class, 'index']);

$router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
$router->add('GET', '/api/v1/auth/me', [AuthController::class, 'me']);
$router->add('GET', '/api/v1/customer-portal/overview', [CustomerPortalController::class, 'overview']);
$router->add('POST', '/api/v1/customer-portal/appointments/create', [CustomerPortalController::class, 'createPortalAppointment']);
$router->add('POST', '/api/v1/customer-portal/payments/create', [CustomerPortalController::class, 'createOnlinePayment']);
$router->add('POST', '/api/v1/customer-portal/payments/sync', [CustomerPortalController::class, 'syncOnlinePayment']);
$router->add('POST', '/api/v1/customer-portal/payments/sync-pending', [CustomerPortalController::class, 'syncPendingPayments']);
$router->add('POST', '/api/v1/customer-portal/token/rotate', [CustomerPortalController::class, 'rotateToken']);
$router->add('GET', '/api/v1/customer-portal/tokens', [CustomerPortalController::class, 'tokens']);
$router->add('POST', '/api/v1/customer-portal/tokens/create', [CustomerPortalController::class, 'createToken']);
$router->add('POST', '/api/v1/customer-portal/tokens/reset', [CustomerPortalController::class, 'resetToken']);
$router->add('POST', '/api/v1/customer-portal/tokens/revoke', [CustomerPortalController::class, 'revokeToken']);
$router->add('GET', '/api/v1/customer-portal/guards', [CustomerPortalController::class, 'guards']);
$router->add('POST', '/api/v1/customer-portal/guards/unlock', [CustomerPortalController::class, 'unlockGuard']);
$router->add('GET', '/api/v1/mobile/menu', [MobileController::class, 'menu']);
$router->add('GET', '/api/v1/dashboard/summary', [DashboardController::class, 'summary']);

$router->add('GET', '/api/v1/cron/followup/generate', [CronController::class, 'followupGenerate']);
$router->add('POST', '/api/v1/cron/followup/generate', [CronController::class, 'followupGenerate']);
$router->add('GET', '/api/v1/cron/followup/notify', [CronController::class, 'followupNotify']);
$router->add('POST', '/api/v1/cron/followup/notify', [CronController::class, 'followupNotify']);
$router->add('GET', '/api/v1/cron/followup/run', [CronController::class, 'followupRun']);
$router->add('POST', '/api/v1/cron/followup/run', [CronController::class, 'followupRun']);

$router->add('GET', '/api/v1/stores', [StoreController::class, 'index']);
$router->add('POST', '/api/v1/stores', [StoreController::class, 'create']);
$router->add('POST', '/api/v1/stores/update', [StoreController::class, 'update']);

$router->add('GET', '/api/v1/staff', [StaffController::class, 'index']);
$router->add('POST', '/api/v1/staff', [StaffController::class, 'create']);
$router->add('POST', '/api/v1/staff/update', [StaffController::class, 'update']);

$router->add('GET', '/api/v1/users', [UserController::class, 'index']);
$router->add('POST', '/api/v1/users/update', [UserController::class, 'update']);
$router->add('POST', '/api/v1/users/status', [UserController::class, 'setStatus']);
$router->add('POST', '/api/v1/users/reset-password', [UserController::class, 'resetPassword']);

$router->add('GET', '/api/v1/customers', [CustomerController::class, 'index']);
$router->add('POST', '/api/v1/customers', [CustomerController::class, 'create']);

$router->add('GET', '/api/v1/services', [ServiceController::class, 'index']);
$router->add('POST', '/api/v1/services', [ServiceController::class, 'create']);
$router->add('GET', '/api/v1/service-categories', [ServiceController::class, 'categoryIndex']);
$router->add('POST', '/api/v1/service-categories', [ServiceController::class, 'createCategory']);
$router->add('POST', '/api/v1/service-categories/update', [ServiceController::class, 'updateCategory']);
$router->add('GET', '/api/v1/service-packages', [ServiceController::class, 'packageIndex']);
$router->add('POST', '/api/v1/service-packages', [ServiceController::class, 'createPackage']);

$router->add('GET', '/api/v1/member-cards', [MemberCardController::class, 'index']);
$router->add('POST', '/api/v1/member-cards', [MemberCardController::class, 'create']);
$router->add('POST', '/api/v1/member-cards/consume', [MemberCardController::class, 'consume']);
$router->add('GET', '/api/v1/member-card-logs', [MemberCardController::class, 'logs']);

$router->add('GET', '/api/v1/orders', [OrderController::class, 'index']);
$router->add('POST', '/api/v1/orders', [OrderController::class, 'create']);
$router->add('POST', '/api/v1/orders/pay', [OrderController::class, 'pay']);
$router->add('GET', '/api/v1/order-items', [OrderController::class, 'items']);
$router->add('GET', '/api/v1/order-payments', [OrderController::class, 'payments']);

$router->add('POST', '/api/v1/payments/online/create', [PaymentController::class, 'createOnline']);
$router->add('POST', '/api/v1/payments/online/create-dual-qr', [PaymentController::class, 'createDualQr']);
$router->add('GET', '/api/v1/payments/online/status', [PaymentController::class, 'status']);
$router->add('GET', '/api/v1/payments/public/status', [PaymentController::class, 'publicStatus']);
$router->add('POST', '/api/v1/payments/public/statuses', [PaymentController::class, 'publicStatuses']);
$router->add('POST', '/api/v1/payments/online/query', [PaymentController::class, 'queryOnline']);
$router->add('POST', '/api/v1/payments/online/close', [PaymentController::class, 'closeOnline']);
$router->add('POST', '/api/v1/payments/online/refund', [PaymentController::class, 'refundOnline']);
$router->add('GET', '/api/v1/payments/online/refunds', [PaymentController::class, 'refunds']);
$router->add('GET', '/api/v1/payments/config', [PaymentController::class, 'config']);
$router->add('POST', '/api/v1/payments/config', [PaymentController::class, 'updateConfig']);
$router->add('POST', '/api/v1/payments/alipay/notify', [PaymentController::class, 'alipayNotify']);
$router->add('POST', '/api/v1/payments/wechat/notify', [PaymentController::class, 'wechatNotify']);

$router->add('GET', '/api/v1/admin/customers/search', [AdminRecordController::class, 'searchCustomers']);
$router->add('POST', '/api/v1/admin/customers/onboard', [AdminCustomerController::class, 'onboard']);
$router->add('POST', '/api/v1/admin/customers/consume-record', [AdminCustomerController::class, 'consumeRecord']);
$router->add('POST', '/api/v1/admin/customers/consume-record-adjust', [AdminCustomerController::class, 'adjustConsumeRecord']);
$router->add('POST', '/api/v1/admin/customers/wallet-adjust', [AdminCustomerController::class, 'adjustWallet']);
$router->add('POST', '/api/v1/admin/customers/coupon-adjust', [AdminCustomerController::class, 'adjustCoupon']);
$router->add('POST', '/api/v1/admin/member-cards/adjust', [AdminRecordController::class, 'adjustMemberCard']);
$router->add('POST', '/api/v1/admin/member-cards/manual-consume', [AdminRecordController::class, 'manualConsume']);
$router->add('POST', '/api/v1/admin/appointment-consumes/adjust', [AdminRecordController::class, 'adjustAppointmentConsume']);

$router->add('GET', '/api/v1/appointments', [AppointmentController::class, 'index']);
$router->add('POST', '/api/v1/appointments', [AppointmentController::class, 'create']);
$router->add('POST', '/api/v1/appointments/status', [AppointmentController::class, 'updateStatus']);

$router->add('GET', '/api/v1/followup/plans', [FollowupController::class, 'plans']);
$router->add('POST', '/api/v1/followup/plans', [FollowupController::class, 'upsertPlan']);
$router->add('GET', '/api/v1/followup/tasks', [FollowupController::class, 'tasks']);
$router->add('POST', '/api/v1/followup/tasks/status', [FollowupController::class, 'updateTaskStatus']);
$router->add('POST', '/api/v1/followup/generate', [FollowupController::class, 'generate']);
$router->add('POST', '/api/v1/followup/notify', [PushController::class, 'notifyFollowupDue']);

$router->add('GET', '/api/v1/push/channels', [PushController::class, 'channels']);
$router->add('POST', '/api/v1/push/channels', [PushController::class, 'upsertChannel']);
$router->add('POST', '/api/v1/push/test', [PushController::class, 'test']);
$router->add('GET', '/api/v1/push/logs', [PushController::class, 'logs']);

$router->add('GET', '/api/v1/commission/rules', [CommissionController::class, 'rules']);
$router->add('POST', '/api/v1/commission/rules', [CommissionController::class, 'upsertRule']);
$router->add('GET', '/api/v1/performance/staff', [CommissionController::class, 'staffPerformance']);

$router->add('GET', '/api/v1/reports/store-daily', [ReportController::class, 'storeDaily']);
$router->add('GET', '/api/v1/reports/customer-repurchase', [ReportController::class, 'customerRepurchase']);
$router->add('GET', '/api/v1/reports/operation-overview', [ReportController::class, 'operationOverview']);
$router->add('GET', '/api/v1/reports/revenue-trend', [ReportController::class, 'revenueTrend']);
$router->add('GET', '/api/v1/reports/channel-stats', [ReportController::class, 'channelStats']);
$router->add('GET', '/api/v1/reports/service-top', [ReportController::class, 'serviceTop']);
$router->add('GET', '/api/v1/reports/payment-methods', [ReportController::class, 'paymentMethods']);

$router->add('GET', '/api/v1/customer-grades', [PointsController::class, 'grades']);
$router->add('POST', '/api/v1/customer-grades', [PointsController::class, 'upsertGrade']);
$router->add('GET', '/api/v1/customer-points/account', [PointsController::class, 'account']);
$router->add('GET', '/api/v1/customer-points/logs', [PointsController::class, 'logs']);
$router->add('POST', '/api/v1/customer-points/change', [PointsController::class, 'change']);

$router->add('GET', '/api/v1/open-gifts', [OpenGiftController::class, 'index']);
$router->add('POST', '/api/v1/open-gifts', [OpenGiftController::class, 'upsert']);
$router->add('POST', '/api/v1/open-gifts/trigger', [OpenGiftController::class, 'trigger']);

$router->add('GET', '/api/v1/coupon-groups', [CouponGroupController::class, 'index']);
$router->add('POST', '/api/v1/coupon-groups', [CouponGroupController::class, 'upsert']);
$router->add('GET', '/api/v1/coupon-group-sends', [CouponGroupController::class, 'sends']);
$router->add('POST', '/api/v1/coupon-groups/send', [CouponGroupController::class, 'send']);

$router->add('GET', '/api/v1/coupon-transfers', [TransferController::class, 'couponTransfers']);
$router->add('POST', '/api/v1/coupons/transfer', [TransferController::class, 'transferCoupon']);
$router->add('GET', '/api/v1/member-card-transfers', [TransferController::class, 'memberCardTransfers']);
$router->add('POST', '/api/v1/member-cards/transfer', [TransferController::class, 'transferMemberCard']);

$router->add('GET', '/api/v1/printers', [PrintController::class, 'printers']);
$router->add('POST', '/api/v1/printers', [PrintController::class, 'upsertPrinter']);
$router->add('GET', '/api/v1/print-jobs', [PrintController::class, 'jobs']);
$router->add('POST', '/api/v1/print-jobs', [PrintController::class, 'createJob']);
$router->add('POST', '/api/v1/print-jobs/dispatch', [PrintController::class, 'dispatch']);

$router->add('POST', '/api/v1/wp/users/sync', [WpUserController::class, 'sync']);
$router->add('GET', '/api/v1/wp/users', [WpUserController::class, 'index']);
$router->add('GET', '/api/v1/system/settings', [SystemSettingsController::class, 'show']);
$router->add('POST', '/api/v1/system/settings', [SystemSettingsController::class, 'update']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$router->dispatch($method, $path);

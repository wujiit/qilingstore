<?php

declare(strict_types=1);

use Qiling\Controllers\AdminRecordController;
use Qiling\Controllers\AdminCustomerController;
use Qiling\Controllers\AuthController;
use Qiling\Controllers\AppointmentController;
use Qiling\Controllers\CommissionController;
use Qiling\Controllers\CouponGroupController;
use Qiling\Controllers\Crm\CrmActivityController;
use Qiling\Controllers\Crm\CrmAutomationController;
use Qiling\Controllers\Crm\CrmBridgeController;
use Qiling\Controllers\Crm\CrmCompanyController;
use Qiling\Controllers\Crm\CrmContactController;
use Qiling\Controllers\Crm\CrmDashboardController;
use Qiling\Controllers\Crm\CrmDealController;
use Qiling\Controllers\Crm\CrmGovernanceController;
use Qiling\Controllers\Crm\CrmLeadController;
use Qiling\Controllers\Crm\CrmMetaController;
use Qiling\Controllers\Crm\CrmOrgController;
use Qiling\Controllers\Crm\CrmPipelineController;
use Qiling\Controllers\Crm\CrmReminderController;
use Qiling\Controllers\Crm\CrmTradeController;
use Qiling\Controllers\CronController;
use Qiling\Controllers\CustomerController;
use Qiling\Controllers\CustomerPortalController;
use Qiling\Controllers\DashboardController;
use Qiling\Controllers\FollowupController;
use Qiling\Controllers\FinanceReconciliationController;
use Qiling\Controllers\HealthController;
use Qiling\Controllers\InventoryController;
use Qiling\Controllers\MemberCardController;
use Qiling\Controllers\MobileController;
use Qiling\Controllers\OpenGiftController;
use Qiling\Controllers\OrderController;
use Qiling\Controllers\PasswordResetController;
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
use Qiling\Controllers\SystemUpgradeController;
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

$corsAllowedOrigins = [];
$corsRaw = trim((string) Config::get('CORS_ALLOWED_ORIGINS', ''));
if ($corsRaw !== '') {
    $parts = preg_split('/[\s,;]+/', $corsRaw);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $origin = trim((string) $part);
            if ($origin !== '' && !in_array($origin, $corsAllowedOrigins, true)) {
                $corsAllowedOrigins[] = $origin;
            }
        }
    }
}
if ($corsAllowedOrigins === []) {
    $appUrl = trim((string) Config::get('APP_URL', ''));
    if ($appUrl !== '') {
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $host = parse_url($appUrl, PHP_URL_HOST);
        $port = parse_url($appUrl, PHP_URL_PORT);
        if (is_string($scheme) && is_string($host) && $scheme !== '' && $host !== '') {
            $appOrigin = strtolower($scheme) . '://' . strtolower($host);
            if (is_int($port) && $port > 0) {
                $appOrigin .= ':' . $port;
            }
            $corsAllowedOrigins[] = $appOrigin;
        }
    }
}

$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$corsAllowAll = in_array('*', $corsAllowedOrigins, true);
$corsAllowed = false;
if ($corsAllowAll) {
    header('Access-Control-Allow-Origin: *');
    $corsAllowed = true;
} elseif ($requestOrigin !== '' && in_array($requestOrigin, $corsAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
    $corsAllowed = true;
} elseif ($requestOrigin !== '') {
    header('Vary: Origin');
}

header('Access-Control-Allow-Headers: Content-Type, Authorization, X-QILING-WP-SECRET, X-QILING-WP-TS, X-QILING-WP-SIGN, X-QILING-CRON-KEY');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 600');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    if ($requestOrigin !== '' && !$corsAllowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'CORS origin forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(204);
    exit;
}

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if (!is_array($last)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($last['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    try {
        $runtimeDir = dirname(__DIR__) . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0775, true);
        }
        $line = '[' . gmdate('c') . '] '
            . 'FATAL[' . (int) ($last['type'] ?? 0) . ']: '
            . (string) ($last['message'] ?? '')
            . ' in ' . (string) ($last['file'] ?? '')
            . ':' . (int) ($last['line'] ?? 0)
            . PHP_EOL . PHP_EOL;
        @file_put_contents($runtimeDir . '/exceptions.log', $line, FILE_APPEND);
    } catch (Throwable) {
        // ignore log write errors
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Server error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

set_exception_handler(static function (Throwable $e): void {
    // Persist a local exception log so panel deployments can diagnose without shell access.
    try {
        $runtimeDir = dirname(__DIR__) . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0775, true);
        }
        $line = '[' . gmdate('c') . '] '
            . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
            . PHP_EOL . $e->getTraceAsString() . PHP_EOL . PHP_EOL;
        @file_put_contents($runtimeDir . '/exceptions.log', $line, FILE_APPEND);
    } catch (Throwable) {
        // ignore log write errors
    }

    $payload = [
        'message' => 'Server error',
    ];
    if ($e instanceof RuntimeException) {
        $runtimeMessage = trim($e->getMessage());
        if ($runtimeMessage !== '' && strpos($runtimeMessage, '数据库结构未升级') !== false) {
            $payload['message'] = $runtimeMessage;
        } elseif ($runtimeMessage !== '' && strpos($runtimeMessage, 'Database connection failed') !== false) {
            $payload['message'] = '数据库连接失败，请检查数据库服务与 .env 的 DB_* 配置';
        }
    }
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
$router->add('GET', '/crm-admin', [SiteController::class, 'crmAdminEntry']);
$router->add('GET', '/crm-admin/index.html', [SiteController::class, 'crmAdminEntry']);
$router->add('GET', '/customer', [SiteController::class, 'customerEntry']);
$router->add('GET', '/customer/index.html', [SiteController::class, 'customerEntry']);
$router->add('GET', '/pay', [SiteController::class, 'paymentEntry']);
$router->add('GET', '/pay/', [SiteController::class, 'paymentEntry']);
$router->add('GET', '/pay/index.html', [SiteController::class, 'paymentEntry']);

$router->add('GET', '/health', [HealthController::class, 'index']);

$router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
$router->add('GET', '/api/v1/auth/me', [AuthController::class, 'me']);
$router->add('POST', '/api/v1/auth/password-reset/request', [PasswordResetController::class, 'request']);
$router->add('POST', '/api/v1/auth/password-reset/confirm', [PasswordResetController::class, 'confirm']);
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
$router->add('GET', '/api/v1/cron/reports/aggregate', [CronController::class, 'reportAggregate']);
$router->add('POST', '/api/v1/cron/reports/aggregate', [CronController::class, 'reportAggregate']);

$router->add('GET', '/api/v1/crm/dashboard', [CrmDashboardController::class, 'dashboard']);
$router->add('GET', '/api/v1/crm/dashboard/funnel', [CrmDashboardController::class, 'funnel']);
$router->add('GET', '/api/v1/crm/dashboard/stage-duration', [CrmDashboardController::class, 'stageDuration']);
$router->add('GET', '/api/v1/crm/dashboard/trends', [CrmDashboardController::class, 'trends']);
$router->add('GET', '/api/v1/crm/pipelines', [CrmPipelineController::class, 'pipelines']);
$router->add('POST', '/api/v1/crm/pipelines', [CrmPipelineController::class, 'upsertPipeline']);
$router->add('GET', '/api/v1/crm/companies', [CrmCompanyController::class, 'companies']);
$router->add('POST', '/api/v1/crm/companies', [CrmCompanyController::class, 'createCompany']);
$router->add('POST', '/api/v1/crm/companies/update', [CrmCompanyController::class, 'updateCompany']);
$router->add('GET', '/api/v1/crm/contacts', [CrmContactController::class, 'contacts']);
$router->add('GET', '/api/v1/crm/contacts/export', [CrmContactController::class, 'exportContacts']);
$router->add('POST', '/api/v1/crm/contacts', [CrmContactController::class, 'createContact']);
$router->add('POST', '/api/v1/crm/contacts/update', [CrmContactController::class, 'updateContact']);
$router->add('GET', '/api/v1/crm/leads', [CrmLeadController::class, 'leads']);
$router->add('GET', '/api/v1/crm/leads/export', [CrmLeadController::class, 'exportLeads']);
$router->add('POST', '/api/v1/crm/leads', [CrmLeadController::class, 'createLead']);
$router->add('POST', '/api/v1/crm/leads/update', [CrmLeadController::class, 'updateLead']);
$router->add('POST', '/api/v1/crm/leads/batch-update', [CrmLeadController::class, 'batchUpdateLeads']);
$router->add('POST', '/api/v1/crm/leads/convert', [CrmLeadController::class, 'convertLead']);
$router->add('GET', '/api/v1/crm/deals', [CrmDealController::class, 'deals']);
$router->add('POST', '/api/v1/crm/deals', [CrmDealController::class, 'createDeal']);
$router->add('POST', '/api/v1/crm/deals/update', [CrmDealController::class, 'updateDeal']);
$router->add('POST', '/api/v1/crm/deals/batch-update', [CrmDealController::class, 'batchUpdateDeals']);
$router->add('GET', '/api/v1/crm/activities', [CrmActivityController::class, 'activities']);
$router->add('POST', '/api/v1/crm/activities', [CrmActivityController::class, 'createActivity']);
$router->add('POST', '/api/v1/crm/activities/status', [CrmActivityController::class, 'updateActivityStatus']);
$router->add('POST', '/api/v1/crm/governance/lifecycle', [CrmGovernanceController::class, 'lifecycle']);
$router->add('GET', '/api/v1/crm/governance/duplicates', [CrmGovernanceController::class, 'duplicates']);
$router->add('POST', '/api/v1/crm/governance/merge', [CrmGovernanceController::class, 'merge']);
$router->add('GET', '/api/v1/crm/transfer-logs', [CrmGovernanceController::class, 'transferLogs']);
$router->add('GET', '/api/v1/crm/assignment-rules', [CrmGovernanceController::class, 'assignmentRules']);
$router->add('POST', '/api/v1/crm/assignment-rules', [CrmGovernanceController::class, 'upsertAssignmentRule']);
$router->add('POST', '/api/v1/crm/assignment-rules/apply', [CrmGovernanceController::class, 'applyAssignmentRule']);
$router->add('GET', '/api/v1/crm/org/context', [CrmOrgController::class, 'context']);
$router->add('GET', '/api/v1/crm/org/departments', [CrmOrgController::class, 'departments']);
$router->add('POST', '/api/v1/crm/org/departments', [CrmOrgController::class, 'upsertDepartment']);
$router->add('GET', '/api/v1/crm/org/teams', [CrmOrgController::class, 'teams']);
$router->add('POST', '/api/v1/crm/org/teams', [CrmOrgController::class, 'upsertTeam']);
$router->add('GET', '/api/v1/crm/org/members', [CrmOrgController::class, 'members']);
$router->add('POST', '/api/v1/crm/org/members', [CrmOrgController::class, 'upsertMember']);
$router->add('GET', '/api/v1/crm/custom-fields', [CrmMetaController::class, 'customFields']);
$router->add('POST', '/api/v1/crm/custom-fields', [CrmMetaController::class, 'upsertCustomField']);
$router->add('POST', '/api/v1/crm/custom-fields/delete', [CrmMetaController::class, 'deleteCustomField']);
$router->add('GET', '/api/v1/crm/form-config', [CrmMetaController::class, 'formConfig']);
$router->add('POST', '/api/v1/crm/form-config', [CrmMetaController::class, 'upsertFormConfig']);
$router->add('GET', '/api/v1/crm/dedupe-rules', [CrmMetaController::class, 'dedupeRules']);
$router->add('POST', '/api/v1/crm/dedupe-rules', [CrmMetaController::class, 'upsertDedupeRule']);
$router->add('GET', '/api/v1/crm/reminders', [CrmReminderController::class, 'notifications']);
$router->add('GET', '/api/v1/crm/reminders/summary', [CrmReminderController::class, 'summary']);
$router->add('POST', '/api/v1/crm/reminders/read', [CrmReminderController::class, 'markRead']);
$router->add('GET', '/api/v1/crm/reminders/push-settings', [CrmReminderController::class, 'pushSettings']);
$router->add('POST', '/api/v1/crm/reminders/push-settings', [CrmReminderController::class, 'savePushSettings']);
$router->add('GET', '/api/v1/crm/reminder-rules', [CrmReminderController::class, 'rules']);
$router->add('POST', '/api/v1/crm/reminder-rules', [CrmReminderController::class, 'upsertRule']);
$router->add('POST', '/api/v1/crm/reminders/run', [CrmReminderController::class, 'run']);
$router->add('GET', '/api/v1/crm/trade/products', [CrmTradeController::class, 'products']);
$router->add('POST', '/api/v1/crm/trade/products', [CrmTradeController::class, 'upsertProduct']);
$router->add('GET', '/api/v1/crm/trade/quotes', [CrmTradeController::class, 'quotes']);
$router->add('POST', '/api/v1/crm/trade/quotes', [CrmTradeController::class, 'upsertQuote']);
$router->add('GET', '/api/v1/crm/trade/contracts', [CrmTradeController::class, 'contracts']);
$router->add('POST', '/api/v1/crm/trade/contracts', [CrmTradeController::class, 'upsertContract']);
$router->add('GET', '/api/v1/crm/trade/payment-plans', [CrmTradeController::class, 'paymentPlans']);
$router->add('POST', '/api/v1/crm/trade/payment-plans', [CrmTradeController::class, 'upsertPaymentPlan']);
$router->add('GET', '/api/v1/crm/trade/invoices', [CrmTradeController::class, 'invoices']);
$router->add('POST', '/api/v1/crm/trade/invoices', [CrmTradeController::class, 'upsertInvoice']);
$router->add('GET', '/api/v1/crm/automation/rules', [CrmAutomationController::class, 'rules']);
$router->add('POST', '/api/v1/crm/automation/rules', [CrmAutomationController::class, 'upsertRule']);
$router->add('GET', '/api/v1/crm/automation/logs', [CrmAutomationController::class, 'logs']);
$router->add('GET', '/api/v1/crm/bridge/links', [CrmBridgeController::class, 'links']);
$router->add('POST', '/api/v1/crm/bridge/links', [CrmBridgeController::class, 'upsertLink']);
$router->add('GET', '/api/v1/crm/bridge/customer-360', [CrmBridgeController::class, 'customer360']);

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

$router->add('GET', '/api/v1/finance/reconciliation/overview', [FinanceReconciliationController::class, 'overview']);
$router->add('POST', '/api/v1/finance/reconciliation/close-day', [FinanceReconciliationController::class, 'closeDay']);
$router->add('POST', '/api/v1/finance/reconciliation/exceptions', [FinanceReconciliationController::class, 'createException']);
$router->add('POST', '/api/v1/finance/reconciliation/exceptions/resolve', [FinanceReconciliationController::class, 'resolveException']);

$router->add('GET', '/api/v1/inventory/dashboard', [InventoryController::class, 'dashboard']);
$router->add('GET', '/api/v1/inventory/materials', [InventoryController::class, 'materials']);
$router->add('POST', '/api/v1/inventory/materials', [InventoryController::class, 'upsertMaterial']);
$router->add('GET', '/api/v1/inventory/service-mappings', [InventoryController::class, 'serviceMappings']);
$router->add('POST', '/api/v1/inventory/service-mappings', [InventoryController::class, 'upsertServiceMapping']);
$router->add('GET', '/api/v1/inventory/purchases', [InventoryController::class, 'purchases']);
$router->add('POST', '/api/v1/inventory/purchases', [InventoryController::class, 'createPurchase']);
$router->add('POST', '/api/v1/inventory/purchases/receive', [InventoryController::class, 'receivePurchase']);
$router->add('POST', '/api/v1/inventory/stock/adjust', [InventoryController::class, 'adjustStock']);
$router->add('GET', '/api/v1/inventory/stock-movements', [InventoryController::class, 'stockMovements']);
$router->add('GET', '/api/v1/inventory/cost-summary', [InventoryController::class, 'costSummary']);

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
$router->add('POST', '/api/v1/system/assets/refresh', [SystemSettingsController::class, 'refreshAssets']);
$router->add('GET', '/api/v1/system/upgrade/status', [SystemUpgradeController::class, 'status']);
$router->add('POST', '/api/v1/system/upgrade/run', [SystemUpgradeController::class, 'run']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$router->dispatch($method, $path);

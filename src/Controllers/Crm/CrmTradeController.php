<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmTradeController
{
    /** @var array<int, string> */
    private const QUOTE_STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled'];

    /** @var array<int, string> */
    private const CONTRACT_STATUSES = ['draft', 'active', 'completed', 'cancelled', 'expired'];

    /** @var array<int, string> */
    private const PAYMENT_PLAN_STATUSES = ['pending', 'partial', 'paid', 'overdue', 'cancelled'];

    /** @var array<int, string> */
    private const INVOICE_STATUSES = ['draft', 'issued', 'sent', 'paid', 'overdue', 'cancelled'];

    /** @var array<int, string> */
    private const PRODUCT_STATUSES = ['active', 'inactive'];

    /** @var array<int, string> */
    private const ALLOWED_EXIST_TABLES = [
        'qiling_crm_products',
        'qiling_crm_quotes',
        'qiling_crm_contracts',
        'qiling_crm_payment_plans',
        'qiling_crm_invoices',
        'qiling_crm_companies',
        'qiling_crm_contacts',
        'qiling_crm_deals',
    ];

    public static function products(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $q = CrmSupport::queryStr('q');
        $status = CrmSupport::queryStr('status');
        $category = CrmSupport::queryStr('category');

        $sql = 'SELECT id, product_code, product_name, category, currency_code, list_price, unit, tax_rate, status, description, created_by, created_at, updated_at
                FROM qiling_crm_products
                WHERE 1 = 1';
        $params = [];

        if ($status !== '') {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        if ($category !== '') {
            $sql .= ' AND category = :category';
            $params['category'] = $category;
        }
        if ($q !== '') {
            $kwFt = CrmSupport::toFulltextBooleanQuery($q);
            if (
                $kwFt !== ''
                && CrmSupport::hasFulltextIndex($pdo, 'qiling_crm_products', 'ft_qiling_crm_products_name')
            ) {
                $sql .= ' AND MATCH (product_name) AGAINST (:kw_ft IN BOOLEAN MODE)';
                $params['kw_ft'] = $kwFt;
            } else {
                $sql .= ' AND (product_name LIKE :kw OR product_code LIKE :kw)';
                $params['kw'] = '%' . $q . '%';
            }
        }
        if ($cursor > 0) {
            $sql .= ' AND id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . $queryLimit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);
        foreach ($rows as &$row) {
            $row['list_price'] = self::moneyValue($row['list_price'] ?? 0);
            $row['tax_rate'] = self::decimalValue($row['tax_rate'] ?? 0, 2);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function upsertProduct(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.edit');

        $data = Request::jsonBody();
        $productId = Request::int($data, 'id', 0);
        $productName = Request::str($data, 'product_name');
        if ($productName === '') {
            Response::json(['message' => 'product_name is required'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $existing = [];
        if ($productId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM qiling_crm_products WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $productId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                Response::json(['message' => 'product not found'], 404);
                return;
            }
        }

        $productCode = strtoupper(self::trimTo(Request::str($data, 'product_code', (string) ($existing['product_code'] ?? '')), 40));
        if ($productCode === '') {
            $productCode = self::generateUniqueNo($pdo, 'qiling_crm_products', 'product_code', 'PRD');
        }

        $payload = [
            'product_code' => $productCode,
            'product_name' => self::trimTo($productName, 150),
            'category' => self::trimTo(Request::str($data, 'category', (string) ($existing['category'] ?? '')), 80),
            'currency_code' => CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', (string) ($existing['currency_code'] ?? 'CNY'))),
            'list_price' => self::moneyValue($data['list_price'] ?? ($existing['list_price'] ?? 0)),
            'unit' => self::trimTo(Request::str($data, 'unit', (string) ($existing['unit'] ?? '项')), 20),
            'tax_rate' => self::decimalValue($data['tax_rate'] ?? ($existing['tax_rate'] ?? 0), 2, 0, 100),
            'status' => CrmSupport::normalizeStatus(
                Request::str($data, 'status', (string) ($existing['status'] ?? 'active')),
                self::PRODUCT_STATUSES,
                'active'
            ),
            'description' => self::trimTo(Request::str($data, 'description', (string) ($existing['description'] ?? '')), 10000),
            'updated_at' => $now,
        ];

        if ($productId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE qiling_crm_products
                 SET product_code = :product_code,
                     product_name = :product_name,
                     category = :category,
                     currency_code = :currency_code,
                     list_price = :list_price,
                     unit = :unit,
                     tax_rate = :tax_rate,
                     status = :status,
                     description = :description,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute(array_merge($payload, ['id' => $productId]));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_products
                 (product_code, product_name, category, currency_code, list_price, unit, tax_rate, status, description, created_by, created_at, updated_at)
                 VALUES
                 (:product_code, :product_name, :category, :currency_code, :list_price, :unit, :tax_rate, :status, :description, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute(array_merge($payload, [
                'created_by' => (int) ($user['id'] ?? 0),
                'created_at' => $now,
            ]));
            $productId = (int) $pdo->lastInsertId();
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.trade.product.upsert', 'crm_product', $productId, 'Upsert crm product', [
            'product_code' => $productCode,
            'status' => $payload['status'],
        ]);

        Response::json([
            'product_id' => $productId,
            'product_code' => $productCode,
        ]);
    }

    public static function quotes(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(30, 200);
        $status = CrmSupport::queryStr('status');
        $dealId = CrmSupport::queryInt('deal_id');
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $q = CrmSupport::queryStr('q');

        $manageAll = CrmService::canManageAll($user);

        $sql = 'SELECT q.*, d.deal_name, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by,
                       cp.company_name, ct.contact_name, ou.username AS owner_username
                FROM qiling_crm_quotes q
                INNER JOIN qiling_crm_deals d ON d.id = q.deal_id
                LEFT JOIN qiling_crm_companies cp ON cp.id = q.company_id
                LEFT JOIN qiling_crm_contacts ct ON ct.id = q.contact_id
                LEFT JOIN qiling_users ou ON ou.id = q.owner_user_id
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND q.owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $requestedOwnerId;
            }
        } else {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0 && $requestedOwnerId !== (int) ($user['id'] ?? 0)) {
                Response::json(['message' => 'forbidden: cross-owner query denied'], 403);
                return;
            }
            CrmSupport::appendVisibilityReadScope($sql, $params, $pdo, $user, 'd');
        }

        if ($status !== '') {
            $sql .= ' AND q.status = :status';
            $params['status'] = $status;
        }
        if ($dealId !== null && $dealId > 0) {
            $sql .= ' AND q.deal_id = :deal_id';
            $params['deal_id'] = $dealId;
        }
        if ($q !== '') {
            $sql .= ' AND (q.quote_no LIKE :kw OR q.remark LIKE :kw OR d.deal_name LIKE :kw OR cp.company_name LIKE :kw)';
            $params['kw'] = '%' . $q . '%';
        }
        if ($cursor > 0) {
            $sql .= ' AND q.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY q.id DESC LIMIT ' . $queryLimit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        $quoteIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
        $itemsMap = self::quoteItemsMap($pdo, $quoteIds);

        foreach ($rows as &$row) {
            $row['subtotal_amount'] = self::moneyValue($row['subtotal_amount'] ?? 0);
            $row['discount_amount'] = self::moneyValue($row['discount_amount'] ?? 0);
            $row['tax_amount'] = self::moneyValue($row['tax_amount'] ?? 0);
            $row['total_amount'] = self::moneyValue($row['total_amount'] ?? 0);
            $row['items'] = $itemsMap[(int) ($row['id'] ?? 0)] ?? [];
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function upsertQuote(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.edit');

        $data = Request::jsonBody();
        $quoteId = Request::int($data, 'id', 0);

        $existing = [];
        if ($quoteId > 0) {
            $existing = self::findQuote($pdo, $quoteId);
            if ($existing === []) {
                Response::json(['message' => 'quote not found'], 404);
                return;
            }
            CrmService::assertWritable(
                $user,
                (int) ($existing['deal_owner_user_id'] ?? 0),
                (int) ($existing['deal_created_by'] ?? 0)
            );
        }

        $dealId = Request::int($data, 'deal_id', (int) ($existing['deal_id'] ?? 0));
        if ($dealId <= 0) {
            Response::json(['message' => 'deal_id is required'], 422);
            return;
        }

        $deal = CrmSupport::findWritableRecord($pdo, 'qiling_crm_deals', $dealId, $user);
        if (!is_array($deal)) {
            return;
        }

        $companyId = Request::int($data, 'company_id', (int) ($existing['company_id'] ?? ($deal['company_id'] ?? 0)));
        $contactId = Request::int($data, 'contact_id', (int) ($existing['contact_id'] ?? ($deal['contact_id'] ?? 0)));

        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
            return;
        }
        if ($contactId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_contacts', $contactId)) {
            Response::json(['message' => 'contact not found'], 404);
            return;
        }

        $ownerUserId = Request::int($data, 'owner_user_id', (int) ($existing['owner_user_id'] ?? ($deal['owner_user_id'] ?? 0)));
        if ($ownerUserId <= 0) {
            $ownerUserId = (int) ($user['id'] ?? 0);
        }
        self::assertActiveUser($pdo, $ownerUserId);

        $quoteNo = self::trimTo(Request::str($data, 'quote_no', (string) ($existing['quote_no'] ?? '')), 40);
        if ($quoteNo === '') {
            $quoteNo = self::generateUniqueNo($pdo, 'qiling_crm_quotes', 'quote_no', 'QT');
        }

        $currencyCode = CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', (string) ($existing['currency_code'] ?? 'CNY')));
        $status = CrmSupport::normalizeStatus(
            Request::str($data, 'status', (string) ($existing['status'] ?? 'draft')),
            self::QUOTE_STATUSES,
            'draft'
        );

        $hasItems = array_key_exists('items', $data);
        $items = $hasItems ? self::sanitizeLineItems($pdo, $data['items'] ?? [], $currencyCode) : [];

        if ($hasItems) {
            $totals = self::calcLineTotals($items);
        } else {
            $totals = [
                'subtotal_amount' => self::moneyValue($existing['subtotal_amount'] ?? 0),
                'discount_amount' => self::moneyValue($existing['discount_amount'] ?? 0),
                'tax_amount' => self::moneyValue($existing['tax_amount'] ?? 0),
                'total_amount' => self::moneyValue($existing['total_amount'] ?? 0),
                'items' => [],
            ];
        }

        $validUntil = array_key_exists('valid_until', $data)
            ? CrmSupport::parseDate(Request::str($data, 'valid_until'))
            : ($existing['valid_until'] ?? null);

        $remark = self::trimTo(Request::str($data, 'remark', (string) ($existing['remark'] ?? '')), 500);
        $now = gmdate('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            if ($quoteId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE qiling_crm_quotes
                     SET quote_no = :quote_no,
                         deal_id = :deal_id,
                         company_id = :company_id,
                         contact_id = :contact_id,
                         owner_user_id = :owner_user_id,
                         currency_code = :currency_code,
                         subtotal_amount = :subtotal_amount,
                         discount_amount = :discount_amount,
                         tax_amount = :tax_amount,
                         total_amount = :total_amount,
                         status = :status,
                         valid_until = :valid_until,
                         remark = :remark,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    'quote_no' => $quoteNo,
                    'deal_id' => $dealId,
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'contact_id' => $contactId > 0 ? $contactId : null,
                    'owner_user_id' => $ownerUserId,
                    'currency_code' => $currencyCode,
                    'subtotal_amount' => $totals['subtotal_amount'],
                    'discount_amount' => $totals['discount_amount'],
                    'tax_amount' => $totals['tax_amount'],
                    'total_amount' => $totals['total_amount'],
                    'status' => $status,
                    'valid_until' => $validUntil,
                    'remark' => $remark,
                    'updated_at' => $now,
                    'id' => $quoteId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO qiling_crm_quotes
                     (quote_no, deal_id, company_id, contact_id, owner_user_id, currency_code, subtotal_amount, discount_amount, tax_amount, total_amount, status, valid_until, remark, created_by, created_at, updated_at)
                     VALUES
                     (:quote_no, :deal_id, :company_id, :contact_id, :owner_user_id, :currency_code, :subtotal_amount, :discount_amount, :tax_amount, :total_amount, :status, :valid_until, :remark, :created_by, :created_at, :updated_at)'
                );
                $stmt->execute([
                    'quote_no' => $quoteNo,
                    'deal_id' => $dealId,
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'contact_id' => $contactId > 0 ? $contactId : null,
                    'owner_user_id' => $ownerUserId,
                    'currency_code' => $currencyCode,
                    'subtotal_amount' => $totals['subtotal_amount'],
                    'discount_amount' => $totals['discount_amount'],
                    'tax_amount' => $totals['tax_amount'],
                    'total_amount' => $totals['total_amount'],
                    'status' => $status,
                    'valid_until' => $validUntil,
                    'remark' => $remark,
                    'created_by' => (int) ($user['id'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $quoteId = (int) $pdo->lastInsertId();
            }

            if ($hasItems) {
                $delete = $pdo->prepare('DELETE FROM qiling_crm_quote_items WHERE quote_id = :quote_id');
                $delete->execute(['quote_id' => $quoteId]);
                self::insertQuoteItems($pdo, $quoteId, $totals['items'], $now);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm quote upsert failed', $e);
            return;
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.trade.quote.upsert', 'crm_quote', $quoteId, 'Upsert crm quote', [
            'deal_id' => $dealId,
            'status' => $status,
            'total_amount' => $totals['total_amount'],
        ]);

        Response::json([
            'quote_id' => $quoteId,
            'quote_no' => $quoteNo,
            'total_amount' => $totals['total_amount'],
            'item_count' => $hasItems ? count($totals['items']) : null,
        ]);
    }

    public static function contracts(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(30, 200);
        $status = CrmSupport::queryStr('status');
        $dealId = CrmSupport::queryInt('deal_id');
        $requestedOwnerId = CrmSupport::queryInt('owner_user_id');
        $q = CrmSupport::queryStr('q');

        $manageAll = CrmService::canManageAll($user);

        $sql = 'SELECT c.*, d.deal_name, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by,
                       q.quote_no, cp.company_name, ct.contact_name, ou.username AS owner_username
                FROM qiling_crm_contracts c
                INNER JOIN qiling_crm_deals d ON d.id = c.deal_id
                LEFT JOIN qiling_crm_quotes q ON q.id = c.quote_id
                LEFT JOIN qiling_crm_companies cp ON cp.id = c.company_id
                LEFT JOIN qiling_crm_contacts ct ON ct.id = c.contact_id
                LEFT JOIN qiling_users ou ON ou.id = c.owner_user_id
                WHERE 1 = 1';
        $params = [];

        if ($manageAll) {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0) {
                $sql .= ' AND c.owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $requestedOwnerId;
            }
        } else {
            if ($requestedOwnerId !== null && $requestedOwnerId > 0 && $requestedOwnerId !== (int) ($user['id'] ?? 0)) {
                Response::json(['message' => 'forbidden: cross-owner query denied'], 403);
                return;
            }
            CrmSupport::appendVisibilityReadScope($sql, $params, $pdo, $user, 'd');
        }

        if ($status !== '') {
            $sql .= ' AND c.status = :status';
            $params['status'] = $status;
        }
        if ($dealId !== null && $dealId > 0) {
            $sql .= ' AND c.deal_id = :deal_id';
            $params['deal_id'] = $dealId;
        }
        if ($q !== '') {
            $sql .= ' AND (c.contract_no LIKE :kw OR c.remark LIKE :kw OR d.deal_name LIKE :kw OR cp.company_name LIKE :kw)';
            $params['kw'] = '%' . $q . '%';
        }
        if ($cursor > 0) {
            $sql .= ' AND c.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY c.id DESC LIMIT ' . $queryLimit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        $contractIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
        $itemsMap = self::contractItemsMap($pdo, $contractIds);

        foreach ($rows as &$row) {
            $row['total_amount'] = self::moneyValue($row['total_amount'] ?? 0);
            $row['items'] = $itemsMap[(int) ($row['id'] ?? 0)] ?? [];
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function upsertContract(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.edit');

        $data = Request::jsonBody();
        $contractId = Request::int($data, 'id', 0);

        $existing = [];
        if ($contractId > 0) {
            $existing = self::findContract($pdo, $contractId);
            if ($existing === []) {
                Response::json(['message' => 'contract not found'], 404);
                return;
            }
            CrmService::assertWritable(
                $user,
                (int) ($existing['deal_owner_user_id'] ?? 0),
                (int) ($existing['deal_created_by'] ?? 0)
            );
        }

        $dealId = Request::int($data, 'deal_id', (int) ($existing['deal_id'] ?? 0));
        if ($dealId <= 0) {
            Response::json(['message' => 'deal_id is required'], 422);
            return;
        }

        $deal = CrmSupport::findWritableRecord($pdo, 'qiling_crm_deals', $dealId, $user);
        if (!is_array($deal)) {
            return;
        }

        $quoteId = Request::int($data, 'quote_id', (int) ($existing['quote_id'] ?? 0));
        if ($quoteId > 0) {
            if (!self::recordExists($pdo, 'qiling_crm_quotes', $quoteId)) {
                Response::json(['message' => 'quote not found'], 404);
                return;
            }
            $quoteStmt = $pdo->prepare('SELECT deal_id FROM qiling_crm_quotes WHERE id = :id LIMIT 1');
            $quoteStmt->execute(['id' => $quoteId]);
            $quoteDealId = (int) $quoteStmt->fetchColumn();
            if ($quoteDealId > 0 && $quoteDealId !== $dealId) {
                Response::json(['message' => 'quote does not belong to deal'], 422);
                return;
            }
        }

        $companyId = Request::int($data, 'company_id', (int) ($existing['company_id'] ?? ($deal['company_id'] ?? 0)));
        $contactId = Request::int($data, 'contact_id', (int) ($existing['contact_id'] ?? ($deal['contact_id'] ?? 0)));

        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
            return;
        }
        if ($contactId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_contacts', $contactId)) {
            Response::json(['message' => 'contact not found'], 404);
            return;
        }

        $ownerUserId = Request::int($data, 'owner_user_id', (int) ($existing['owner_user_id'] ?? ($deal['owner_user_id'] ?? 0)));
        if ($ownerUserId <= 0) {
            $ownerUserId = (int) ($user['id'] ?? 0);
        }
        self::assertActiveUser($pdo, $ownerUserId);

        $contractNo = self::trimTo(Request::str($data, 'contract_no', (string) ($existing['contract_no'] ?? '')), 40);
        if ($contractNo === '') {
            $contractNo = self::generateUniqueNo($pdo, 'qiling_crm_contracts', 'contract_no', 'CT');
        }

        $currencyCode = CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', (string) ($existing['currency_code'] ?? 'CNY')));
        $status = CrmSupport::normalizeStatus(
            Request::str($data, 'status', (string) ($existing['status'] ?? 'draft')),
            self::CONTRACT_STATUSES,
            'draft'
        );

        $hasItems = array_key_exists('items', $data);
        $items = $hasItems ? self::sanitizeLineItems($pdo, $data['items'] ?? [], $currencyCode) : [];
        if ($hasItems) {
            $totals = self::calcLineTotals($items);
            $totalAmount = $totals['total_amount'];
        } else {
            $totalAmount = self::moneyValue($data['total_amount'] ?? ($existing['total_amount'] ?? 0));
            $totals = [
                'items' => [],
                'total_amount' => $totalAmount,
            ];
        }

        $signedAt = array_key_exists('signed_at', $data)
            ? CrmSupport::parseDateTime(Request::str($data, 'signed_at'))
            : ($existing['signed_at'] ?? null);
        $effectiveAt = array_key_exists('effective_at', $data)
            ? CrmSupport::parseDateTime(Request::str($data, 'effective_at'))
            : ($existing['effective_at'] ?? null);
        $expireAt = array_key_exists('expire_at', $data)
            ? CrmSupport::parseDateTime(Request::str($data, 'expire_at'))
            : ($existing['expire_at'] ?? null);

        $remark = self::trimTo(Request::str($data, 'remark', (string) ($existing['remark'] ?? '')), 500);
        $now = gmdate('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            if ($contractId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE qiling_crm_contracts
                     SET contract_no = :contract_no,
                         deal_id = :deal_id,
                         quote_id = :quote_id,
                         company_id = :company_id,
                         contact_id = :contact_id,
                         owner_user_id = :owner_user_id,
                         currency_code = :currency_code,
                         total_amount = :total_amount,
                         signed_at = :signed_at,
                         effective_at = :effective_at,
                         expire_at = :expire_at,
                         status = :status,
                         remark = :remark,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    'contract_no' => $contractNo,
                    'deal_id' => $dealId,
                    'quote_id' => $quoteId > 0 ? $quoteId : null,
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'contact_id' => $contactId > 0 ? $contactId : null,
                    'owner_user_id' => $ownerUserId,
                    'currency_code' => $currencyCode,
                    'total_amount' => $totalAmount,
                    'signed_at' => $signedAt,
                    'effective_at' => $effectiveAt,
                    'expire_at' => $expireAt,
                    'status' => $status,
                    'remark' => $remark,
                    'updated_at' => $now,
                    'id' => $contractId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO qiling_crm_contracts
                     (contract_no, deal_id, quote_id, company_id, contact_id, owner_user_id, currency_code, total_amount, signed_at, effective_at, expire_at, status, remark, created_by, created_at, updated_at)
                     VALUES
                     (:contract_no, :deal_id, :quote_id, :company_id, :contact_id, :owner_user_id, :currency_code, :total_amount, :signed_at, :effective_at, :expire_at, :status, :remark, :created_by, :created_at, :updated_at)'
                );
                $stmt->execute([
                    'contract_no' => $contractNo,
                    'deal_id' => $dealId,
                    'quote_id' => $quoteId > 0 ? $quoteId : null,
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'contact_id' => $contactId > 0 ? $contactId : null,
                    'owner_user_id' => $ownerUserId,
                    'currency_code' => $currencyCode,
                    'total_amount' => $totalAmount,
                    'signed_at' => $signedAt,
                    'effective_at' => $effectiveAt,
                    'expire_at' => $expireAt,
                    'status' => $status,
                    'remark' => $remark,
                    'created_by' => (int) ($user['id'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $contractId = (int) $pdo->lastInsertId();
            }

            if ($hasItems) {
                $delete = $pdo->prepare('DELETE FROM qiling_crm_contract_items WHERE contract_id = :contract_id');
                $delete->execute(['contract_id' => $contractId]);
                self::insertContractItems($pdo, $contractId, $totals['items'], $now);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::serverError('crm contract upsert failed', $e);
            return;
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.trade.contract.upsert', 'crm_contract', $contractId, 'Upsert crm contract', [
            'deal_id' => $dealId,
            'status' => $status,
            'total_amount' => $totalAmount,
        ]);

        Response::json([
            'contract_id' => $contractId,
            'contract_no' => $contractNo,
            'total_amount' => $totalAmount,
            'item_count' => $hasItems ? count($totals['items']) : null,
        ]);
    }

    public static function paymentPlans(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(30, 200);
        $status = CrmSupport::queryStr('status');
        $dealId = CrmSupport::queryInt('deal_id');
        $contractId = CrmSupport::queryInt('contract_id');

        $sql = 'SELECT p.*, c.contract_no, d.deal_name, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by,
                       ou.username AS owner_username
                FROM qiling_crm_payment_plans p
                INNER JOIN qiling_crm_deals d ON d.id = p.deal_id
                LEFT JOIN qiling_crm_contracts c ON c.id = p.contract_id
                LEFT JOIN qiling_users ou ON ou.id = d.owner_user_id
                WHERE 1 = 1';
        $params = [];

        if (!CrmService::canManageAll($user)) {
            CrmSupport::appendVisibilityReadScope($sql, $params, $pdo, $user, 'd');
        }

        if ($status !== '') {
            $sql .= ' AND p.status = :status';
            $params['status'] = $status;
        }
        if ($dealId !== null && $dealId > 0) {
            $sql .= ' AND p.deal_id = :deal_id';
            $params['deal_id'] = $dealId;
        }
        if ($contractId !== null && $contractId > 0) {
            $sql .= ' AND p.contract_id = :contract_id';
            $params['contract_id'] = $contractId;
        }
        if ($cursor > 0) {
            $sql .= ' AND p.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY p.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);
        foreach ($rows as &$row) {
            $row['amount'] = self::moneyValue($row['amount'] ?? 0);
            $row['paid_amount'] = self::moneyValue($row['paid_amount'] ?? 0);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function upsertPaymentPlan(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.edit');

        $data = Request::jsonBody();
        $planId = Request::int($data, 'id', 0);

        $existing = [];
        if ($planId > 0) {
            $existing = self::findPaymentPlan($pdo, $planId);
            if ($existing === []) {
                Response::json(['message' => 'payment plan not found'], 404);
                return;
            }
            CrmService::assertWritable(
                $user,
                (int) ($existing['deal_owner_user_id'] ?? 0),
                (int) ($existing['deal_created_by'] ?? 0)
            );
        }

        $contractId = Request::int($data, 'contract_id', (int) ($existing['contract_id'] ?? 0));
        $dealId = Request::int($data, 'deal_id', (int) ($existing['deal_id'] ?? 0));

        if ($contractId <= 0) {
            Response::json(['message' => 'contract_id is required'], 422);
            return;
        }

        $contract = self::findContractRaw($pdo, $contractId);
        if ($contract === []) {
            Response::json(['message' => 'contract not found'], 404);
            return;
        }
        $contractDealId = (int) ($contract['deal_id'] ?? 0);
        if ($dealId <= 0) {
            $dealId = $contractDealId;
        }
        if ($dealId <= 0) {
            Response::json(['message' => 'deal_id is required'], 422);
            return;
        }
        if ($contractDealId > 0 && $dealId !== $contractDealId) {
            Response::json(['message' => 'contract does not belong to deal'], 422);
            return;
        }

        $deal = CrmSupport::findWritableRecord($pdo, 'qiling_crm_deals', $dealId, $user);
        if (!is_array($deal)) {
            return;
        }

        $milestoneName = self::trimTo(Request::str($data, 'milestone_name', (string) ($existing['milestone_name'] ?? '')), 120);
        if ($milestoneName === '') {
            $milestoneName = '回款里程碑';
        }

        $amount = self::moneyValue($data['amount'] ?? ($existing['amount'] ?? 0));
        $paidAmount = self::moneyValue($data['paid_amount'] ?? ($existing['paid_amount'] ?? 0));
        if ($paidAmount > $amount && $amount > 0) {
            $amount = $paidAmount;
        }

        $status = CrmSupport::normalizeStatus(
            Request::str($data, 'status', (string) ($existing['status'] ?? 'pending')),
            self::PAYMENT_PLAN_STATUSES,
            'pending'
        );

        $paidAt = array_key_exists('paid_at', $data)
            ? CrmSupport::parseDateTime(Request::str($data, 'paid_at'))
            : ($existing['paid_at'] ?? null);
        if ($status === 'paid' && $paidAt === null) {
            $paidAt = gmdate('Y-m-d H:i:s');
            if ($paidAmount <= 0) {
                $paidAmount = $amount;
            }
        }

        $dueDate = array_key_exists('due_date', $data)
            ? CrmSupport::parseDate(Request::str($data, 'due_date'))
            : ($existing['due_date'] ?? null);

        $payload = [
            'contract_id' => $contractId,
            'deal_id' => $dealId,
            'milestone_name' => $milestoneName,
            'due_date' => $dueDate,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'currency_code' => CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', (string) ($existing['currency_code'] ?? 'CNY'))),
            'status' => $status,
            'paid_at' => $paidAt,
            'payment_note' => self::trimTo(Request::str($data, 'payment_note', (string) ($existing['payment_note'] ?? '')), 255),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($planId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE qiling_crm_payment_plans
                 SET contract_id = :contract_id,
                     deal_id = :deal_id,
                     milestone_name = :milestone_name,
                     due_date = :due_date,
                     amount = :amount,
                     paid_amount = :paid_amount,
                     currency_code = :currency_code,
                     status = :status,
                     paid_at = :paid_at,
                     payment_note = :payment_note,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute(array_merge($payload, ['id' => $planId]));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_payment_plans
                 (contract_id, deal_id, milestone_name, due_date, amount, paid_amount, currency_code, status, paid_at, payment_note, created_by, created_at, updated_at)
                 VALUES
                 (:contract_id, :deal_id, :milestone_name, :due_date, :amount, :paid_amount, :currency_code, :status, :paid_at, :payment_note, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute(array_merge($payload, [
                'created_by' => (int) ($user['id'] ?? 0),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]));
            $planId = (int) $pdo->lastInsertId();
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.trade.payment_plan.upsert', 'crm_payment_plan', $planId, 'Upsert crm payment plan', [
            'deal_id' => $dealId,
            'contract_id' => $contractId,
            'status' => $status,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
        ]);

        Response::json([
            'payment_plan_id' => $planId,
            'status' => $status,
        ]);
    }

    public static function invoices(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(30, 200);
        $status = CrmSupport::queryStr('status');
        $dealId = CrmSupport::queryInt('deal_id');
        $contractId = CrmSupport::queryInt('contract_id');

        $sql = 'SELECT i.*, c.contract_no, d.deal_name, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by,
                       cp.company_name, ou.username AS owner_username
                FROM qiling_crm_invoices i
                INNER JOIN qiling_crm_deals d ON d.id = i.deal_id
                LEFT JOIN qiling_crm_contracts c ON c.id = i.contract_id
                LEFT JOIN qiling_crm_companies cp ON cp.id = i.company_id
                LEFT JOIN qiling_users ou ON ou.id = d.owner_user_id
                WHERE 1 = 1';
        $params = [];

        if (!CrmService::canManageAll($user)) {
            CrmSupport::appendVisibilityReadScope($sql, $params, $pdo, $user, 'd');
        }

        if ($status !== '') {
            $sql .= ' AND i.status = :status';
            $params['status'] = $status;
        }
        if ($dealId !== null && $dealId > 0) {
            $sql .= ' AND i.deal_id = :deal_id';
            $params['deal_id'] = $dealId;
        }
        if ($contractId !== null && $contractId > 0) {
            $sql .= ' AND i.contract_id = :contract_id';
            $params['contract_id'] = $contractId;
        }
        if ($cursor > 0) {
            $sql .= ' AND i.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY i.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);
        foreach ($rows as &$row) {
            $row['amount'] = self::moneyValue($row['amount'] ?? 0);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function upsertInvoice(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.trade.edit');

        $data = Request::jsonBody();
        $invoiceId = Request::int($data, 'id', 0);

        $existing = [];
        if ($invoiceId > 0) {
            $existing = self::findInvoice($pdo, $invoiceId);
            if ($existing === []) {
                Response::json(['message' => 'invoice not found'], 404);
                return;
            }
            CrmService::assertWritable(
                $user,
                (int) ($existing['deal_owner_user_id'] ?? 0),
                (int) ($existing['deal_created_by'] ?? 0)
            );
        }

        $contractId = Request::int($data, 'contract_id', (int) ($existing['contract_id'] ?? 0));
        $dealId = Request::int($data, 'deal_id', (int) ($existing['deal_id'] ?? 0));
        $companyId = Request::int($data, 'company_id', (int) ($existing['company_id'] ?? 0));

        if ($contractId <= 0) {
            Response::json(['message' => 'contract_id is required'], 422);
            return;
        }

        $contract = self::findContractRaw($pdo, $contractId);
        if ($contract === []) {
            Response::json(['message' => 'contract not found'], 404);
            return;
        }

        $contractDealId = (int) ($contract['deal_id'] ?? 0);
        if ($dealId <= 0) {
            $dealId = $contractDealId;
        }
        if ($dealId <= 0) {
            Response::json(['message' => 'deal_id is required'], 422);
            return;
        }
        if ($contractDealId > 0 && $dealId !== $contractDealId) {
            Response::json(['message' => 'contract does not belong to deal'], 422);
            return;
        }
        if ($companyId <= 0) {
            $companyId = (int) ($contract['company_id'] ?? 0);
        }

        $deal = CrmSupport::findWritableRecord($pdo, 'qiling_crm_deals', $dealId, $user);
        if (!is_array($deal)) {
            return;
        }

        if ($companyId <= 0) {
            $companyId = (int) ($deal['company_id'] ?? 0);
        }
        if ($companyId > 0 && !CrmSupport::recordExists($pdo, 'qiling_crm_companies', $companyId)) {
            Response::json(['message' => 'company not found'], 404);
            return;
        }

        $invoiceNo = self::trimTo(Request::str($data, 'invoice_no', (string) ($existing['invoice_no'] ?? '')), 60);
        if ($invoiceNo === '') {
            $invoiceNo = self::generateUniqueNo($pdo, 'qiling_crm_invoices', 'invoice_no', 'INV');
        }

        $amount = self::moneyValue($data['amount'] ?? ($existing['amount'] ?? 0));
        if ($amount <= 0) {
            Response::json(['message' => 'amount must be greater than 0'], 422);
            return;
        }

        $status = CrmSupport::normalizeStatus(
            Request::str($data, 'status', (string) ($existing['status'] ?? 'draft')),
            self::INVOICE_STATUSES,
            'draft'
        );

        $payload = [
            'invoice_no' => $invoiceNo,
            'contract_id' => $contractId,
            'deal_id' => $dealId,
            'company_id' => $companyId > 0 ? $companyId : null,
            'amount' => $amount,
            'currency_code' => CrmSupport::normalizeCurrency(Request::str($data, 'currency_code', (string) ($existing['currency_code'] ?? 'CNY'))),
            'issue_date' => array_key_exists('issue_date', $data)
                ? CrmSupport::parseDate(Request::str($data, 'issue_date'))
                : ($existing['issue_date'] ?? null),
            'due_date' => array_key_exists('due_date', $data)
                ? CrmSupport::parseDate(Request::str($data, 'due_date'))
                : ($existing['due_date'] ?? null),
            'status' => $status,
            'receiver_name' => self::trimTo(Request::str($data, 'receiver_name', (string) ($existing['receiver_name'] ?? '')), 120),
            'receiver_tax_no' => self::trimTo(Request::str($data, 'receiver_tax_no', (string) ($existing['receiver_tax_no'] ?? '')), 80),
            'note' => self::trimTo(Request::str($data, 'note', (string) ($existing['note'] ?? '')), 500),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($invoiceId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE qiling_crm_invoices
                 SET invoice_no = :invoice_no,
                     contract_id = :contract_id,
                     deal_id = :deal_id,
                     company_id = :company_id,
                     amount = :amount,
                     currency_code = :currency_code,
                     issue_date = :issue_date,
                     due_date = :due_date,
                     status = :status,
                     receiver_name = :receiver_name,
                     receiver_tax_no = :receiver_tax_no,
                     note = :note,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute(array_merge($payload, ['id' => $invoiceId]));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO qiling_crm_invoices
                 (invoice_no, contract_id, deal_id, company_id, amount, currency_code, issue_date, due_date, status, receiver_name, receiver_tax_no, note, created_by, created_at, updated_at)
                 VALUES
                 (:invoice_no, :contract_id, :deal_id, :company_id, :amount, :currency_code, :issue_date, :due_date, :status, :receiver_name, :receiver_tax_no, :note, :created_by, :created_at, :updated_at)'
            );
            $stmt->execute(array_merge($payload, [
                'created_by' => (int) ($user['id'] ?? 0),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]));
            $invoiceId = (int) $pdo->lastInsertId();
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.trade.invoice.upsert', 'crm_invoice', $invoiceId, 'Upsert crm invoice', [
            'deal_id' => $dealId,
            'contract_id' => $contractId,
            'status' => $status,
            'amount' => $amount,
        ]);

        Response::json([
            'invoice_id' => $invoiceId,
            'invoice_no' => $invoiceNo,
            'status' => $status,
        ]);
    }

    /**
     * @param array<int,int> $quoteIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    private static function quoteItemsMap(PDO $pdo, array $quoteIds): array
    {
        if ($quoteIds === []) {
            return [];
        }

        $params = [];
        $holders = [];
        foreach ($quoteIds as $idx => $id) {
            $key = 'qid_' . $idx;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $pdo->prepare(
            'SELECT id, quote_id, product_id, item_name, quantity, unit_price, discount_rate, tax_rate, line_amount, remark, created_at, updated_at
             FROM qiling_crm_quote_items
             WHERE quote_id IN (' . implode(',', $holders) . ')
             ORDER BY id ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $qid = (int) ($row['quote_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $row['quantity'] = self::decimalValue($row['quantity'] ?? 0, 2);
            $row['unit_price'] = self::moneyValue($row['unit_price'] ?? 0);
            $row['discount_rate'] = self::decimalValue($row['discount_rate'] ?? 0, 2);
            $row['tax_rate'] = self::decimalValue($row['tax_rate'] ?? 0, 2);
            $row['line_amount'] = self::moneyValue($row['line_amount'] ?? 0);
            if (!isset($map[$qid])) {
                $map[$qid] = [];
            }
            $map[$qid][] = $row;
        }

        return $map;
    }

    /**
     * @param array<int,int> $contractIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    private static function contractItemsMap(PDO $pdo, array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $params = [];
        $holders = [];
        foreach ($contractIds as $idx => $id) {
            $key = 'cid_' . $idx;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $pdo->prepare(
            'SELECT id, contract_id, product_id, item_name, quantity, unit_price, discount_rate, tax_rate, line_amount, remark, created_at, updated_at
             FROM qiling_crm_contract_items
             WHERE contract_id IN (' . implode(',', $holders) . ')
             ORDER BY id ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['contract_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $row['quantity'] = self::decimalValue($row['quantity'] ?? 0, 2);
            $row['unit_price'] = self::moneyValue($row['unit_price'] ?? 0);
            $row['discount_rate'] = self::decimalValue($row['discount_rate'] ?? 0, 2);
            $row['tax_rate'] = self::decimalValue($row['tax_rate'] ?? 0, 2);
            $row['line_amount'] = self::moneyValue($row['line_amount'] ?? 0);
            if (!isset($map[$cid])) {
                $map[$cid] = [];
            }
            $map[$cid][] = $row;
        }

        return $map;
    }

    /**
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    private static function sanitizeLineItems(PDO $pdo, mixed $raw, string $currencyCode): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $productIds = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0;
            if ($pid > 0) {
                $productIds[$pid] = $pid;
            }
        }

        $products = self::productMap($pdo, array_values($productIds));
        $out = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0;
            $product = ($productId > 0 && isset($products[$productId])) ? $products[$productId] : null;

            $itemName = self::trimTo(
                trim((string) ($item['item_name'] ?? ($product['product_name'] ?? ''))),
                160
            );
            if ($itemName === '') {
                continue;
            }

            $quantity = self::decimalValue($item['quantity'] ?? 1, 2, 0.01, 999999999.99);
            $unitPrice = array_key_exists('unit_price', $item)
                ? self::moneyValue($item['unit_price'])
                : self::moneyValue($product['list_price'] ?? 0);
            $discountRate = self::decimalValue($item['discount_rate'] ?? 0, 2, 0, 100);
            $taxRate = array_key_exists('tax_rate', $item)
                ? self::decimalValue($item['tax_rate'], 2, 0, 100)
                : self::decimalValue($product['tax_rate'] ?? 0, 2, 0, 100);

            $lineBase = self::moneyValue($quantity * $unitPrice);
            $discountAmount = self::moneyValue($lineBase * ($discountRate / 100));
            $lineAfterDiscount = self::moneyValue($lineBase - $discountAmount);
            $taxAmount = self::moneyValue($lineAfterDiscount * ($taxRate / 100));
            $lineAmount = self::moneyValue($lineAfterDiscount + $taxAmount);

            $out[] = [
                'product_id' => $productId > 0 ? $productId : null,
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_rate' => $discountRate,
                'tax_rate' => $taxRate,
                'line_amount' => $lineAmount,
                'remark' => self::trimTo((string) ($item['remark'] ?? ''), 255),
                'currency_code' => $currencyCode,
            ];

            if (count($out) >= 200) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function calcLineTotals(array $items): array
    {
        $normalized = [];
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $total = 0.0;

        foreach ($items as $item) {
            $quantity = self::decimalValue($item['quantity'] ?? 0, 2, 0.01, 999999999.99);
            $unitPrice = self::moneyValue($item['unit_price'] ?? 0);
            $discountRate = self::decimalValue($item['discount_rate'] ?? 0, 2, 0, 100);
            $taxRate = self::decimalValue($item['tax_rate'] ?? 0, 2, 0, 100);

            $lineBase = self::moneyValue($quantity * $unitPrice);
            $lineDiscount = self::moneyValue($lineBase * ($discountRate / 100));
            $lineAfterDiscount = self::moneyValue($lineBase - $lineDiscount);
            $lineTax = self::moneyValue($lineAfterDiscount * ($taxRate / 100));
            $lineAmount = self::moneyValue($lineAfterDiscount + $lineTax);

            $subtotal += $lineBase;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $total += $lineAmount;

            $item['quantity'] = $quantity;
            $item['unit_price'] = $unitPrice;
            $item['discount_rate'] = $discountRate;
            $item['tax_rate'] = $taxRate;
            $item['line_amount'] = $lineAmount;
            $normalized[] = $item;
        }

        return [
            'items' => $normalized,
            'subtotal_amount' => self::moneyValue($subtotal),
            'discount_amount' => self::moneyValue($discount),
            'tax_amount' => self::moneyValue($tax),
            'total_amount' => self::moneyValue($total),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function insertQuoteItems(PDO $pdo, int $quoteId, array $items, string $now): void
    {
        if ($quoteId <= 0 || $items === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_quote_items
             (quote_id, product_id, item_name, quantity, unit_price, discount_rate, tax_rate, line_amount, remark, created_at, updated_at)
             VALUES
             (:quote_id, :product_id, :item_name, :quantity, :unit_price, :discount_rate, :tax_rate, :line_amount, :remark, :created_at, :updated_at)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                'quote_id' => $quoteId,
                'product_id' => $item['product_id'] ?? null,
                'item_name' => (string) ($item['item_name'] ?? ''),
                'quantity' => $item['quantity'] ?? 0,
                'unit_price' => $item['unit_price'] ?? 0,
                'discount_rate' => $item['discount_rate'] ?? 0,
                'tax_rate' => $item['tax_rate'] ?? 0,
                'line_amount' => $item['line_amount'] ?? 0,
                'remark' => (string) ($item['remark'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function insertContractItems(PDO $pdo, int $contractId, array $items, string $now): void
    {
        if ($contractId <= 0 || $items === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_contract_items
             (contract_id, product_id, item_name, quantity, unit_price, discount_rate, tax_rate, line_amount, remark, created_at, updated_at)
             VALUES
             (:contract_id, :product_id, :item_name, :quantity, :unit_price, :discount_rate, :tax_rate, :line_amount, :remark, :created_at, :updated_at)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                'contract_id' => $contractId,
                'product_id' => $item['product_id'] ?? null,
                'item_name' => (string) ($item['item_name'] ?? ''),
                'quantity' => $item['quantity'] ?? 0,
                'unit_price' => $item['unit_price'] ?? 0,
                'discount_rate' => $item['discount_rate'] ?? 0,
                'tax_rate' => $item['tax_rate'] ?? 0,
                'line_amount' => $item['line_amount'] ?? 0,
                'remark' => (string) ($item['remark'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>>
     */
    private static function productMap(PDO $pdo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $params = [];
        $holders = [];
        foreach ($ids as $idx => $id) {
            $key = 'pid_' . $idx;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $pdo->prepare(
            'SELECT id, product_name, list_price, tax_rate
             FROM qiling_crm_products
             WHERE id IN (' . implode(',', $holders) . ')'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $row;
            }
        }

        return $map;
    }

    /**
     * @return array<string,mixed>
     */
    private static function findQuote(PDO $pdo, int $quoteId): array
    {
        if ($quoteId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT q.*, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by
             FROM qiling_crm_quotes q
             INNER JOIN qiling_crm_deals d ON d.id = q.deal_id
             WHERE q.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $quoteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function findContract(PDO $pdo, int $contractId): array
    {
        if ($contractId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT c.*, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by
             FROM qiling_crm_contracts c
             INNER JOIN qiling_crm_deals d ON d.id = c.deal_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function findContractRaw(PDO $pdo, int $contractId): array
    {
        if ($contractId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare('SELECT * FROM qiling_crm_contracts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function findPaymentPlan(PDO $pdo, int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT p.*, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by
             FROM qiling_crm_payment_plans p
             INNER JOIN qiling_crm_deals d ON d.id = p.deal_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function findInvoice(PDO $pdo, int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT i.*, d.owner_user_id AS deal_owner_user_id, d.created_by AS deal_created_by
             FROM qiling_crm_invoices i
             INNER JOIN qiling_crm_deals d ON d.id = i.deal_id
             WHERE i.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private static function recordExists(PDO $pdo, string $table, int $id): bool
    {
        if ($id <= 0 || !in_array($table, self::ALLOWED_EXIST_TABLES, true)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function assertActiveUser(PDO $pdo, int $userId): void
    {
        if ($userId <= 0) {
            throw new \RuntimeException('owner_user_id is invalid');
        }
        $stmt = $pdo->prepare('SELECT id FROM qiling_users WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([
            'id' => $userId,
            'status' => 'active',
        ]);
        if ((int) $stmt->fetchColumn() <= 0) {
            throw new \RuntimeException('owner user not found');
        }
    }

    private static function moneyValue(mixed $value): float
    {
        $num = is_numeric($value) ? (float) $value : 0.0;
        if ($num < 0) {
            $num = 0.0;
        }
        return round($num, 2);
    }

    private static function decimalValue(mixed $value, int $scale = 2, float $min = 0.0, float $max = 999999999.99): float
    {
        $num = is_numeric($value) ? (float) $value : 0.0;
        if ($num < $min) {
            $num = $min;
        }
        if ($num > $max) {
            $num = $max;
        }
        return round($num, $scale);
    }

    private static function trimTo(string $value, int $max): string
    {
        $value = trim($value);
        if ($max <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) > $max) {
                return mb_substr($value, 0, $max);
            }
            return $value;
        }

        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }

        return $value;
    }

    private static function generateUniqueNo(PDO $pdo, string $table, string $column, string $prefix): string
    {
        if (!in_array($table, self::ALLOWED_EXIST_TABLES, true)) {
            throw new \RuntimeException('generate number table is invalid');
        }

        for ($i = 0; $i < 20; $i++) {
            $candidate = strtoupper($prefix) . gmdate('ymdHis') . random_int(1000, 9999);
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE ' . $column . ' = :code LIMIT 1');
            $stmt->execute(['code' => $candidate]);
            if ((int) $stmt->fetchColumn() <= 0) {
                return $candidate;
            }
            usleep(1000);
        }

        throw new \RuntimeException('failed to generate unique business number');
    }
}

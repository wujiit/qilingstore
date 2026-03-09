<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Core\DataScope;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmBridgeController
{
    /** @var array<int, string> */
    private const LINK_STATUSES = ['active', 'disabled'];

    /** @var array<int, string> */
    private const MATCH_RULES = ['manual', 'mobile', 'email', 'name'];

    public static function links(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.bridge.view');

        [$limit, $queryLimit, $cursor] = CrmSupport::paginationParams(50, 200);
        $customerId = CrmSupport::queryInt('customer_id');
        $crmContactId = CrmSupport::queryInt('crm_contact_id');
        $crmCompanyId = CrmSupport::queryInt('crm_company_id');
        $requestedStoreId = CrmSupport::queryInt('store_id');
        $scopeStoreId = DataScope::resolveFilterStoreId($user, $requestedStoreId);
        $status = CrmSupport::queryStr('status');
        if (!in_array($status, self::LINK_STATUSES, true)) {
            $status = '';
        }
        $q = CrmSupport::queryStr('q');

        $sql = 'SELECT l.id, l.customer_id, l.crm_contact_id, l.crm_company_id, l.match_rule, l.status, l.note, l.created_by, l.created_at, l.updated_at,
                       c.customer_no, c.store_id, c.name AS customer_name, c.mobile AS customer_mobile,
                       ct.contact_name, ct.mobile AS crm_contact_mobile, ct.email AS crm_contact_email,
                       cp.company_name,
                       u.username AS created_username
                FROM qiling_customer_crm_links l
                INNER JOIN qiling_customers c ON c.id = l.customer_id
                LEFT JOIN qiling_crm_contacts ct ON ct.id = l.crm_contact_id
                LEFT JOIN qiling_crm_companies cp ON cp.id = l.crm_company_id
                LEFT JOIN qiling_users u ON u.id = l.created_by
                WHERE 1 = 1';
        $params = [];

        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND l.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($crmContactId !== null && $crmContactId > 0) {
            $sql .= ' AND l.crm_contact_id = :crm_contact_id';
            $params['crm_contact_id'] = $crmContactId;
        }
        if ($crmCompanyId !== null && $crmCompanyId > 0) {
            $sql .= ' AND l.crm_company_id = :crm_company_id';
            $params['crm_company_id'] = $crmCompanyId;
        }
        if ($status !== '') {
            $sql .= ' AND l.status = :status';
            $params['status'] = $status;
        }
        if ($scopeStoreId !== null) {
            $sql .= ' AND c.store_id = :store_id';
            $params['store_id'] = $scopeStoreId;
        }
        if ($q !== '') {
            $sql .= ' AND (c.name LIKE :kw OR c.mobile LIKE :kw OR c.customer_no LIKE :kw OR ct.contact_name LIKE :kw OR ct.mobile LIKE :kw OR ct.email LIKE :kw OR cp.company_name LIKE :kw)';
            $params['kw'] = '%' . $q . '%';
        }
        if ($cursor > 0) {
            $sql .= ' AND l.id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql .= ' ORDER BY l.id DESC LIMIT ' . $queryLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        [$rows, $pagination] = CrmSupport::sliceRows($rows, $limit, $cursor);

        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['customer_id'] = (int) ($row['customer_id'] ?? 0);
            $row['crm_contact_id'] = (int) ($row['crm_contact_id'] ?? 0);
            $row['crm_company_id'] = (int) ($row['crm_company_id'] ?? 0);
            $row['created_by'] = (int) ($row['created_by'] ?? 0);
            $row['store_id'] = (int) ($row['store_id'] ?? 0);
        }
        unset($row);

        Response::json([
            'data' => $rows,
            'pagination' => $pagination,
        ]);
    }

    public static function upsertLink(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.bridge.edit');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: requires crm.scope.all'], 403);
            return;
        }

        $data = Request::jsonBody();
        $linkId = Request::int($data, 'id', 0);
        $customerId = Request::int($data, 'customer_id', 0);
        $crmContactId = Request::int($data, 'crm_contact_id', 0);
        $crmCompanyId = Request::int($data, 'crm_company_id', 0);

        if ($customerId <= 0) {
            Response::json(['message' => 'customer_id is required'], 422);
            return;
        }
        if ($crmContactId <= 0 && $crmCompanyId <= 0) {
            Response::json(['message' => 'crm_contact_id or crm_company_id is required'], 422);
            return;
        }

        $customer = self::findCustomerById($pdo, $customerId);
        if ($customer === []) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }
        self::assertCustomerStoreScope($user, $customer);

        $contact = [];
        if ($crmContactId > 0) {
            $contact = self::findCrmContactById($pdo, $crmContactId);
            if ($contact === []) {
                Response::json(['message' => 'crm contact not found'], 404);
                return;
            }
            CrmService::assertWritable(
                $user,
                (int) ($contact['owner_user_id'] ?? 0),
                (int) ($contact['created_by'] ?? 0)
            );

            if ($crmCompanyId <= 0) {
                $crmCompanyId = (int) ($contact['company_id'] ?? 0);
            }
        }

        if ($crmCompanyId > 0) {
            $company = self::findCrmCompanyById($pdo, $crmCompanyId);
            if ($company === []) {
                Response::json(['message' => 'crm company not found'], 404);
                return;
            }
            CrmService::assertWritable(
                $user,
                (int) ($company['owner_user_id'] ?? 0),
                (int) ($company['created_by'] ?? 0)
            );

            if ($contact !== []) {
                $contactCompanyId = (int) ($contact['company_id'] ?? 0);
                if ($contactCompanyId > 0 && $contactCompanyId !== $crmCompanyId) {
                    Response::json(['message' => 'crm contact does not belong to crm company'], 422);
                    return;
                }
            }
        }

        $existing = [];
        if ($linkId > 0) {
            $existing = self::findLinkById($pdo, $linkId);
            if ($existing === []) {
                Response::json(['message' => 'link not found'], 404);
                return;
            }

            $existingCustomerId = (int) ($existing['customer_id'] ?? 0);
            if ($existingCustomerId > 0) {
                $existingCustomer = self::findCustomerById($pdo, $existingCustomerId);
                if ($existingCustomer !== []) {
                    self::assertCustomerStoreScope($user, $existingCustomer);
                }
            }

            $existingContactId = (int) ($existing['crm_contact_id'] ?? 0);
            if ($existingContactId > 0) {
                $existingContact = self::findCrmContactById($pdo, $existingContactId);
                if ($existingContact !== []) {
                    CrmService::assertWritable(
                        $user,
                        (int) ($existingContact['owner_user_id'] ?? 0),
                        (int) ($existingContact['created_by'] ?? 0)
                    );
                }
            }

            $existingCompanyId = (int) ($existing['crm_company_id'] ?? 0);
            if ($existingCompanyId > 0) {
                $existingCompany = self::findCrmCompanyById($pdo, $existingCompanyId);
                if ($existingCompany !== []) {
                    CrmService::assertWritable(
                        $user,
                        (int) ($existingCompany['owner_user_id'] ?? 0),
                        (int) ($existingCompany['created_by'] ?? 0)
                    );
                }
            }
        }

        $status = CrmSupport::normalizeStatus(
            Request::str($data, 'status', (string) ($existing['status'] ?? 'active')),
            self::LINK_STATUSES,
            'active'
        );
        $matchRule = CrmSupport::normalizeStatus(
            Request::str($data, 'match_rule', (string) ($existing['match_rule'] ?? 'manual')),
            self::MATCH_RULES,
            'manual'
        );
        $note = self::trimTo(Request::str($data, 'note', (string) ($existing['note'] ?? '')), 255);
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'customer_id' => $customerId,
            'crm_contact_id' => $crmContactId > 0 ? $crmContactId : null,
            'crm_company_id' => $crmCompanyId > 0 ? $crmCompanyId : null,
            'match_rule' => $matchRule,
            'status' => $status,
            'note' => $note,
            'updated_at' => $now,
        ];

        if ($status === 'active' && $crmContactId > 0) {
            $conflict = self::findActiveConflictByContact($pdo, $crmContactId, $linkId);
            if ($conflict !== []) {
                Response::json([
                    'message' => 'crm_contact_id is already linked to another customer',
                    'conflict_link_id' => (int) ($conflict['id'] ?? 0),
                    'conflict_customer_id' => (int) ($conflict['customer_id'] ?? 0),
                ], 409);
                return;
            }
        }

        try {
            if ($linkId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE qiling_customer_crm_links
                     SET customer_id = :customer_id,
                         crm_contact_id = :crm_contact_id,
                         crm_company_id = :crm_company_id,
                         match_rule = :match_rule,
                         status = :status,
                         note = :note,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute(array_merge($payload, ['id' => $linkId]));
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO qiling_customer_crm_links
                     (customer_id, crm_contact_id, crm_company_id, match_rule, status, note, created_by, created_at, updated_at)
                     VALUES
                     (:customer_id, :crm_contact_id, :crm_company_id, :match_rule, :status, :note, :created_by, :created_at, :updated_at)'
                );
                $stmt->execute(array_merge($payload, [
                    'created_by' => (int) ($user['id'] ?? 0),
                    'created_at' => $now,
                ]));
                $linkId = (int) $pdo->lastInsertId();
            }
        } catch (\Throwable $e) {
            if (self::isDuplicateError($e)) {
                Response::json(['message' => 'mapping already exists'], 409);
                return;
            }
            throw $e;
        }

        Audit::log((int) ($user['id'] ?? 0), 'crm.bridge.link.upsert', 'crm_bridge_link', $linkId, 'Upsert customer crm link', [
            'customer_id' => $customerId,
            'crm_contact_id' => $crmContactId,
            'crm_company_id' => $crmCompanyId,
            'status' => $status,
            'match_rule' => $matchRule,
        ]);

        Response::json([
            'link_id' => $linkId,
            'customer_id' => $customerId,
            'crm_contact_id' => $crmContactId,
            'crm_company_id' => $crmCompanyId,
            'status' => $status,
        ]);
    }

    public static function customer360(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.bridge.view');

        $customerId = CrmSupport::queryInt('customer_id') ?? 0;
        $linkId = CrmSupport::queryInt('link_id') ?? 0;
        $crmContactId = CrmSupport::queryInt('crm_contact_id') ?? 0;
        $crmCompanyId = CrmSupport::queryInt('crm_company_id') ?? 0;

        if ($linkId > 0) {
            $link = self::findLinkById($pdo, $linkId);
            if ($link === []) {
                Response::json(['message' => 'link not found'], 404);
                return;
            }
            $linkStatus = (string) ($link['status'] ?? '');
            $linkActive = $linkStatus === 'active';
            if ($customerId <= 0) {
                $customerId = (int) ($link['customer_id'] ?? 0);
            }
            if ($linkActive && $crmContactId <= 0) {
                $crmContactId = (int) ($link['crm_contact_id'] ?? 0);
            }
            if ($linkActive && $crmCompanyId <= 0) {
                $crmCompanyId = (int) ($link['crm_company_id'] ?? 0);
            }
        }

        if ($customerId <= 0 && ($crmContactId > 0 || $crmCompanyId > 0)) {
            $resolved = self::findCustomerIdByCrmEntity($pdo, $crmContactId, $crmCompanyId);
            if (($resolved['ambiguous'] ?? false) === true) {
                Response::json(['message' => 'crm mapping is ambiguous, please specify customer_id or link_id'], 409);
                return;
            }
            $customerId = (int) ($resolved['customer_id'] ?? 0);
        }

        if ($customerId <= 0) {
            Response::json(['message' => 'customer_id or valid link_id is required'], 422);
            return;
        }

        $customer = self::findCustomerById($pdo, $customerId);
        if ($customer === []) {
            Response::json(['message' => 'customer not found'], 404);
            return;
        }
        self::assertCustomerStoreScope($user, $customer);

        $links = self::findLinksByCustomerId($pdo, $customerId);
        $activeLinks = array_values(array_filter($links, static function (array $row): bool {
            return (string) ($row['status'] ?? '') === 'active';
        }));
        $contactIds = self::normalizeIds(array_merge(
            [$crmContactId],
            array_map(static fn (array $row): int => (int) ($row['crm_contact_id'] ?? 0), $activeLinks)
        ));
        $companyIds = self::normalizeIds(array_merge(
            [$crmCompanyId],
            array_map(static fn (array $row): int => (int) ($row['crm_company_id'] ?? 0), $activeLinks)
        ));

        if ($contactIds !== []) {
            $contactCompanyIds = self::findCompanyIdsByContactIds($pdo, $contactIds);
            $companyIds = self::normalizeIds(array_merge($companyIds, $contactCompanyIds));
        }

        $storeSummary = self::storeSummary($pdo, $customerId);
        $storeRecent = self::storeRecent($pdo, $customerId);

        $crmSummary = self::crmSummary($pdo, $contactIds, $companyIds);
        $crmRecent = self::crmRecent($pdo, $contactIds, $companyIds);

        Response::json([
            'customer' => [
                'id' => (int) ($customer['id'] ?? 0),
                'customer_no' => (string) ($customer['customer_no'] ?? ''),
                'store_id' => (int) ($customer['store_id'] ?? 0),
                'name' => (string) ($customer['name'] ?? ''),
                'mobile' => (string) ($customer['mobile'] ?? ''),
                'gender' => (string) ($customer['gender'] ?? ''),
                'source_channel' => (string) ($customer['source_channel'] ?? ''),
                'total_spent' => round((float) ($customer['total_spent'] ?? 0), 2),
                'visit_count' => (int) ($customer['visit_count'] ?? 0),
                'last_visit_at' => $customer['last_visit_at'] ?? null,
                'status' => (string) ($customer['status'] ?? ''),
                'created_at' => (string) ($customer['created_at'] ?? ''),
            ],
            'mapping' => [
                'links' => $links,
                'contact_ids' => $contactIds,
                'company_ids' => $companyIds,
            ],
            'store_summary' => $storeSummary,
            'store_recent' => $storeRecent,
            'crm_summary' => $crmSummary,
            'crm_recent' => $crmRecent,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function findLinkById(PDO $pdo, int $linkId): array
    {
        if ($linkId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM qiling_customer_crm_links WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $linkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function findLinksByCustomerId(PDO $pdo, int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT l.id, l.customer_id, l.crm_contact_id, l.crm_company_id, l.match_rule, l.status, l.note, l.created_by, l.created_at, l.updated_at,
                    ct.contact_name, ct.mobile AS crm_contact_mobile, ct.email AS crm_contact_email,
                    cp.company_name
             FROM qiling_customer_crm_links l
             LEFT JOIN qiling_crm_contacts ct ON ct.id = l.crm_contact_id
             LEFT JOIN qiling_crm_companies cp ON cp.id = l.crm_company_id
             WHERE l.customer_id = :customer_id
             ORDER BY l.id DESC'
        );
        $stmt->execute(['customer_id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['customer_id'] = (int) ($row['customer_id'] ?? 0);
            $row['crm_contact_id'] = (int) ($row['crm_contact_id'] ?? 0);
            $row['crm_company_id'] = (int) ($row['crm_company_id'] ?? 0);
            $row['created_by'] = (int) ($row['created_by'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function findCustomerById(PDO $pdo, int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare('SELECT * FROM qiling_customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function findCrmContactById(PDO $pdo, int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_crm_contacts
             WHERE id = :id
               AND deleted_at IS NULL
               AND is_archived = 0
             LIMIT 1'
        );
        $stmt->execute(['id' => $contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function findCrmCompanyById(PDO $pdo, int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT *
             FROM qiling_crm_companies
             WHERE id = :id
               AND deleted_at IS NULL
               AND is_archived = 0
             LIMIT 1'
        );
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @return array{customer_id:int,ambiguous:bool}
     */
    private static function findCustomerIdByCrmEntity(PDO $pdo, int $crmContactId, int $crmCompanyId): array
    {
        $customerIds = [];

        if ($crmContactId > 0) {
            $stmt = $pdo->prepare(
                'SELECT customer_id
                 FROM qiling_customer_crm_links
                 WHERE crm_contact_id = :crm_contact_id
                   AND status = :status'
            );
            $stmt->execute([
                'crm_contact_id' => $crmContactId,
                'status' => 'active',
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $customerIds[] = (int) $row;
                }
            }
        }

        if ($crmCompanyId > 0) {
            $stmt = $pdo->prepare(
                'SELECT customer_id
                 FROM qiling_customer_crm_links
                 WHERE crm_company_id = :crm_company_id
                   AND status = :status'
            );
            $stmt->execute([
                'crm_company_id' => $crmCompanyId,
                'status' => 'active',
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $customerIds[] = (int) $row;
                }
            }
        }

        $customerIds = self::normalizeIds($customerIds);
        if (count($customerIds) === 1) {
            return [
                'customer_id' => (int) $customerIds[0],
                'ambiguous' => false,
            ];
        }

        if (count($customerIds) > 1) {
            return [
                'customer_id' => 0,
                'ambiguous' => true,
            ];
        }

        return [
            'customer_id' => 0,
            'ambiguous' => false,
        ];
    }

    /**
     * @param array<int, int> $contactIds
     * @return array<int, int>
     */
    private static function findCompanyIdsByContactIds(PDO $pdo, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $params = [];
        $holders = self::holders('cid_', $contactIds, $params);

        $stmt = $pdo->prepare(
            'SELECT company_id
             FROM qiling_crm_contacts
             WHERE id IN (' . $holders . ')
               AND company_id IS NOT NULL
               AND company_id > 0'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        return self::normalizeIds(array_map(static fn (mixed $v): int => (int) $v, $rows));
    }

    /**
     * @return array<string,mixed>
     */
    private static function storeSummary(PDO $pdo, int $customerId): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $orderStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_orders,
                    SUM(CASE WHEN status = \'paid\' THEN 1 ELSE 0 END) AS paid_orders,
                    SUM(CASE WHEN status = \'paid\' THEN paid_amount ELSE 0 END) AS paid_amount,
                    MAX(paid_at) AS last_paid_at
             FROM qiling_orders
             WHERE customer_id = :customer_id'
        );
        $orderStmt->execute(['customer_id' => $customerId]);
        $orderSummary = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($orderSummary)) {
            $orderSummary = [];
        }

        $appointmentStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_appointments,
                    SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed_appointments,
                    SUM(CASE WHEN status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_appointments,
                    MAX(end_at) AS last_appointment_at
             FROM qiling_appointments
             WHERE customer_id = :customer_id'
        );
        $appointmentStmt->execute(['customer_id' => $customerId]);
        $appointmentSummary = $appointmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($appointmentSummary)) {
            $appointmentSummary = [];
        }

        $cardStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_cards,
                    SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active_cards,
                    SUM(CASE WHEN status = \'active\' THEN remaining_sessions ELSE 0 END) AS remaining_sessions
             FROM qiling_member_cards
             WHERE customer_id = :customer_id'
        );
        $cardStmt->execute(['customer_id' => $customerId]);
        $cardSummary = $cardStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($cardSummary)) {
            $cardSummary = [];
        }

        $followupStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_followups,
                    SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_followups,
                    SUM(CASE WHEN status = \'pending\' AND due_at < :now_at THEN 1 ELSE 0 END) AS overdue_followups
             FROM qiling_followup_tasks
             WHERE customer_id = :customer_id'
        );
        $followupStmt->execute([
            'customer_id' => $customerId,
            'now_at' => $now,
        ]);
        $followupSummary = $followupStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($followupSummary)) {
            $followupSummary = [];
        }

        return [
            'total_orders' => (int) ($orderSummary['total_orders'] ?? 0),
            'paid_orders' => (int) ($orderSummary['paid_orders'] ?? 0),
            'paid_amount' => round((float) ($orderSummary['paid_amount'] ?? 0), 2),
            'last_paid_at' => $orderSummary['last_paid_at'] ?? null,
            'total_appointments' => (int) ($appointmentSummary['total_appointments'] ?? 0),
            'completed_appointments' => (int) ($appointmentSummary['completed_appointments'] ?? 0),
            'cancelled_appointments' => (int) ($appointmentSummary['cancelled_appointments'] ?? 0),
            'last_appointment_at' => $appointmentSummary['last_appointment_at'] ?? null,
            'total_cards' => (int) ($cardSummary['total_cards'] ?? 0),
            'active_cards' => (int) ($cardSummary['active_cards'] ?? 0),
            'remaining_sessions' => (int) ($cardSummary['remaining_sessions'] ?? 0),
            'total_followups' => (int) ($followupSummary['total_followups'] ?? 0),
            'pending_followups' => (int) ($followupSummary['pending_followups'] ?? 0),
            'overdue_followups' => (int) ($followupSummary['overdue_followups'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function storeRecent(PDO $pdo, int $customerId): array
    {
        $orderStmt = $pdo->prepare(
            'SELECT id, order_no, status, payable_amount, paid_amount, paid_at, created_at
             FROM qiling_orders
             WHERE customer_id = :customer_id
             ORDER BY id DESC
             LIMIT 10'
        );
        $orderStmt->execute(['customer_id' => $customerId]);
        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($orders)) {
            $orders = [];
        }

        $appointmentStmt = $pdo->prepare(
            'SELECT id, appointment_no, status, start_at, end_at, source_channel, created_at
             FROM qiling_appointments
             WHERE customer_id = :customer_id
             ORDER BY id DESC
             LIMIT 10'
        );
        $appointmentStmt->execute(['customer_id' => $customerId]);
        $appointments = $appointmentStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($appointments)) {
            $appointments = [];
        }

        $followupStmt = $pdo->prepare(
            'SELECT id, appointment_id, status, due_at, title, notify_status, completed_at, created_at
             FROM qiling_followup_tasks
             WHERE customer_id = :customer_id
             ORDER BY id DESC
             LIMIT 10'
        );
        $followupStmt->execute(['customer_id' => $customerId]);
        $followups = $followupStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($followups)) {
            $followups = [];
        }

        return [
            'orders' => $orders,
            'appointments' => $appointments,
            'followups' => $followups,
        ];
    }

    /**
     * @param array<int,int> $contactIds
     * @param array<int,int> $companyIds
     * @return array<string,mixed>
     */
    private static function crmSummary(PDO $pdo, array $contactIds, array $companyIds): array
    {
        $result = [
            'companies_total' => 0,
            'contacts_total' => 0,
            'leads_total' => 0,
            'deals_total' => 0,
            'deals_won' => 0,
            'deals_won_amount' => 0.0,
            'activities_total' => 0,
            'activities_todo' => 0,
            'activities_overdue' => 0,
        ];

        if ($companyIds !== []) {
            $params = [];
            $holders = self::holders('co_', $companyIds, $params);
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM qiling_crm_companies
                 WHERE deleted_at IS NULL
                   AND is_archived = 0
                   AND id IN (' . $holders . ')'
            );
            $stmt->execute($params);
            $result['companies_total'] = (int) $stmt->fetchColumn();
        }

        if ($contactIds !== []) {
            $params = [];
            $holders = self::holders('ct_', $contactIds, $params);
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM qiling_crm_contacts
                 WHERE deleted_at IS NULL
                   AND is_archived = 0
                   AND id IN (' . $holders . ')'
            );
            $stmt->execute($params);
            $result['contacts_total'] = (int) $stmt->fetchColumn();
        }

        $where = self::crmLeadDealWhere($contactIds, $companyIds, 'l');
        if ($where['sql'] !== '') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS total
                 FROM qiling_crm_leads l
                 WHERE l.deleted_at IS NULL
                   AND l.is_archived = 0
                   AND (' . $where['sql'] . ')'
            );
            $stmt->execute($where['params']);
            $result['leads_total'] = (int) $stmt->fetchColumn();
        }

        $dealWhere = self::crmLeadDealWhere($contactIds, $companyIds, 'd');
        if ($dealWhere['sql'] !== '') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN d.deal_status = \'won\' THEN 1 ELSE 0 END) AS won_count,
                        SUM(CASE WHEN d.deal_status = \'won\' THEN d.amount ELSE 0 END) AS won_amount
                 FROM qiling_crm_deals d
                 WHERE ' . $dealWhere['sql']
            );
            $stmt->execute($dealWhere['params']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $row = [];
            }
            $result['deals_total'] = (int) ($row['total'] ?? 0);
            $result['deals_won'] = (int) ($row['won_count'] ?? 0);
            $result['deals_won_amount'] = round((float) ($row['won_amount'] ?? 0), 2);
        }

        $activityWhere = self::crmActivityWhere($contactIds, $companyIds, []);
        if ($activityWhere['sql'] !== '') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = \'todo\' THEN 1 ELSE 0 END) AS todo_count,
                        SUM(CASE WHEN status = \'todo\' AND due_at IS NOT NULL AND due_at < :now_at THEN 1 ELSE 0 END) AS overdue_count
                 FROM qiling_crm_activities
                 WHERE ' . $activityWhere['sql']
            );
            $params = $activityWhere['params'];
            $params['now_at'] = gmdate('Y-m-d H:i:s');
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $row = [];
            }
            $result['activities_total'] = (int) ($row['total'] ?? 0);
            $result['activities_todo'] = (int) ($row['todo_count'] ?? 0);
            $result['activities_overdue'] = (int) ($row['overdue_count'] ?? 0);
        }

        return $result;
    }

    /**
     * @param array<int,int> $contactIds
     * @param array<int,int> $companyIds
     * @return array<string,mixed>
     */
    private static function crmRecent(PDO $pdo, array $contactIds, array $companyIds): array
    {
        $companies = [];
        if ($companyIds !== []) {
            $params = [];
            $holders = self::holders('co_', $companyIds, $params);
            $stmt = $pdo->prepare(
                'SELECT id, company_name, country_code, source_channel, status, owner_user_id, created_at
                 FROM qiling_crm_companies
                 WHERE id IN (' . $holders . ')
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $stmt->execute($params);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($companies)) {
                $companies = [];
            }
        }

        $contacts = [];
        if ($contactIds !== []) {
            $params = [];
            $holders = self::holders('ct_', $contactIds, $params);
            $stmt = $pdo->prepare(
                'SELECT id, company_id, contact_name, mobile, email, source_channel, status, owner_user_id, created_at
                 FROM qiling_crm_contacts
                 WHERE id IN (' . $holders . ')
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $stmt->execute($params);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($contacts)) {
                $contacts = [];
            }
        }

        $deals = [];
        $dealIds = [];
        $dealWhere = self::crmLeadDealWhere($contactIds, $companyIds, 'd');
        if ($dealWhere['sql'] !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, deal_name, company_id, contact_id, deal_status, stage_key, amount, currency_code, expected_close_date, owner_user_id, created_at
                 FROM qiling_crm_deals d
                 WHERE ' . $dealWhere['sql'] . '
                 ORDER BY d.id DESC
                 LIMIT 20'
            );
            $stmt->execute($dealWhere['params']);
            $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($deals)) {
                $deals = [];
            }
            $dealIds = self::normalizeIds(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $deals));
        }

        $activities = [];
        $activityWhere = self::crmActivityWhere($contactIds, $companyIds, $dealIds);
        if ($activityWhere['sql'] !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, entity_type, entity_id, activity_type, subject, due_at, status, owner_user_id, created_at
                 FROM qiling_crm_activities
                 WHERE ' . $activityWhere['sql'] . '
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $stmt->execute($activityWhere['params']);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($activities)) {
                $activities = [];
            }
        }

        $quotes = [];
        $contracts = [];
        $paymentPlans = [];
        $invoices = [];

        if ($dealIds !== []) {
            $params = [];
            $holders = self::holders('d_', $dealIds, $params);

            $quoteStmt = $pdo->prepare(
                'SELECT id, quote_no, deal_id, total_amount, currency_code, status, valid_until, updated_at
                 FROM qiling_crm_quotes
                 WHERE deal_id IN (' . $holders . ')
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $quoteStmt->execute($params);
            $quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($quotes)) {
                $quotes = [];
            }

            $contractStmt = $pdo->prepare(
                'SELECT id, contract_no, deal_id, total_amount, currency_code, status, signed_at, updated_at
                 FROM qiling_crm_contracts
                 WHERE deal_id IN (' . $holders . ')
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $contractStmt->execute($params);
            $contracts = $contractStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($contracts)) {
                $contracts = [];
            }

            $planStmt = $pdo->prepare(
                'SELECT id, contract_id, deal_id, milestone_name, amount, paid_amount, currency_code, status, due_date, paid_at, updated_at
                 FROM qiling_crm_payment_plans
                 WHERE deal_id IN (' . $holders . ')
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $planStmt->execute($params);
            $paymentPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($paymentPlans)) {
                $paymentPlans = [];
            }

            $invoiceStmt = $pdo->prepare(
                'SELECT id, invoice_no, contract_id, deal_id, amount, currency_code, status, issue_date, due_date, updated_at
                 FROM qiling_crm_invoices
                 WHERE deal_id IN (' . $holders . ')
                 ORDER BY id DESC
                 LIMIT 20'
            );
            $invoiceStmt->execute($params);
            $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($invoices)) {
                $invoices = [];
            }
        }

        return [
            'companies' => $companies,
            'contacts' => $contacts,
            'deals' => $deals,
            'activities' => $activities,
            'quotes' => $quotes,
            'contracts' => $contracts,
            'payment_plans' => $paymentPlans,
            'invoices' => $invoices,
        ];
    }

    /**
     * @param array<int,int> $contactIds
     * @param array<int,int> $companyIds
     * @return array{sql:string,params:array<string,mixed>}
     */
    private static function crmLeadDealWhere(array $contactIds, array $companyIds, string $alias): array
    {
        $conditions = [];
        $params = [];

        if ($contactIds !== []) {
            $holders = self::holders('contact_', $contactIds, $params);
            if ($alias === 'l') {
                $conditions[] = $alias . '.related_contact_id IN (' . $holders . ')';
            } else {
                $conditions[] = $alias . '.contact_id IN (' . $holders . ')';
            }
        }

        if ($companyIds !== []) {
            $holders = self::holders('company_', $companyIds, $params);
            if ($alias === 'l') {
                $conditions[] = $alias . '.related_company_id IN (' . $holders . ')';
            } else {
                $conditions[] = $alias . '.company_id IN (' . $holders . ')';
            }
        }

        return [
            'sql' => implode(' OR ', $conditions),
            'params' => $params,
        ];
    }

    /**
     * @param array<int,int> $contactIds
     * @param array<int,int> $companyIds
     * @param array<int,int> $dealIds
     * @return array{sql:string,params:array<string,mixed>}
     */
    private static function crmActivityWhere(array $contactIds, array $companyIds, array $dealIds): array
    {
        $conditions = [];
        $params = [];

        if ($contactIds !== []) {
            $holders = self::holders('a_contact_', $contactIds, $params);
            $conditions[] = '(entity_type = :a_entity_contact AND entity_id IN (' . $holders . '))';
            $params['a_entity_contact'] = 'contact';
        }

        if ($companyIds !== []) {
            $holders = self::holders('a_company_', $companyIds, $params);
            $conditions[] = '(entity_type = :a_entity_company AND entity_id IN (' . $holders . '))';
            $params['a_entity_company'] = 'company';
        }

        if ($dealIds !== []) {
            $holders = self::holders('a_deal_', $dealIds, $params);
            $conditions[] = '(entity_type = :a_entity_deal AND entity_id IN (' . $holders . '))';
            $params['a_entity_deal'] = 'deal';
        }

        return [
            'sql' => implode(' OR ', $conditions),
            'params' => $params,
        ];
    }

    /**
     * @param array<int,int> $ids
     * @param array<string,mixed> $params
     */
    private static function holders(string $prefix, array $ids, array &$params): string
    {
        $holders = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . $index;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }

        if ($holders === []) {
            return 'NULL';
        }
        return implode(',', $holders);
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private static function normalizeIds(array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $num = (int) $id;
            if ($num > 0) {
                $map[$num] = $num;
            }
        }
        return array_values($map);
    }

    /**
     * @return array<string,mixed>
     */
    private static function findActiveConflictByContact(PDO $pdo, int $crmContactId, int $excludeLinkId): array
    {
        if ($crmContactId <= 0) {
            return [];
        }

        $sql = 'SELECT id, customer_id
                FROM qiling_customer_crm_links
                WHERE crm_contact_id = :crm_contact_id
                  AND status = :status';
        $params = [
            'crm_contact_id' => $crmContactId,
            'status' => 'active',
        ];
        if ($excludeLinkId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeLinkId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $customer
     */
    private static function assertCustomerStoreScope(array $user, array $customer): void
    {
        $storeId = (int) ($customer['store_id'] ?? 0);
        DataScope::assertStoreAccess($user, $storeId);
    }

    private static function isDuplicateError(\Throwable $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '23000') {
            return true;
        }
        $message = strtolower(trim($e->getMessage()));
        return str_contains($message, 'duplicate') || str_contains($message, '1062');
    }

    private static function trimTo(string $value, int $max): string
    {
        $value = trim($value);
        if ($max <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max);
        }

        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }
}

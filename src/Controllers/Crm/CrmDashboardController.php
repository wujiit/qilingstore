<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\CrmService;
use Qiling\Support\Response;

final class CrmDashboardController
{
    public static function dashboard(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.dashboard.view');
        $ownerFilter = CrmService::resolveOwnerFilter($user, CrmSupport::queryInt('owner_user_id'));

        $leadSummary = CrmSupport::singleRowSummary(
            $pdo,
            'SELECT
                SUM(CASE WHEN deleted_at IS NULL AND is_archived = 0 THEN 1 ELSE 0 END) AS total,
                SUM(CASE WHEN deleted_at IS NULL AND is_archived = 0 AND status = \'new\' THEN 1 ELSE 0 END) AS new_count,
                SUM(CASE WHEN deleted_at IS NULL AND is_archived = 0 AND status = \'qualified\' THEN 1 ELSE 0 END) AS qualified_count,
                SUM(CASE WHEN deleted_at IS NULL AND is_archived = 0 AND status = \'converted\' THEN 1 ELSE 0 END) AS converted_count
             FROM qiling_crm_leads',
            $ownerFilter
        );

        $dealSummary = CrmSupport::singleRowSummary(
            $pdo,
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN deal_status = \'open\' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN deal_status = \'won\' THEN 1 ELSE 0 END) AS won_count,
                SUM(CASE WHEN deal_status = \'lost\' THEN 1 ELSE 0 END) AS lost_count,
                SUM(CASE WHEN deal_status = \'won\' THEN amount ELSE 0 END) AS won_amount
             FROM qiling_crm_deals',
            $ownerFilter
        );

        $activitySummary = CrmSupport::singleRowSummary(
            $pdo,
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = \'todo\' THEN 1 ELSE 0 END) AS todo_count,
                SUM(CASE WHEN status = \'done\' THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN status = \'todo\' AND due_at IS NOT NULL AND due_at < :now_at THEN 1 ELSE 0 END) AS overdue_count
             FROM qiling_crm_activities',
            $ownerFilter,
            ['now_at' => gmdate('Y-m-d H:i:s')]
        );

        $companySummary = CrmSupport::singleRowSummary(
            $pdo,
            'SELECT SUM(CASE WHEN deleted_at IS NULL AND is_archived = 0 THEN 1 ELSE 0 END) AS total FROM qiling_crm_companies',
            $ownerFilter
        );

        $contactSummary = CrmSupport::singleRowSummary(
            $pdo,
            'SELECT SUM(CASE WHEN deleted_at IS NULL AND is_archived = 0 THEN 1 ELSE 0 END) AS total FROM qiling_crm_contacts',
            $ownerFilter
        );

        Response::json([
            'summary' => [
                'leads_total' => (int) ($leadSummary['total'] ?? 0),
                'leads_new' => (int) ($leadSummary['new_count'] ?? 0),
                'leads_qualified' => (int) ($leadSummary['qualified_count'] ?? 0),
                'leads_converted' => (int) ($leadSummary['converted_count'] ?? 0),
                'companies_total' => (int) ($companySummary['total'] ?? 0),
                'contacts_total' => (int) ($contactSummary['total'] ?? 0),
                'deals_total' => (int) ($dealSummary['total'] ?? 0),
                'deals_open' => (int) ($dealSummary['open_count'] ?? 0),
                'deals_won' => (int) ($dealSummary['won_count'] ?? 0),
                'deals_lost' => (int) ($dealSummary['lost_count'] ?? 0),
                'deals_won_amount' => round((float) ($dealSummary['won_amount'] ?? 0), 2),
                'activities_total' => (int) ($activitySummary['total'] ?? 0),
                'activities_todo' => (int) ($activitySummary['todo_count'] ?? 0),
                'activities_done' => (int) ($activitySummary['done_count'] ?? 0),
                'activities_overdue' => (int) ($activitySummary['overdue_count'] ?? 0),
            ],
        ]);
    }

    public static function funnel(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.analytics.view');

        $ownerFilter = CrmService::resolveOwnerFilter($user, CrmSupport::queryInt('owner_user_id'));
        $pipelineKey = CrmSupport::queryStr('pipeline_key');
        if ($pipelineKey === '') {
            $pipelineKey = 'default';
        }

        $dateFrom = CrmSupport::parseDate(CrmSupport::queryStr('date_from'));
        $dateTo = CrmSupport::parseDate(CrmSupport::queryStr('date_to'));
        [$stages, $stageOrder] = self::pipelineStages($pdo, $pipelineKey);

        $currentSql = 'SELECT stage_key, COUNT(*) AS cnt
                       FROM qiling_crm_deals
                       WHERE pipeline_key = :pipeline_key';
        $currentParams = ['pipeline_key' => $pipelineKey];

        if ($ownerFilter !== null) {
            $currentSql .= ' AND owner_user_id = :owner_user_id';
            $currentParams['owner_user_id'] = $ownerFilter;
        }
        if ($dateFrom !== null) {
            $currentSql .= ' AND created_at >= :date_from';
            $currentParams['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $currentSql .= ' AND created_at <= :date_to';
            $currentParams['date_to'] = $dateTo . ' 23:59:59';
        }

        $currentSql .= ' GROUP BY stage_key';
        $currentStmt = $pdo->prepare($currentSql);
        $currentStmt->execute($currentParams);
        $currentRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($currentRows)) {
            $currentRows = [];
        }

        $transitionSql = 'SELECT l.from_stage_key, l.to_stage_key, COUNT(*) AS cnt
                          FROM qiling_crm_deal_stage_logs l
                          INNER JOIN qiling_crm_deals d ON d.id = l.deal_id
                          WHERE l.pipeline_key = :pipeline_key';
        $transitionParams = ['pipeline_key' => $pipelineKey];

        if ($ownerFilter !== null) {
            $transitionSql .= ' AND d.owner_user_id = :owner_user_id';
            $transitionParams['owner_user_id'] = $ownerFilter;
        }
        if ($dateFrom !== null) {
            $transitionSql .= ' AND l.changed_at >= :date_from';
            $transitionParams['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $transitionSql .= ' AND l.changed_at <= :date_to';
            $transitionParams['date_to'] = $dateTo . ' 23:59:59';
        }

        $transitionSql .= ' GROUP BY l.from_stage_key, l.to_stage_key';
        $transitionStmt = $pdo->prepare($transitionSql);
        $transitionStmt->execute($transitionParams);
        $transitionRows = $transitionStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($transitionRows)) {
            $transitionRows = [];
        }

        $currentMap = [];
        foreach ($currentRows as $row) {
            $key = trim((string) ($row['stage_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!isset($stageOrder[$key])) {
                $stageOrder[$key] = 9999;
                $stages[] = ['key' => $key, 'name' => $key, 'sort' => 9999];
            }
            $currentMap[$key] = (int) ($row['cnt'] ?? 0);
        }

        $enteredMap = [];
        $progressedMap = [];
        foreach ($transitionRows as $row) {
            $fromKey = trim((string) ($row['from_stage_key'] ?? ''));
            $toKey = trim((string) ($row['to_stage_key'] ?? ''));
            $count = (int) ($row['cnt'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            if ($toKey !== '') {
                if (!isset($stageOrder[$toKey])) {
                    $stageOrder[$toKey] = 9999;
                    $stages[] = ['key' => $toKey, 'name' => $toKey, 'sort' => 9999];
                }
                $enteredMap[$toKey] = (int) ($enteredMap[$toKey] ?? 0) + $count;
            }

            if ($fromKey !== '' && $fromKey !== $toKey) {
                if (!isset($stageOrder[$fromKey])) {
                    $stageOrder[$fromKey] = 9999;
                    $stages[] = ['key' => $fromKey, 'name' => $fromKey, 'sort' => 9999];
                }
                $progressedMap[$fromKey] = (int) ($progressedMap[$fromKey] ?? 0) + $count;
            }
        }

        usort($stages, static function (array $a, array $b): int {
            $sa = (int) ($a['sort'] ?? 9999);
            $sb = (int) ($b['sort'] ?? 9999);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });

        $rows = [];
        foreach ($stages as $stage) {
            $key = (string) ($stage['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $current = (int) ($currentMap[$key] ?? 0);
            $entered = (int) ($enteredMap[$key] ?? 0);
            $progressed = (int) ($progressedMap[$key] ?? 0);
            $base = max($entered, $current + $progressed);
            $conversion = $base > 0 ? round(($progressed * 100) / $base, 2) : 0.0;

            $rows[] = [
                'stage_key' => $key,
                'stage_name' => (string) ($stage['name'] ?? $key),
                'current_count' => $current,
                'entered_count' => $entered,
                'progressed_count' => $progressed,
                'conversion_rate' => $conversion,
            ];
        }

        $summarySql = 'SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN deal_status = \'won\' THEN 1 ELSE 0 END) AS won_count,
                        SUM(CASE WHEN deal_status = \'lost\' THEN 1 ELSE 0 END) AS lost_count,
                        SUM(CASE WHEN deal_status = \'won\' THEN amount ELSE 0 END) AS won_amount
                       FROM qiling_crm_deals
                       WHERE pipeline_key = :pipeline_key';
        $summaryParams = ['pipeline_key' => $pipelineKey];

        if ($ownerFilter !== null) {
            $summarySql .= ' AND owner_user_id = :owner_user_id';
            $summaryParams['owner_user_id'] = $ownerFilter;
        }
        if ($dateFrom !== null) {
            $summarySql .= ' AND created_at >= :date_from';
            $summaryParams['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $summarySql .= ' AND created_at <= :date_to';
            $summaryParams['date_to'] = $dateTo . ' 23:59:59';
        }

        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($summaryParams);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($summary)) {
            $summary = [];
        }

        Response::json([
            'pipeline_key' => $pipelineKey,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'deals_total' => (int) ($summary['total'] ?? 0),
                'won_count' => (int) ($summary['won_count'] ?? 0),
                'lost_count' => (int) ($summary['lost_count'] ?? 0),
                'won_amount' => round((float) ($summary['won_amount'] ?? 0), 2),
            ],
            'data' => $rows,
        ]);
    }

    public static function stageDuration(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.analytics.view');

        $ownerFilter = CrmService::resolveOwnerFilter($user, CrmSupport::queryInt('owner_user_id'));
        $pipelineKey = CrmSupport::queryStr('pipeline_key');
        if ($pipelineKey === '') {
            $pipelineKey = 'default';
        }

        $dateFrom = CrmSupport::parseDate(CrmSupport::queryStr('date_from'));
        $dateTo = CrmSupport::parseDate(CrmSupport::queryStr('date_to'));
        [$stages, $stageOrder] = self::pipelineStages($pdo, $pipelineKey);
        $stageNames = [];
        foreach ($stages as $stage) {
            $key = (string) ($stage['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $stageNames[$key] = (string) ($stage['name'] ?? $key);
        }

        $sql = 'SELECT l.from_stage_key,
                       COUNT(*) AS transition_count,
                       AVG(l.duration_seconds) AS avg_duration_seconds,
                       SUM(l.duration_seconds) AS total_duration_seconds
                FROM qiling_crm_deal_stage_logs l
                INNER JOIN qiling_crm_deals d ON d.id = l.deal_id
                WHERE l.pipeline_key = :pipeline_key
                  AND l.from_stage_key <> \'\'';
        $params = ['pipeline_key' => $pipelineKey];

        if ($ownerFilter !== null) {
            $sql .= ' AND d.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $ownerFilter;
        }
        if ($dateFrom !== null) {
            $sql .= ' AND l.changed_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $sql .= ' AND l.changed_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $sql .= ' GROUP BY l.from_stage_key';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        foreach ($rows as &$row) {
            $key = (string) ($row['from_stage_key'] ?? '');
            $avgSeconds = (float) ($row['avg_duration_seconds'] ?? 0);
            $totalSeconds = (float) ($row['total_duration_seconds'] ?? 0);

            if (!isset($stageOrder[$key])) {
                $stageOrder[$key] = 9999;
            }

            $row['stage_key'] = $key;
            $row['stage_name'] = $stageNames[$key] ?? $key;
            $row['transition_count'] = (int) ($row['transition_count'] ?? 0);
            $row['avg_duration_seconds'] = (int) round($avgSeconds);
            $row['avg_duration_hours'] = round($avgSeconds / 3600, 2);
            $row['total_duration_seconds'] = (int) round($totalSeconds);
            $row['total_duration_hours'] = round($totalSeconds / 3600, 2);
            $row['stage_sort'] = (int) $stageOrder[$key];
            unset($row['from_stage_key']);
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $sa = (int) ($a['stage_sort'] ?? 9999);
            $sb = (int) ($b['stage_sort'] ?? 9999);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp((string) ($a['stage_key'] ?? ''), (string) ($b['stage_key'] ?? ''));
        });

        foreach ($rows as &$row) {
            unset($row['stage_sort']);
        }
        unset($row);

        Response::json([
            'pipeline_key' => $pipelineKey,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'data' => $rows,
        ]);
    }

    public static function trends(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.analytics.view');

        $ownerFilter = CrmService::resolveOwnerFilter($user, CrmSupport::queryInt('owner_user_id'));
        $pipelineKey = CrmSupport::queryStr('pipeline_key');

        $dimension = strtolower(CrmSupport::queryStr('dimension'));
        if (!in_array($dimension, ['channel', 'region', 'owner'], true)) {
            $dimension = 'channel';
        }

        $months = CrmSupport::queryInt('months') ?? 6;
        $months = max(1, min($months, 24));
        $startAt = gmdate('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months'));

        $joins = '';
        $dimensionKeySql = "COALESCE(NULLIF(TRIM(d.source_channel), ''), '未设置')";
        $dimensionLabelSql = $dimensionKeySql;

        if ($dimension === 'region') {
            $joins = ' LEFT JOIN qiling_crm_companies cp ON cp.id = d.company_id';
            $dimensionKeySql = "COALESCE(NULLIF(TRIM(cp.country_code), ''), '未设置')";
            $dimensionLabelSql = $dimensionKeySql;
        } elseif ($dimension === 'owner') {
            $joins = ' LEFT JOIN qiling_users ou ON ou.id = d.owner_user_id';
            $dimensionKeySql = 'CAST(COALESCE(d.owner_user_id, 0) AS CHAR)';
            $dimensionLabelSql = "CASE
                                   WHEN d.owner_user_id IS NULL OR d.owner_user_id = 0 THEN '未设置'
                                   ELSE COALESCE(NULLIF(TRIM(ou.username), ''), CONCAT('用户#', d.owner_user_id))
                                 END";
        }

        $sql = 'SELECT DATE_FORMAT(d.created_at, \'%Y-%m\') AS month_bucket,
                       ' . $dimensionKeySql . ' AS dim_key,
                       ' . $dimensionLabelSql . ' AS dim_label,
                       COUNT(*) AS deals_count,
                       SUM(CASE WHEN d.deal_status = \'won\' THEN 1 ELSE 0 END) AS won_count,
                       SUM(CASE WHEN d.deal_status = \'won\' THEN d.amount ELSE 0 END) AS won_amount
                FROM qiling_crm_deals d' . $joins . '
                WHERE d.created_at >= :start_at';
        $params = ['start_at' => $startAt];

        if ($pipelineKey !== '') {
            $sql .= ' AND d.pipeline_key = :pipeline_key';
            $params['pipeline_key'] = $pipelineKey;
        }
        if ($ownerFilter !== null) {
            $sql .= ' AND d.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $ownerFilter;
        }

        $sql .= ' GROUP BY month_bucket, dim_key, dim_label
                  ORDER BY month_bucket ASC, won_amount DESC
                  LIMIT 5000';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $timeline = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $timeline[] = gmdate('Y-m', strtotime('-' . $i . ' months'));
        }

        $seriesMap = [];
        foreach ($rows as $row) {
            $bucket = (string) ($row['month_bucket'] ?? '');
            $dimKey = (string) ($row['dim_key'] ?? '');
            if ($bucket === '' || $dimKey === '') {
                continue;
            }

            $dimLabel = (string) ($row['dim_label'] ?? $dimKey);
            if (!isset($seriesMap[$dimKey])) {
                $seriesMap[$dimKey] = [
                    'dimension_key' => $dimKey,
                    'dimension_label' => $dimLabel,
                    'total_deals' => 0,
                    'total_won_count' => 0,
                    'total_won_amount' => 0.0,
                    'points' => [],
                ];
            }

            $deals = (int) ($row['deals_count'] ?? 0);
            $wonCount = (int) ($row['won_count'] ?? 0);
            $wonAmount = round((float) ($row['won_amount'] ?? 0), 2);

            $seriesMap[$dimKey]['points'][$bucket] = [
                'month' => $bucket,
                'deals_count' => $deals,
                'won_count' => $wonCount,
                'won_amount' => $wonAmount,
            ];
            $seriesMap[$dimKey]['total_deals'] += $deals;
            $seriesMap[$dimKey]['total_won_count'] += $wonCount;
            $seriesMap[$dimKey]['total_won_amount'] += $wonAmount;
        }

        $series = array_values($seriesMap);
        foreach ($series as &$item) {
            $points = [];
            foreach ($timeline as $monthKey) {
                if (isset($item['points'][$monthKey]) && is_array($item['points'][$monthKey])) {
                    $points[] = $item['points'][$monthKey];
                } else {
                    $points[] = [
                        'month' => $monthKey,
                        'deals_count' => 0,
                        'won_count' => 0,
                        'won_amount' => 0.0,
                    ];
                }
            }
            $item['points'] = $points;
            $item['total_won_amount'] = round((float) $item['total_won_amount'], 2);
        }
        unset($item);

        usort($series, static function (array $a, array $b): int {
            $wa = (float) ($a['total_won_amount'] ?? 0);
            $wb = (float) ($b['total_won_amount'] ?? 0);
            if ($wa !== $wb) {
                return $wb <=> $wa;
            }
            return strcmp((string) ($a['dimension_label'] ?? ''), (string) ($b['dimension_label'] ?? ''));
        });

        if (count($series) > 30) {
            $series = array_slice($series, 0, 30);
        }

        Response::json([
            'dimension' => $dimension,
            'months' => $months,
            'timeline' => $timeline,
            'series' => $series,
        ]);
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<string,int>}
     */
    private static function pipelineStages(PDO $pdo, string $pipelineKey): array
    {
        $default = [
            ['key' => 'new', 'name' => '新建线索', 'sort' => 10],
            ['key' => 'contacted', 'name' => '已触达', 'sort' => 20],
            ['key' => 'qualified', 'name' => '已确认需求', 'sort' => 30],
            ['key' => 'proposal', 'name' => '方案/报价', 'sort' => 40],
            ['key' => 'negotiation', 'name' => '商务谈判', 'sort' => 50],
            ['key' => 'won', 'name' => '赢单', 'sort' => 60],
            ['key' => 'lost', 'name' => '输单', 'sort' => 70],
        ];

        $stmt = $pdo->prepare(
            'SELECT stages_json
             FROM qiling_crm_pipelines
             WHERE pipeline_key = :pipeline_key
             LIMIT 1'
        );
        $stmt->execute(['pipeline_key' => $pipelineKey]);
        $raw = (string) $stmt->fetchColumn();

        $decoded = CrmSupport::decodeJsonArray($raw);
        $stages = [];
        foreach ($decoded as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($key === '' || $name === '') {
                continue;
            }
            $stages[] = [
                'key' => $key,
                'name' => $name,
                'sort' => is_numeric($item['sort'] ?? null) ? (int) $item['sort'] : (($index + 1) * 10),
            ];
        }

        if ($stages === []) {
            $stages = $default;
        }

        usort($stages, static function (array $a, array $b): int {
            $sa = (int) ($a['sort'] ?? 9999);
            $sb = (int) ($b['sort'] ?? 9999);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });

        $order = [];
        foreach ($stages as $stage) {
            $order[(string) ($stage['key'] ?? '')] = (int) ($stage['sort'] ?? 9999);
        }

        return [$stages, $order];
    }
}

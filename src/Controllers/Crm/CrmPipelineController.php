<?php

declare(strict_types=1);

namespace Qiling\Controllers\Crm;

use PDO;
use Qiling\Core\Audit;
use Qiling\Core\CrmService;
use Qiling\Support\Request;
use Qiling\Support\Response;

final class CrmPipelineController
{
    public static function pipelines(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.pipelines.view');
        $rows = $pdo->query(
            'SELECT id, pipeline_key, pipeline_name, stages_json, is_system, status, created_at, updated_at
             FROM qiling_crm_pipelines
             WHERE status = \'active\'
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['is_system'] = (int) ($row['is_system'] ?? 0);
            $row['stages'] = CrmSupport::decodeJsonArray($row['stages_json'] ?? null);
            unset($row['stages_json']);
        }
        unset($row);

        Response::json(['data' => $rows]);
    }

    public static function upsertPipeline(): void
    {
        [$user, $pdo] = CrmSupport::context();
        CrmSupport::requirePermission($user, 'crm.pipelines.manage');
        if (!CrmService::canManageAll($user)) {
            Response::json(['message' => 'forbidden: manager only'], 403);
            return;
        }

        $data = Request::jsonBody();
        $pipelineKey = strtolower(Request::str($data, 'pipeline_key'));
        $pipelineName = Request::str($data, 'pipeline_name');
        $status = Request::str($data, 'status', 'active');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'active';

        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $pipelineKey)) {
            Response::json(['message' => 'pipeline_key invalid'], 422);
            return;
        }
        if ($pipelineName === '') {
            Response::json(['message' => 'pipeline_name is required'], 422);
            return;
        }

        $stagesRaw = $data['stages'] ?? [];
        if (!is_array($stagesRaw) || $stagesRaw === []) {
            Response::json(['message' => 'stages is required'], 422);
            return;
        }
        $stages = [];
        foreach ($stagesRaw as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = strtolower(trim((string) ($item['key'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));
            $sort = is_numeric($item['sort'] ?? null) ? (int) $item['sort'] : (($index + 1) * 10);
            if (!preg_match('/^[a-z0-9_-]{2,40}$/', $key) || $name === '') {
                continue;
            }
            $stages[] = [
                'key' => $key,
                'name' => $name,
                'sort' => $sort,
            ];
        }
        if ($stages === []) {
            Response::json(['message' => 'stages invalid'], 422);
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO qiling_crm_pipelines
             (pipeline_key, pipeline_name, stages_json, is_system, status, created_at, updated_at)
             VALUES
             (:pipeline_key, :pipeline_name, :stages_json, 0, :status, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                pipeline_name = VALUES(pipeline_name),
                stages_json = VALUES(stages_json),
                status = VALUES(status),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'pipeline_key' => $pipelineKey,
            'pipeline_name' => $pipelineName,
            'stages_json' => json_encode($stages, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::log((int) $user['id'], 'crm.pipeline.upsert', 'crm_pipeline', 0, 'Upsert crm pipeline', [
            'pipeline_key' => $pipelineKey,
            'status' => $status,
            'stages' => $stages,
        ]);

        Response::json([
            'pipeline_key' => $pipelineKey,
            'pipeline_name' => $pipelineName,
            'status' => $status,
            'stages' => $stages,
        ]);
    }
}

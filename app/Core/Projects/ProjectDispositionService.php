<?php

namespace App\Core\Projects;

use App\Connectors\RuntimeClient;
use App\Core\Brand\WorkspaceBrandKitResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProjectDispositionService — close out a project and record what happened.
 *
 * Composite score formula:
 *   composite = milestone_score * w_m + kpi_score * w_k
 *
 * If only milestones exist: composite = milestone_score (kpi weight ignored).
 * If only KPIs exist:        composite = kpi_score.
 * If neither: composite = null (recorded but no metric).
 *
 * Side effects on disposition:
 *   1. Project status transitions:
 *      succeeded / partially_succeeded → completed
 *      failed / abandoned              → cancelled
 *   2. Sarah generates a prose narrative (runtime LLM)
 *   3. The outcome is recorded into engine_intelligence as
 *      knowledge_type='project_outcome' so Sarah can learn across projects
 *      and across workspaces sharing the same industry.
 */
class ProjectDispositionService
{
    public const OUTCOMES = ['succeeded', 'partially_succeeded', 'failed', 'abandoned'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly MilestoneService $milestones,
        private readonly KpiService $kpis,
        private readonly RuntimeClient $runtime,
        private readonly WorkspaceBrandKitResolver $brand,
    ) {}

    /**
     * Dispose a project.
     *
     * Required: $wsId, $projectId, $outcome, $userId
     *
     * Optional $opts:
     *   - weights:         ['milestone' => 0.5, 'kpi' => 0.5]
     *   - evidence:        array — appended to outcome row
     *   - skip_narrative:  bool — don't call runtime (useful in tests)
     *   - replace:         bool — overwrite existing outcome if one exists
     */
    public function disposeProject(int $wsId, int $projectId, string $outcome, int $userId, array $opts = []): array
    {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            return ['success' => false, 'error' => "invalid outcome: $outcome"];
        }

        $proj = DB::table('projects')
            ->where('id', $projectId)->where('workspace_id', $wsId)
            ->whereNull('deleted_at')->first();
        if (!$proj) return ['success' => false, 'error' => 'project not found in workspace'];

        // Already disposed?
        $existing = DB::table('project_outcomes')->where('project_id', $projectId)->first();
        if ($existing && empty($opts['replace'])) {
            return [
                'success'    => false,
                'error'      => 'project already disposed',
                'outcome_id' => $existing->id,
            ];
        }

        // ── Compute scores ─────────────────────────────────────────────
        $msScore  = $this->milestones->score($wsId, $projectId);
        $kpiScore = $this->kpis->score($wsId, $projectId);

        $weights = $opts['weights'] ?? ['milestone' => 0.5, 'kpi' => 0.5];
        // Normalize weights to sum to 1 (defensive — UI might pass anything)
        $wSum = (float) ($weights['milestone'] ?? 0) + (float) ($weights['kpi'] ?? 0);
        if ($wSum <= 0) $weights = ['milestone' => 0.5, 'kpi' => 0.5];
        else {
            $weights = [
                'milestone' => (float) $weights['milestone'] / $wSum,
                'kpi'       => (float) $weights['kpi']       / $wSum,
            ];
        }

        $msVal  = $msScore['score'];   // float|null
        $kpiVal = $kpiScore['score'];  // float|null
        $composite = $this->composeScore($msVal, $kpiVal, $weights);

        // ── Narrative ──────────────────────────────────────────────────
        $narrative = '';
        if (empty($opts['skip_narrative'])) {
            $narrative = $this->generateNarrative($wsId, $proj, $outcome, $msVal, $kpiVal, $composite, $opts['evidence'] ?? []);
        }

        // ── Persist outcome ────────────────────────────────────────────
        $now = now();
        $outcomePayload = [
            'project_id'      => $projectId,
            'workspace_id'    => $wsId,
            'outcome'         => $outcome,
            'composite_score' => $composite,
            'milestone_score' => $msVal,
            'kpi_score'       => $kpiVal,
            'weights_json'    => json_encode($weights),
            'evidence_json'   => json_encode($opts['evidence'] ?? []),
            'narrative'       => $narrative ?: null,
            'disposed_by'     => $userId,
            'disposed_at'     => $now,
            'metadata_json'   => json_encode([
                'milestone_breakdown' => [
                    'achieved' => $msScore['achieved'] ?? null,
                    'total'    => $msScore['total']    ?? null,
                ],
                'kpi_breakdown' => $kpiScore['breakdown'] ?? null,
            ]),
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        if ($existing) {
            DB::table('project_outcomes')->where('id', $existing->id)->update($outcomePayload);
            $outcomeId = $existing->id;
        } else {
            $outcomeId = DB::table('project_outcomes')->insertGetId($outcomePayload);
        }

        // ── Transition project status ──────────────────────────────────
        // Disposition is terminal — bypass the state machine and write the
        // target status directly. The state machine forbids 'proposed' →
        // 'completed' (Phase 1 graph), but disposition is the authoritative
        // close-out and must work for any non-archived starting state.
        $targetStatus = in_array($outcome, ['succeeded', 'partially_succeeded'], true)
            ? 'completed' : 'cancelled';
        if ($proj->status !== $targetStatus && $proj->status !== 'archived') {
            DB::table('projects')->where('id', $projectId)->update([
                'status'        => $targetStatus,
                'completed_at'  => $targetStatus === 'completed' ? $now : null,
                'updated_at'    => $now,
            ]);
        }

        // ── Record into engine_intelligence (learning loop) ────────────
        $this->recordLearning($wsId, $proj, $outcome, $composite, $msVal, $kpiVal, $narrative, $opts['evidence'] ?? []);

        return [
            'success'         => true,
            'outcome_id'      => $outcomeId,
            'outcome'         => $outcome,
            'composite_score' => $composite,
            'milestone_score' => $msVal,
            'kpi_score'       => $kpiVal,
            'narrative'       => $narrative,
            'weights'         => $weights,
            'project_status'  => $targetStatus,
        ];
    }

    public function get(int $wsId, int $projectId): array
    {
        $row = DB::table('project_outcomes')
            ->where('project_id', $projectId)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'no outcome recorded'];
        return ['success' => true, 'data' => $this->hydrate($row)];
    }

    /**
     * Pull prior project_outcome learnings for an industry.
     *
     * Used by SarahProjectPlanner (next iteration) to ground proposals
     * in what worked / what didn't for similar industries.
     */
    public function getLearnings(string $industry, int $limit = 5): array
    {
        // Use JSON_EXTRACT so MySQL handles the json-escaped-slash case
        // (e.g. industry "SaaS / AI Marketing Platform" is stored as
        // "SaaS \/ AI Marketing Platform" in the JSON text, which a naive
        // LIKE pattern would never match).
        $rows = DB::table('engine_intelligence')
            ->where('knowledge_type', 'project_outcome')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.industry')) = ?", [$industry])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        return [
            'success' => true,
            'count'   => $rows->count(),
            'data'    => $rows->map(fn($r) => [
                'id'                  => $r->id,
                'engine'              => $r->engine,
                'content'             => $r->content,
                'metadata'            => json_decode($r->metadata_json ?? '{}', true) ?: [],
                'effectiveness_score' => $r->effectiveness_score,
                'created_at'          => $r->created_at,
            ])->toArray(),
        ];
    }

    private function composeScore(?float $ms, ?float $kpi, array $weights): ?float
    {
        if ($ms === null && $kpi === null) return null;
        if ($ms === null)  return round($kpi, 2);
        if ($kpi === null) return round($ms,  2);
        return round($ms * $weights['milestone'] + $kpi * $weights['kpi'], 2);
    }

    private function generateNarrative(
        int $wsId,
        object $proj,
        string $outcome,
        ?float $msScore,
        ?float $kpiScore,
        ?float $composite,
        array $evidence
    ): string {
        $kit = $this->brand->resolve($wsId);
        $brand    = $kit['brand_name']      ?? 'this business';
        $industry = $kit['industry']        ?? 'general SMB';

        $evidenceLine = empty($evidence) ? 'No additional evidence captured.'
            : "Evidence captured: " . json_encode($evidence);

        $scoreLine = "Milestone score: " . ($msScore !== null ? "{$msScore}%" : 'n/a')
            . ", KPI score: " . ($kpiScore !== null ? "{$kpiScore}%" : 'n/a')
            . ", Composite: " . ($composite !== null ? "{$composite}%" : 'n/a');

        $prompt = "You are Sarah, the Digital Marketing Manager closing out a project. "
            . "Write a SHORT (2-3 sentence) outcome summary in your own voice. "
            . "Focus on what was learned, not just what happened. No headers, no bullets, no formal report tone.\n\n"
            . "Brand: {$brand}\n"
            . "Industry: {$industry}\n"
            . "Project goal: " . ($proj->goal ?? '(unspecified)') . "\n"
            . "Outcome verdict: {$outcome}\n"
            . "{$scoreLine}\n"
            . "{$evidenceLine}";

        $result = $this->runtime->aiRun('email_generation', $prompt, [
            'industry' => $industry,
            'outcome'  => $outcome,
        ], 600);

        if (empty($result['success']) || empty($result['text'])) {
            Log::warning('[ProjectDispositionService] runtime narrative failed', [
                'workspace_id' => $wsId,
                'project_id'   => $proj->id,
                'error'        => $result['error'] ?? null,
            ]);
            // Fallback: deterministic stub so disposition still completes
            return "Project closed as {$outcome}. {$scoreLine}.";
        }

        return trim((string) $result['text']);
    }

    /**
     * Record outcome into engine_intelligence for cross-workspace learning.
     *
     * Scope is 'industry:X' so future workspaces in the same industry
     * benefit. workspace_id is also captured in metadata for traceability.
     */
    private function recordLearning(
        int $wsId,
        object $proj,
        string $outcome,
        ?float $composite,
        ?float $msScore,
        ?float $kpiScore,
        string $narrative,
        array $evidence
    ): void {
        try {
            $kit = $this->brand->resolve($wsId);
            $industry = $kit['industry'] ?? 'general';

            $engine = 'strategy'; // Projects originate from Strategy Room mostly
            if (isset($proj->source_type) && $proj->source_type === 'sarah_campaign') {
                $engine = 'marketing';
            }

            $content = "Project outcome ({$outcome}, composite {$composite}%): "
                . ($proj->goal ?? '(unspecified goal)') . ". "
                . ($narrative ?: '');

            // Key includes workspace_id so each workspace's outcome for a
            // given project is unique under the table's (engine, type, key)
            // composite unique constraint. updateOrInsert is used so a
            // re-disposition (replace=true) overwrites instead of failing.
            $key = 'ws_' . $wsId . '_project_' . $proj->id;
            DB::table('engine_intelligence')->updateOrInsert(
                [
                    'engine'         => $engine,
                    'knowledge_type' => 'project_outcome',
                    'key'            => $key,
                ],
                [
                    'content'             => mb_substr($content, 0, 2000),
                    'metadata_json'       => json_encode([
                        'scope'              => 'industry:' . $industry,
                        'industry'           => $industry,
                        'workspace_id'       => $wsId,
                        'project_id'         => (int) $proj->id,
                        'outcome'            => $outcome,
                        'composite_score'    => $composite,
                        'milestone_score'    => $msScore,
                        'kpi_score'          => $kpiScore,
                        'source_type'        => $proj->source_type ?? null,
                        'source_meeting_id'  => $proj->source_meeting_id ?? null,
                        'evidence'           => $evidence,
                    ]),
                    'effectiveness_score' => $composite,
                    'updated_at'          => now(),
                    'created_at'          => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Learning-loop failure must not break disposition itself
            Log::warning('[ProjectDispositionService] failed to record learning', [
                'project_id' => $proj->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function hydrate($row): array
    {
        $arr = (array) $row;
        $arr['weights']  = json_decode($arr['weights_json']  ?? '{}', true) ?: [];
        $arr['evidence'] = json_decode($arr['evidence_json'] ?? '{}', true) ?: [];
        $arr['metadata'] = json_decode($arr['metadata_json'] ?? '{}', true) ?: [];
        unset($arr['weights_json'], $arr['evidence_json'], $arr['metadata_json']);
        if ($arr['composite_score'] !== null) $arr['composite_score'] = (float) $arr['composite_score'];
        if ($arr['milestone_score'] !== null) $arr['milestone_score'] = (float) $arr['milestone_score'];
        if ($arr['kpi_score']       !== null) $arr['kpi_score']       = (float) $arr['kpi_score'];
        return $arr;
    }
}
<?php

namespace App\Core\Intelligence;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * ToolSelectorService — the brain Sarah was missing.
 *
 * Given a workspace, a goal, and a capability need, scores every candidate
 * tool across 4 weighted dimensions and returns the best match(es).
 *
 * Scoring dimensions (weights sum to 1.0):
 *   - effectiveness         (0.35)  rolling avg from engine_intelligence.effectiveness_score
 *   - industry_relevance    (0.25)  blueprint metadata.industries vs workspace.industry
 *   - past_success          (0.25)  lu_tasks success rate for this engine+action
 *   - constraint_fit        (0.15)  budget, plan, credit balance fit
 *
 * Cold-start handling: NULL effectiveness_score is treated as 0.5 neutral prior.
 * No synthetic data in DB — scorer handles nullability cleanly.
 *
 * Returns a ToolSelectionResult array:
 *   [
 *     'sequence' => [ { engine, action, agent, score, justification, cost }, ... ],
 *     'total_cost' => int (credits),
 *     'confidence' => float (0-1),
 *     'rejected' => [ { tool, reason, score }, ... ]
 *   ]
 */
class ToolSelectorService
{
    private const WEIGHTS = [
        'effectiveness' => 0.35,
        'industry_relevance' => 0.25,
        'past_success' => 0.25,
        'constraint_fit' => 0.15,
    ];

    private const THRESHOLD = 0.40; // tools below this are rejected
    private const NEUTRAL_PRIOR = 0.50; // NULL effectiveness → 0.5

    public function __construct(
        private EngineIntelligenceService $engineIntel,
        private ToolCostCalculatorService $costCalc,
        private \App\Core\EngineKernel\CapabilityMapService $capabilityMap,
    ) {}

    /**
     * Main entry point. Called by Sarah.
     */
    public function selectTools(int $wsId, string $goal, array $analysis): array
    {
        $workspace = Workspace::find($wsId);
        $industry = $workspace?->industry;
        $engines = $analysis['engines_required'] ?? [];
        $budget = $analysis['budget_credits'] ?? PHP_INT_MAX;

        $sequence = [];
        $rejected = [];
        $totalCost = 0;
        $scoreSum = 0.0;
        $scoreCount = 0;

        // For each required engine, select the best tool chain
        foreach ($engines as $engine) {
            $candidates = $this->getCandidatesForEngine($engine, $goal, $analysis);

            foreach ($candidates as $candidate) {
                $scores = $this->scoreTool($candidate, $workspace, $industry, $budget, $totalCost);
                $weighted = $this->computeWeightedScore($scores);

                if ($weighted < self::THRESHOLD) {
                    $rejected[] = [
                        'engine' => $engine,
                        'tool' => $candidate['key'],
                        'score' => $weighted,
                        'scores' => $scores,
                        'reason' => $this->explainRejection($scores),
                    ];
                    continue;
                }

                $cost = $this->costCalc->costForTool($engine, $candidate['key']);
                $sequence[] = [
                    'engine' => $engine,
                    'action' => $candidate['key'],
                    'agent' => $this->suggestAgent($engine, $candidate['key']),
                    'params' => $this->defaultParamsFor($candidate['key'], $goal, $analysis),
                    'depends_on' => $this->inferDependencies($engine, $candidate['key'], $sequence),
                    'score' => round($weighted, 3),
                    'scores' => $scores,
                    'justification' => $this->buildJustification($engine, $candidate, $scores, $weighted),
                    'cost' => $cost,
                ];

                $totalCost += $cost;
                $scoreSum += $weighted;
                $scoreCount++;
            }
        }

        $confidence = $scoreCount > 0 ? round($scoreSum / $scoreCount, 3) : 0.0;

        return [
            'sequence' => $sequence,
            'total_cost' => $totalCost,
            'confidence' => $confidence,
            'rejected' => $rejected,
            'dimensions_weights' => self::WEIGHTS,
            'threshold' => self::THRESHOLD,
        ];
    }

    /**
     * Fetch candidate tools for an engine from the intelligence layer.
     *
     * FIX 2026-04-13 (Phase 1.5): the engine_intelligence seed mixed two
     * concept layers — real CapabilityMap action keys (`generate_image`,
     * `generate_video`, etc.) AND phantom blueprint type names
     * (`blueprint_article`, `blueprint_email`, etc. — these are concepts
     * BlueprintService produces, not capability map actions). Sarah's plans
     * were including the phantom keys, then EngineExecutionService threw
     * "Unknown action: creative/blueprint_article" at execution time and
     * every task failed.
     *
     * The filter below keeps only candidates whose `key` actually resolves
     * through CapabilityMapService::resolveAction(). Phantom blueprint keys
     * get silently dropped from the candidate pool. Re-seeding the
     * engine_intelligence table to remove the phantoms is the proper
     * Phase 2E.10 cleanup; this filter is the tactical fix that lets Sarah
     * end-to-end work today.
     */
    private function getCandidatesForEngine(string $engine, string $goal, array $analysis): array
    {
        $briefing = $this->engineIntel->getBriefing($engine);
        $candidates = [];

        foreach ($briefing['tools'] ?? [] as $tool) {
            // Skip blueprint candidates that aren't real capability actions.
            // Try resolving with both bare action and engine-prefixed forms
            // (CapabilityMapService::resolveAction does this internally).
            if ($this->capabilityMap->resolveAction($engine, $tool->key) === null) {
                continue;
            }

            $meta = json_decode($tool->metadata_json ?? '{}', true) ?: [];
            $candidates[] = [
                'key' => $tool->key,
                'content' => $tool->content,
                'effectiveness_score' => $tool->effectiveness_score, // nullable
                'usage_count' => $tool->usage_count ?? 0,
                'metadata' => $meta,
            ];
        }

        // If no blueprints exist (seeding hasn't run), fall back to empty — caller will skip engine
        return $candidates;
    }

    /**
     * Score a single tool across 4 dimensions. Each score is 0.0-1.0.
     */
    private function scoreTool(array $candidate, ?Workspace $workspace, ?string $industry, int $budget, int $runningCost): array
    {
        // 1. Effectiveness — rolling avg from past executions, NULL → neutral
        $effectiveness = $candidate['effectiveness_score'];
        if ($effectiveness === null) {
            $effectiveness = self::NEUTRAL_PRIOR;
        } else {
            $effectiveness = (float) $effectiveness;
        }

        // 2. Industry relevance — check metadata.industries if present
        $industryRelevance = self::NEUTRAL_PRIOR;
        if ($industry && isset($candidate['metadata']['industries'])) {
            $tags = (array) $candidate['metadata']['industries'];
            $industryRelevance = in_array($industry, $tags, true) ? 1.0 : 0.4;
        } elseif ($candidate['usage_count'] > 0) {
            // Some usage history exists but no industry tagging — mild positive signal
            $industryRelevance = 0.6;
        }

        // 3. Past success — look up lu_tasks for this engine+action in this workspace
        $pastSuccess = $this->computePastSuccess($workspace?->id, $candidate['key']);

        // 4. Constraint fit — credit budget
        $toolCost = (int) ($candidate['metadata']['credit_cost'] ?? 0);
        $remaining = $budget - $runningCost;
        $constraintFit = $remaining >= $toolCost ? 1.0 : ($toolCost > 0 ? max(0.0, $remaining / max(1, $toolCost)) : 1.0);

        return [
            'effectiveness' => round($effectiveness, 3),
            'industry_relevance' => round($industryRelevance, 3),
            'past_success' => round($pastSuccess, 3),
            'constraint_fit' => round($constraintFit, 3),
        ];
    }

    private function computeWeightedScore(array $scores): float
    {
        $total = 0.0;
        foreach (self::WEIGHTS as $key => $weight) {
            $total += ($scores[$key] ?? self::NEUTRAL_PRIOR) * $weight;
        }
        return $total;
    }

    private function computePastSuccess(?int $wsId, string $toolKey): float
    {
        if (!$wsId) return self::NEUTRAL_PRIOR;

        try {
            $row = DB::table('lu_tasks')
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN state = "completed" THEN 1 ELSE 0 END) as completed')
                ->where('workspace_id', $wsId)
                ->where('action', $toolKey)
                ->first();

            if (!$row || $row->total == 0) return self::NEUTRAL_PRIOR;
            return (float) $row->completed / (float) $row->total;
        } catch (\Throwable $e) {
            // Table may not exist in all environments — fall back to neutral
            return self::NEUTRAL_PRIOR;
        }
    }

    private function explainRejection(array $scores): string
    {
        $reasons = [];
        if ($scores['effectiveness'] < 0.4) $reasons[] = 'low historical effectiveness';
        if ($scores['industry_relevance'] < 0.4) $reasons[] = 'poor industry fit';
        if ($scores['past_success'] < 0.4) $reasons[] = 'poor past success rate in this workspace';
        if ($scores['constraint_fit'] < 0.4) $reasons[] = 'exceeds remaining budget';
        return $reasons ? implode(', ', $reasons) : 'below score threshold';
    }

    private function buildJustification(string $engine, array $candidate, array $scores, float $weighted): string
    {
        $parts = [];
        $parts[] = "Selected {$engine}.{$candidate['key']} (score " . round($weighted * 100) . "%)";

        if ($scores['effectiveness'] > 0.7) {
            $parts[] = "strong historical effectiveness";
        } elseif ($scores['effectiveness'] == self::NEUTRAL_PRIOR && $candidate['usage_count'] == 0) {
            $parts[] = "no history yet — neutral prior";
        }

        if ($scores['industry_relevance'] >= 0.8) {
            $parts[] = "highly relevant to industry";
        }

        if ($scores['past_success'] > 0.7) {
            $parts[] = "strong past success in this workspace";
        }

        if ($candidate['usage_count'] > 10) {
            $parts[] = "proven ({$candidate['usage_count']} past uses)";
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * Suggest the agent best matched to this tool.
     * Matches the permanent agent team: james/priya/marcus/elena/alex/sarah.
     */
    private function suggestAgent(string $engine, string $action): string
    {
        $map = [
            'seo' => 'james',
            'write' => 'priya',
            'creative' => 'sarah',
            'social' => 'marcus',
            'marketing' => 'elena',
            'crm' => 'elena',
            'builder' => 'sarah',
            'calendar' => 'sarah',
            'beforeafter' => 'sarah',
            'traffic' => 'alex',
            'manualedit' => 'sarah',
        ];
        return $map[$engine] ?? 'sarah';
    }

    /**
     * Generate sensible default params for a tool given the goal.
     * Sarah can override these in her plan.
     */
    private function defaultParamsFor(string $action, string $goal, array $analysis): array
    {
        return match ($action) {
            'serp_analysis' => ['keyword' => $goal],
            'deep_audit' => ['url' => $analysis['target_url'] ?? $goal],
            'write_article' => ['topic' => $goal, 'length' => 2000],
            'generate_outline' => ['topic' => $goal],
            'generate_image' => ['prompt' => $goal],
            'generate_video' => ['prompt' => $goal],
            'create_campaign' => ['name' => substr($goal, 0, 100), 'type' => 'email'],
            'social_create_post' => ['content' => $goal],
            'wizard_generate' => ['goal' => $goal],
            'create_event' => ['title' => substr($goal, 0, 100)],
            default => [],
        };
    }

    /**
     * Infer task dependencies. Research before writing, writing before creative, etc.
     */
    private function inferDependencies(string $engine, string $action, array $existingSequence): array
    {
        $deps = [];
        $engineOrder = ['seo', 'write', 'creative', 'marketing', 'social', 'crm', 'builder', 'calendar', 'beforeafter', 'traffic', 'manualedit'];
        $myPos = array_search($engine, $engineOrder, true);

        foreach ($existingSequence as $idx => $prior) {
            $priorPos = array_search($prior['engine'], $engineOrder, true);
            if ($priorPos !== false && $myPos !== false && $priorPos < $myPos) {
                $deps[] = $idx;
            }
        }

        return $deps;
    }
}

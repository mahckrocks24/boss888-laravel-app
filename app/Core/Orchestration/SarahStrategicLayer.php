<?php

namespace App\Core\Orchestration;

use App\Core\Intelligence\GlobalKnowledgeService;
use App\Core\Intelligence\AgentExperienceService;
use App\Core\Intelligence\Validation\IntelligenceValidator;
use App\Core\Billing\CreditService;
use App\Connectors\DeepSeekConnector;
use App\Models\Workspace;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * Sarah's Strategic Judgment Layer.
 *
 * This is NOT execution. This is THINKING.
 *
 * Before Sarah creates a plan, she:
 *   1. CHALLENGES the goal — is it clear? achievable? worth the cost?
 *   2. ASSESSES RISK — what could go wrong? what's the blast radius?
 *   3. PRIORITIZES by ROI — which tasks create the most value per credit?
 *   4. SUGGESTS ALTERNATIVES — has something better worked before?
 *   5. ESTIMATES OUTCOME — based on past data, what's the likely result?
 *
 * This layer turns Sarah from an executor into a strategic manager
 * who pushes back when things don't make sense.
 */
class SarahStrategicLayer
{
    public function __construct(
        private GlobalKnowledgeService $globalKnowledge,
        private AgentExperienceService $agentExperience,
        private IntelligenceValidator $validator,
        private CreditService $credits,
        private DeepSeekConnector $llm,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    /**
     * Strategic assessment of a goal before planning.
     * Returns challenges, risks, alternatives, and a go/no-go recommendation.
     */
    public function assess(int $wsId, string $goal, array $analysis): array
    {
        $workspace = Workspace::find($wsId);
        $industry = $workspace?->industry;
        $creditBalance = $this->credits->getBalance($wsId);

        $assessment = [
            'goal_clarity' => $this->assessGoalClarity($goal),
            'budget_feasibility' => $this->assessBudgetFeasibility($analysis['credit_estimate'] ?? 0, $creditBalance),
            'risk_assessment' => $this->assessRisks($analysis, $workspace),
            'roi_estimate' => $this->estimateROI($analysis, $industry),
            'alternatives' => $this->suggestAlternatives($goal, $analysis, $industry),
            'past_performance' => $this->checkPastPerformance($wsId, $analysis['engines_required'] ?? [], $industry),
            'agent_readiness' => $this->assessAgentReadiness($analysis['agents_required'] ?? [], $industry),
            'recommendation' => null, // set below
            'reasoning' => [],
        ];

        // Generate recommendation
        $assessment['recommendation'] = $this->generateRecommendation($assessment);

        // If runtime available, get Sarah's strategic reasoning via runtime LLM
        // 2026-04-12 (Phase 1.0.4 / doc 14): now routes through RuntimeClient
        if ($this->runtime->isConfigured()) {
            $assessment['sarah_reasoning'] = $this->getSarahReasoning($goal, $assessment, $workspace);
        }

        return $assessment;
    }

    /**
     * Prioritize tasks by expected ROI.
     * Reorders and may remove low-value tasks.
     */
    public function prioritizeTasks(array $tasks, int $wsId, ?string $industry = null): array
    {
        $scored = [];

        foreach ($tasks as $task) {
            $roiScore = $this->calculateTaskROI($task, $industry);
            $riskScore = $this->calculateTaskRisk($task);
            $priorityScore = ($roiScore * 0.7) - ($riskScore * 0.3); // ROI-weighted

            $scored[] = array_merge($task, [
                '_roi_score' => round($roiScore, 2),
                '_risk_score' => round($riskScore, 2),
                '_priority_score' => round($priorityScore, 2),
                '_should_include' => $priorityScore > -0.3, // Drop very low value tasks
            ]);
        }

        // Sort by priority (highest first)
        usort($scored, fn($a, $b) => $b['_priority_score'] <=> $a['_priority_score']);

        // Maintain dependency order while respecting priority
        $filtered = array_values(array_filter($scored, fn($t) => $t['_should_include']));

        // Re-number steps
        foreach ($filtered as $i => &$task) {
            $task['step_order'] = $i + 1;
        }

        return $filtered;
    }

    /**
     * Challenge a proposed plan — push back if it doesn't make sense.
     * Returns [approved => bool, challenges => [], suggestions => []]
     */
    public function challengePlan(array $plan, int $wsId): array
    {
        $challenges = [];
        $suggestions = [];

        $tasks = $plan['tasks'] ?? [];
        $workspace = Workspace::find($wsId);

        // Challenge 1: Too many tasks for the goal
        if (count($tasks) > 10) {
            $challenges[] = [
                'severity' => 'warning',
                'message' => 'This plan has ' . count($tasks) . ' tasks. Complex plans have higher failure rates. Consider breaking this into phases.',
                'type' => 'complexity',
            ];
        }

        // Challenge 2: High credit cost without clear ROI
        $totalCredits = array_sum(array_map(fn($t) => $t['credits_used'] ?? $t['_credit_estimate'] ?? 0, $tasks));
        $balance = $this->credits->getBalance($wsId)['available'] ?? 0;

        if ($totalCredits > $balance * 0.5) {
            $challenges[] = [
                'severity' => 'critical',
                'message' => "This plan would use {$totalCredits} credits ({$balance} available). That's over 50% of your balance. Are you sure?",
                'type' => 'budget',
            ];
        }

        // Challenge 3: No research before execution
        $hasResearch = collect($tasks)->contains(fn($t) => in_array($t['action'] ?? '', ['serp_analysis', 'deep_audit', 'ai_report']));
        $hasExecution = collect($tasks)->contains(fn($t) => in_array($t['action'] ?? '', ['write_article', 'social_create_post', 'create_campaign']));

        if ($hasExecution && !$hasResearch) {
            $challenges[] = [
                'severity' => 'warning',
                'message' => 'This plan executes without research first. Campaigns based on data perform 2-3x better. Consider adding an audit or SERP analysis as step 1.',
                'type' => 'missing_research',
            ];
            $suggestions[] = ['action' => 'Add serp_analysis as first step', 'engine' => 'seo', 'reason' => 'Data-driven campaigns perform better'];
        }

        // Challenge 4: First time in this industry
        if ($workspace?->industry) {
            $pastOutcomes = DB::table('campaign_outcomes')
                ->where('industry', $workspace->industry)
                ->count();

            if ($pastOutcomes === 0) {
                $challenges[] = [
                    'severity' => 'info',
                    'message' => "This is the first campaign in the {$workspace->industry} industry. Sarah will proceed cautiously and monitor closely.",
                    'type' => 'new_territory',
                ];
            }
        }

        // Challenge 5: Publishing without approval chain
        $hasPublish = collect($tasks)->contains(fn($t) => in_array($t['action'] ?? '', ['social_create_post', 'publish_website', 'create_campaign']));
        if ($hasPublish) {
            $challenges[] = [
                'severity' => 'info',
                'message' => 'This plan includes publishing actions. Sarah will request your approval before anything goes live.',
                'type' => 'approval_required',
            ];
        }

        // Challenge 6: Agent overload — same agent assigned too many tasks
        $agentLoad = [];
        foreach ($tasks as $t) {
            $agent = $t['agent'] ?? $t['assigned_agent'] ?? 'sarah';
            $agentLoad[$agent] = ($agentLoad[$agent] ?? 0) + 1;
        }
        foreach ($agentLoad as $agent => $count) {
            if ($count > 4) {
                $challenges[] = [
                    'severity' => 'warning',
                    'message' => "Agent {$agent} has {$count} tasks in this plan. Consider distributing work across team.",
                    'type' => 'agent_overload',
                ];
            }
        }

        $approved = !collect($challenges)->contains(fn($c) => $c['severity'] === 'critical');

        return [
            'approved' => $approved,
            'challenges' => $challenges,
            'suggestions' => $suggestions,
            'challenge_count' => count($challenges),
            'critical_count' => collect($challenges)->where('severity', 'critical')->count(),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE — Assessment Methods
    // ═══════════════════════════════════════════════════════════

    /**
     * Wave 89 — Goal-clarity assessment via runtime (canonical).
     * On unreachable runtime, returns a goal-unclear default so Sarah
     * escalates to the user instead of guessing.
     */
    private function assessGoalClarity(string $goal): array
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $result = $rt->strategicAssessGoalClarity($goal);
            if ($result !== null) return $result;
        }
        // Wave 89 Phase D — runtime canonical. On unreachable runtime,
        // return a goal-unclear default so Sarah asks the user to clarify.
        return [
            'score'  => 0.0,
            'issues' => ['Strategic intelligence unavailable — please retry'],
            'clear'  => false,
        ];
    }


    private function assessBudgetFeasibility(int $estimated, array $balance): array
    {
        $available = $balance['available'] ?? 0;
        $feasible = $available >= $estimated;
        $percentage = $available > 0 ? round(($estimated / $available) * 100) : 100;

        return [
            'feasible' => $feasible,
            'estimated_cost' => $estimated,
            'available_credits' => $available,
            'usage_percentage' => $percentage,
            'warning' => $percentage > 80 ? 'This plan would use most of your remaining credits' : null,
        ];
    }

    /**
     * Wave 89 — Engine-set risk assessment via runtime (canonical).
     * On unreachable runtime, returns high-risk to force human review.
     */
    private function assessRisks(array $analysis, ?Workspace $workspace): array
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $engines = $analysis['engines_required'] ?? [];
            $creditEst = (int) ($analysis['credit_estimate'] ?? 0);
            $result = $rt->strategicAssessRisks($engines, $creditEst);
            if ($result !== null) return $result;
        }
        // Wave 89 Phase D — runtime canonical. On unreachable runtime,
        // return high-risk default to force human review of the plan.
        return [
            'level'       => 'high',
            'risks'       => [[
                'risk'       => 'Strategic intelligence unavailable',
                'mitigation' => 'Retry when runtime is restored',
                'severity'   => 'high',
            ]],
            'total_risks' => 1,
        ];
    }


    /**
     * Wave 89 — ROI estimation via runtime (canonical). DB query for
     * past-performance confidences stays local; scoring is runtime.
     * On unreachable runtime, returns neutral speculative ROI.
     */
    private function estimateROI(array $analysis, ?string $industry): array
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $pastData = $this->globalKnowledge->query([
                'category' => $analysis['engines_required'][0] ?? 'general',
                'industry' => $industry,
                'insight_type' => 'ab_result',
            ], 5);
            $confidences = collect($pastData)->pluck('confidence')->filter()->values()->all();
            $result = $rt->strategicEstimateROI($confidences);
            if ($result !== null) return $result;
        }
        // Wave 89 Phase D — runtime canonical. On unreachable runtime,
        // return a neutral speculative ROI so callers don't over-commit.
        return [
            'estimated_effectiveness' => 0.5,
            'data_points'             => 0,
            'confidence'              => 'speculative',
            'note'                    => 'Strategic intelligence unavailable',
        ];
    }


    private function suggestAlternatives(string $goal, array $analysis, ?string $industry): array
    {
        $alternatives = [];

        // Check if a simpler approach exists
        $topStrategies = $this->globalKnowledge->getTopStrategies($analysis['engines_required'][0] ?? 'marketing', $industry, 3);

        foreach ($topStrategies as $strategy) {
            $strat = json_decode($strategy->strategy_json ?? '{}', true);
            if ($strategy->effectiveness_score > 0.7) {
                $alternatives[] = [
                    'type' => 'proven_strategy',
                    'description' => "Previously successful approach: {$strategy->campaign_type} (effectiveness: " . round($strategy->effectiveness_score * 100) . "%)",
                    'effectiveness' => $strategy->effectiveness_score,
                    'strategy' => $strat,
                ];
            }
        }

        // Suggest research-first if not included
        $hasResearch = collect($analysis['engines_required'])->contains('seo');
        if (!$hasResearch) {
            $alternatives[] = [
                'type' => 'add_research',
                'description' => 'Consider starting with SEO research to make data-driven decisions',
                'effectiveness' => null,
            ];
        }

        return $alternatives;
    }

    private function checkPastPerformance(int $wsId, array $engines, ?string $industry): array
    {
        $outcomes = DB::table('campaign_outcomes')
            ->where('workspace_id', $wsId)
            ->whereIn('engine', $engines)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        if ($outcomes->isEmpty()) {
            return ['has_history' => false, 'message' => 'No past campaigns in these engines for this workspace'];
        }

        return [
            'has_history' => true,
            'campaign_count' => $outcomes->count(),
            'avg_effectiveness' => round($outcomes->avg('effectiveness_score') ?? 0, 2),
            'best_type' => $outcomes->sortByDesc('effectiveness_score')->first()?->campaign_type,
            'message' => 'Found ' . $outcomes->count() . ' past campaigns with avg effectiveness: ' . round(($outcomes->avg('effectiveness_score') ?? 0) * 100) . '%',
        ];
    }

    private function assessAgentReadiness(array $agents, ?string $industry): array
    {
        $readiness = [];

        foreach ($agents as $agentSlug) {
            $agent = Agent::where('slug', $agentSlug)->first();
            if (!$agent) continue;

            $trust = $this->validator->getAgentTrustScore($agent->id, $industry);
            $readiness[$agentSlug] = [
                'name' => $agent->name,
                'trust_score' => $trust['trust_score'],
                'autonomy' => $trust['autonomy_level'],
                'tasks_completed' => $trust['total_tasks'],
                'success_rate' => $trust['success_rate'],
                'ready' => $trust['trust_score'] >= 0.3,
                'concern' => $trust['trust_score'] < 0.3 ? "{$agent->name} has limited experience — Sarah will supervise closely" : null,
            ];
        }

        return $readiness;
    }

    /**
     * Wave 89 — Plan-go recommendation via runtime (canonical).
     * On unreachable runtime, returns 'reconsider' to force human review.
     */
    private function generateRecommendation(array $assessment): array
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $result = $rt->strategicGenerateRecommendation($assessment);
            if ($result !== null) return $result;
        }
        // Wave 89 Phase D — runtime canonical. On unreachable runtime,
        // return a 'reconsider' decision to force human review.
        return [
            'decision'   => 'reconsider',
            'confidence' => 0.0,
            'reasons'    => ['Strategic intelligence unavailable'],
            'message'    => "I can't make a recommendation right now — strategic services are unavailable. Please retry shortly.",
        ];
    }


    /**
     * Wave 89 — Per-task ROI score via runtime (canonical).
     * On unreachable runtime, returns neutral 0.5.
     */
    private function calculateTaskROI(array $task, ?string $industry): float
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $hasData = false;
            if ($industry) {
                $knowledge = $this->globalKnowledge->query(['category' => $task['engine'] ?? '', 'industry' => $industry], 3);
                $hasData = count($knowledge) > 0;
            }
            $result = $rt->strategicCalculateTaskROI($task['engine'] ?? '', $task['action'] ?? '', $hasData);
            if ($result !== null) return $result;
        }
        // Wave 89 Phase D — runtime canonical. Neutral ROI on failure.
        return 0.5;
    }


    /**
     * Wave 89 — Per-task risk score via runtime (canonical).
     * On unreachable runtime, returns conservative 0.8.
     */
    private function calculateTaskRisk(array $task): float
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $result = $rt->strategicCalculateTaskRisk($task['action'] ?? '');
            if ($result !== null) return $result;
        }
        // Wave 89 Phase D — runtime canonical. Conservative high risk on failure.
        return 0.8;
    }


    private function getSarahReasoning(string $goal, array $assessment, ?Workspace $workspace): string
    {
        $context = [];
        $context[] = "Goal: {$goal}";
        $context[] = "Recommendation: {$assessment['recommendation']['decision']}";
        $context[] = "Risks: " . ($assessment['risk_assessment']['total_risks'] ?? 0);
        $context[] = "Budget: " . ($assessment['budget_feasibility']['feasible'] ? 'OK' : 'TIGHT');
        if ($workspace) $context[] = "Industry: {$workspace->industry}, Location: {$workspace->location}";

        $challenges = $assessment['recommendation']['reasons'] ?? [];
        if (!empty($challenges)) $context[] = "Concerns: " . implode(', ', $challenges);

        $alternatives = $assessment['alternatives'] ?? [];
        if (!empty($alternatives)) $context[] = "Alternatives found: " . count($alternatives);

        // MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun fold-pattern
        // to chatJson. Sarah's persona is now the system prompt; the assessment
        // context is the user prompt. The LLM returns {"reasoning":"<3-4 sentences>"}
        // and we extract the string for the public return contract.
        $systemPrompt = "You are Sarah, Digital Marketing Manager. "
                      . "Provide a brief (3-4 sentences) strategic assessment of this campaign plan. "
                      . "Be honest about risks. Suggest improvements if you see them. "
                      . "You're advising the business owner directly. "
                      . "Output ONLY a valid JSON object of the form {\"reasoning\":\"<your assessment>\"}. "
                      . "No markdown, no commentary outside the JSON.";

        $userPrompt = "ASSESSMENT CONTEXT:\n" . implode("\n", $context);

        $result = $this->runtime->chatJson($systemPrompt, $userPrompt, [
            'task'        => 'sarah_strategic_assessment',
            'agent_voice' => 'Sarah — Digital Marketing Manager',
        ], 250);

        if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
            return $result['parsed']['reasoning'] ?? $result['text'] ?? '';
        }

        return "Based on my analysis, I " . ($assessment['recommendation']['decision'] === 'proceed' ? "recommend proceeding with this plan." : "have concerns about this approach. Let me explain the risks before we proceed.");
    }
}

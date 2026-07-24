<?php

namespace App\Core\Projects;

use App\Connectors\RuntimeClient;
use App\Core\Brand\WorkspaceBrandKitResolver;
use Illuminate\Support\Facades\Log;

/**
 * SarahProjectPlanner — Sarah proposes KPIs + milestones for a Project.
 *
 * Grounded in:
 *   - Workspace industry + brand voice (WorkspaceBrandKitResolver — B2)
 *   - Goal text, duration, budget, channels
 *   - Sarah's engine_intelligence (industry_pattern rows seeded in B6)
 *
 * Routes through RuntimeClient per hands-vs-brain rule.
 *
 * Returns a PROPOSAL (does NOT persist). Frontend shows it for user
 * review/edit before submitting to MilestoneService + KpiService.
 */
class SarahProjectPlanner
{
    public function __construct(
        private readonly RuntimeClient $runtime,
        private readonly WorkspaceBrandKitResolver $brand,
    ) {}

    /**
     * Propose KPIs + milestones for a project.
     *
     * Required params:
     *   - goal (text)
     *
     * Optional:
     *   - duration_days (int, default 30)
     *   - budget_credits (int)
     *   - channels (array of engine slugs)
     *   - kpi_count (int 2-7, default 4)
     *   - milestone_count (int 2-7, default 5)
     *
     * Returns:
     *   ['success' => bool, 'proposed_kpis' => [...], 'proposed_milestones' => [...]]
     */
    public function proposeForProject(int $wsId, array $params): array
    {
        $goal = trim((string) ($params['goal'] ?? ''));
        if ($goal === '') {
            return ['success' => false, 'error' => 'goal is required'];
        }

        $kit = $this->brand->resolve($wsId);
        $industry      = $kit['industry']        ?? 'general SMB';
        $brandName     = $kit['brand_name']      ?? 'this business';
        $audience      = $kit['target_audience'] ?? 'small business customers';
        $voice         = $kit['voice']           ?? 'professional';
        $isNeutral     = !empty($kit['is_neutral']);

        $durationDays  = max(7, min(365, (int) ($params['duration_days']  ?? 30)));
        $budgetCredits = (int) ($params['budget_credits'] ?? 0);
        $channels      = $params['channels'] ?? [];
        $kpiCount      = max(2, min(7, (int) ($params['kpi_count']       ?? 4)));
        $msCount       = max(2, min(7, (int) ($params['milestone_count'] ?? 5)));

        $channelLine = is_array($channels) && !empty($channels)
            ? "Channels available: " . implode(', ', $channels)
            : "Channels available: all standard (write, social, marketing, studio, crm)";

        $budgetLine = $budgetCredits > 0
            ? "Credit budget: {$budgetCredits} credits over {$durationDays} days"
            : "Credit budget: not specified — propose realistic scope";

        $industryNote = $isNeutral
            ? "Industry: not specified — use cautious, broadly-applicable targets"
            : "Industry: {$industry}. Audience: {$audience}. Brand voice: {$voice}.";

        // Build the user prompt. The system prompt is hardcoded by the
        // runtime task type, so we put everything in the user prompt.
        $userPrompt = "You are Sarah, an experienced Digital Marketing Manager with deep industry expertise. "
            . "Propose realistic KPIs and milestones for this project so the user can track success.\n\n"
            . "Brand: {$brandName}\n"
            . "{$industryNote}\n"
            . "{$channelLine}\n"
            . "{$budgetLine}\n"
            . "Duration: {$durationDays} days\n"
            . "Goal: {$goal}\n\n"
            . "Propose {$kpiCount} KPIs and {$msCount} milestones. Targets must be realistic for this industry and timeframe — not aspirational. "
            . "For each item, include a one-sentence rationale grounded in industry experience.\n\n"
            . "Return ONLY valid JSON in this exact shape:\n"
            . '{"kpis":[{"name":"...","target_value":N,"unit":"...","direction":"higher_is_better|lower_is_better","rationale":"..."}],'
            . '"milestones":[{"title":"...","target_date_offset_days":N,"success_criteria":"...","rationale":"..."}]}';

        $context = array_filter([
            'industry'         => $industry,
            'duration_days'    => $durationDays,
            'budget_credits'   => $budgetCredits > 0 ? $budgetCredits : null,
            'channels'         => is_array($channels) ? implode(',', $channels) : null,
        ], fn($v) => $v !== null && $v !== '');

        // Route through runtime per hands-vs-brain
        $result = $this->runtime->aiRun('email_generation', $userPrompt, $context, 1200);

        if (empty($result['success']) || empty($result['text'])) {
            Log::warning('[SarahProjectPlanner] runtime returned no usable response', [
                'workspace_id' => $wsId,
                'error' => $result['error'] ?? null,
            ]);
            return [
                'success' => false,
                'error'   => 'Sarah could not produce a proposal right now',
                'detail'  => $result['error'] ?? 'no response',
            ];
        }

        $parsed = json_decode($this->cleanJsonResponse($result['text']), true);
        if (!is_array($parsed)) {
            Log::warning('[SarahProjectPlanner] JSON parse failed', [
                'workspace_id' => $wsId,
                'snippet' => substr($result['text'], 0, 240),
            ]);
            return [
                'success' => false,
                'error'   => 'Sarah produced a response we could not parse',
                'raw'     => substr($result['text'], 0, 400),
            ];
        }

        $kpis       = $this->validateKpiList($parsed['kpis']       ?? []);
        $milestones = $this->validateMilestoneList($parsed['milestones'] ?? []);

        return [
            'success'              => true,
            'proposed_kpis'        => $kpis,
            'proposed_milestones'  => $milestones,
            'context_used' => [
                'industry'       => $industry,
                'is_neutral'     => $isNeutral,
                'duration_days'  => $durationDays,
                'budget_credits' => $budgetCredits,
            ],
        ];
    }

    /**
     * Some LLMs wrap JSON in ```json ... ``` fences. Strip them.
     */
    private function cleanJsonResponse(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            // strip opening fence (possibly with language tag)
            $raw = preg_replace('/^```\w*\s*/', '', $raw, 1);
            // strip closing fence
            $raw = preg_replace('/\s*```\s*$/', '', $raw, 1);
        }
        return trim((string) $raw);
    }

    private function validateKpiList($list): array
    {
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $k) {
            if (!is_array($k)) continue;
            $name   = trim((string) ($k['name'] ?? ''));
            $target = $k['target_value'] ?? null;
            if ($name === '' || $target === null || !is_numeric($target)) continue;
            $direction = (string) ($k['direction'] ?? 'higher_is_better');
            if (!in_array($direction, ['higher_is_better', 'lower_is_better'], true)) {
                $direction = 'higher_is_better';
            }
            $out[] = [
                'name'         => mb_substr($name, 0, 200),
                'target_value' => (float) $target,
                'unit'         => mb_substr((string) ($k['unit'] ?? ''), 0, 40),
                'direction'    => $direction,
                'rationale'    => mb_substr((string) ($k['rationale'] ?? ''), 0, 400),
            ];
        }
        return $out;
    }

    private function validateMilestoneList($list): array
    {
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $m) {
            if (!is_array($m)) continue;
            $title  = trim((string) ($m['title'] ?? ''));
            $offset = $m['target_date_offset_days'] ?? null;
            if ($title === '' || $offset === null || !is_numeric($offset)) continue;
            $out[] = [
                'title'                   => mb_substr($title, 0, 240),
                'target_date_offset_days' => max(0, (int) $offset),
                'success_criteria'        => mb_substr((string) ($m['success_criteria'] ?? ''), 0, 500),
                'rationale'               => mb_substr((string) ($m['rationale'] ?? ''), 0, 400),
            ];
        }
        return $out;
    }
}
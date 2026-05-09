<?php

namespace App\Core\Intelligence;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StrategyLearningService — Sarah's outcomes journal.
 *
 * Each campaign / strategy execution can record a structured outcome.
 * The service scores the outcome heuristically, asks the runtime to
 * write a one-sentence "what to do differently next time" note, and
 * persists both. Future strategy meetings call getLearnings() to pull
 * the last 5 outcomes for the same strategy type.
 *
 * Patched 2026-05-10 (Phase 2I).
 */
class StrategyLearningService
{
    public function recordOutcome(
        int $wsId,
        string $strategyType,
        array $strategyData,
        array $outcomeData
    ): int {
        $score = $this->scoreOutcome($strategyType, $outcomeData);
        $notes = $this->generateNotes($strategyType, $outcomeData, $score);

        return DB::table('strategy_outcomes')->insertGetId([
            'workspace_id'  => $wsId,
            'strategy_type' => $strategyType,
            'strategy_data' => json_encode($strategyData, JSON_UNESCAPED_UNICODE),
            'outcome_data'  => json_encode($outcomeData, JSON_UNESCAPED_UNICODE),
            'success_score' => $score,
            'sarah_notes'   => $notes,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Returns a system-prompt block summarising the last 5 outcomes for
     * a strategy type in this workspace, plus the rolling average success
     * score. Empty string when there's nothing yet.
     */
    public function getLearnings(int $wsId, string $strategyType, int $limit = 5): string
    {
        $rows = DB::table('strategy_outcomes')
            ->where('workspace_id', $wsId)
            ->where('strategy_type', $strategyType)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['success_score', 'sarah_notes', 'created_at']);

        if ($rows->isEmpty()) return '';

        $avg = round((float)$rows->avg('success_score') * 100);
        $lines = ["STRATEGY LEARNINGS for `{$strategyType}` (rolling avg success: {$avg}%):"];
        foreach ($rows as $r) {
            if ($r->sarah_notes) $lines[] = '- ' . trim($r->sarah_notes);
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    public function listOutcomes(int $wsId, ?string $strategyType = null, int $limit = 50): array
    {
        $q = DB::table('strategy_outcomes')->where('workspace_id', $wsId);
        if ($strategyType) $q->where('strategy_type', $strategyType);
        return $q->orderByDesc('created_at')->limit($limit)->get()->all();
    }

    /**
     * Heuristic scoring per strategy type. Default is 0.5 when we cannot
     * tell. Each branch caps at [0..1] and is intentionally simple — the
     * intent is for the LLM-written notes to carry the nuance.
     */
    private function scoreOutcome(string $type, array $outcome): float
    {
        $val = match ($type) {
            'seo_campaign' => isset($outcome['rank_improvement'])
                ? min(1.0, (float)$outcome['rank_improvement'] / 10.0) : 0.50,
            'content_push' => isset($outcome['views'])
                ? min(1.0, (float)$outcome['views'] / 1000.0) : 0.50,
            'social_campaign' => isset($outcome['engagement_rate'])
                ? min(1.0, (float)$outcome['engagement_rate'] / 5.0) : 0.50,
            'email_campaign' => isset($outcome['click_through_rate'])
                ? min(1.0, (float)$outcome['click_through_rate'] / 5.0) : 0.50,
            default => 0.50,
        };
        return max(0.0, min(1.0, $val));
    }

    private function generateNotes(string $type, array $outcome, float $score): string
    {
        $runtime = app(RuntimeClient::class);
        if (!$runtime->isConfigured()) {
            return "Outcome score " . round($score * 100) . "% for {$type}. Runtime offline; no LLM note.";
        }

        $system = "You are Sarah, the Digital Marketing Manager. In ONE concise sentence (max 30 words), summarise what this strategy outcome teaches for future campaigns. Output JSON: {\"reply\":\"<one sentence>\"}.";
        $user = "Strategy type: {$type}\n"
              . "Heuristic score: " . round($score, 2) . "\n"
              . "Outcome data:\n" . json_encode($outcome, JSON_UNESCAPED_UNICODE);

        try {
            $r = $runtime->chatJson($system, $user, [], 200);
            if ($r['success'] ?? false) {
                $reply = $r['parsed']['reply'] ?? $r['text'] ?? '';
                if ($reply) return trim((string)$reply);
            }
        } catch (\Throwable $e) {
            Log::warning('StrategyLearningService::generateNotes failed: ' . $e->getMessage());
        }
        return "Outcome score " . round($score * 100) . "% — " . ucfirst($type) . " ran without a runtime note.";
    }
}

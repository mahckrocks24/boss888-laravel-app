<?php

namespace App\Core\Intelligence;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * Agent Experience System — each agent earns knowledge and expertise.
 *
 * Like a human employee, agents build their resume over time:
 *   - Tasks completed (total and per-engine)
 *   - Industries handled (and how many projects per industry)
 *   - Tokens used (LLM consumption)
 *   - Articles written, campaigns managed, leads processed
 *   - Success rate per task type
 *   - Time-to-completion averages
 *
 * Agents with more experience in an industry get higher confidence
 * in their recommendations for that industry.
 */
class AgentExperienceService
{
    /**
     * Record a completed task on an agent's profile.
     */
    public function recordTaskCompletion(int $agentId, string $engine, string $action, ?string $industry = null, array $metrics = []): void
    {
        // Increment total tasks
        $this->incrementStat($agentId, 'tasks_completed', null);
        $this->incrementStat($agentId, "tasks_{$engine}", null);

        // Increment industry experience
        if ($industry) {
            $this->incrementStat($agentId, 'industries_handled', $industry);
        }

        // Increment action-specific stats
        $actionMap = [
            'write_article' => 'articles_written',
            'create_article' => 'articles_written',
            'generate_image' => 'images_generated',
            'generate_video' => 'videos_generated',
            'create_campaign' => 'campaigns_managed',
            'social_create_post' => 'posts_created',
            'serp_analysis' => 'seo_analyses_completed',
            'deep_audit' => 'audits_completed',
            'create_lead' => 'leads_processed',
            'create_deal' => 'deals_managed',
            'ba_transform' => 'designs_created',
        ];

        if (isset($actionMap[$action])) {
            $this->incrementStat($agentId, $actionMap[$action], $industry);
        }

        // Record tokens used
        if (isset($metrics['tokens_used'])) {
            $this->incrementStat($agentId, 'tokens_used', null, $metrics['tokens_used']);
        }

        // Record success/failure
        if (isset($metrics['success']) && $metrics['success']) {
            $this->incrementStat($agentId, 'tasks_succeeded', null);
        } elseif (isset($metrics['success']) && !$metrics['success']) {
            $this->incrementStat($agentId, 'tasks_failed', null);
        }

        // Update monthly stats
        $this->incrementStat($agentId, 'tasks_completed', null, 1, 'monthly');
    }

    /**
     * Get full agent profile with experience data.
     * This is what shows on the agent's "resume" card in the UI.
     */
    public function getAgentProfile(int $agentId): array
    {
        $agent = Agent::find($agentId);
        if (!$agent) return [];

        $stats = DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('period', 'all_time')
            ->pluck('value_int', 'metric_key')
            ->toArray();

        $industries = DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('metric_key', 'industries_handled')
            ->whereNotNull('industry')
            ->orderByDesc('value_int')
            ->pluck('value_int', 'industry')
            ->toArray();

        $monthlyTasks = DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('metric_key', 'tasks_completed')
            ->where('period', 'monthly')
            ->value('value_int') ?? 0;

        $totalTasks = $stats['tasks_completed'] ?? 0;
        $succeeded = $stats['tasks_succeeded'] ?? 0;
        $successRate = $totalTasks > 0 ? round(($succeeded / $totalTasks) * 100, 1) : 0;

        return [
            'agent' => $agent,
            'total_tasks' => $totalTasks,
            'tasks_this_month' => $monthlyTasks,
            'success_rate' => $successRate,
            'tokens_used' => $stats['tokens_used'] ?? 0,
            'articles_written' => $stats['articles_written'] ?? 0,
            'images_generated' => $stats['images_generated'] ?? 0,
            'campaigns_managed' => $stats['campaigns_managed'] ?? 0,
            'posts_created' => $stats['posts_created'] ?? 0,
            'seo_analyses' => $stats['seo_analyses_completed'] ?? 0,
            'leads_processed' => $stats['leads_processed'] ?? 0,
            'industries' => $industries,
            'experience_level' => $this->calculateLevel($totalTasks),
            'specializations' => $this->getSpecializations($agentId),
        ];
    }

    /**
     * Get agent's expertise score for a specific industry.
     * Higher score = more experience = better recommendations.
     */
    public function getIndustryExpertise(int $agentId, string $industry): float
    {
        $stat = DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('metric_key', 'industries_handled')
            ->where('industry', $industry)
            ->first();

        if (!$stat) return 0;

        // Logarithmic scaling: 1 project = 0.2, 5 = 0.5, 20 = 0.8, 50+ = 0.95
        return round(min(0.95, log($stat->value_int + 1) / log(60)), 2);
    }

    /**
     * Build experience context for agent reasoning prompts.
     */
    public function buildExperienceContext(int $agentId, ?string $industry = null): string
    {
        $profile = $this->getAgentProfile($agentId);
        if (empty($profile['agent'])) return '';

        $lines = ["Your experience profile:"];
        $lines[] = "- Experience level: {$profile['experience_level']}";
        $lines[] = "- Tasks completed: {$profile['total_tasks']} (success rate: {$profile['success_rate']}%)";

        if (!empty($profile['industries'])) {
            $top = array_slice($profile['industries'], 0, 5, true);
            $lines[] = "- Top industries: " . implode(', ', array_map(fn($ind, $cnt) => "{$ind} ({$cnt} projects)", array_keys($top), $top));
        }

        if ($industry) {
            $expertise = $this->getIndustryExpertise($agentId, $industry);
            $lines[] = "- Your expertise in {$industry}: " . round($expertise * 100) . "%";
        }

        $lines[] = "- Tokens used: " . number_format($profile['tokens_used']);

        return implode("\n", $lines);
    }

    /**
     * Store agent memory for a specific workspace.
     * This is project-specific knowledge that NEVER leaks to other workspaces.
     */
    public function storeMemory(int $wsId, int $agentId, string $type, string $key, string $value): void
    {
        DB::table('agent_workspace_memory')->updateOrInsert(
            ['workspace_id' => $wsId, 'agent_id' => $agentId, 'key' => $key],
            ['memory_type' => $type, 'value' => $value, 'access_count' => DB::raw('access_count + 1'),
             'last_accessed_at' => now(), 'updated_at' => now(), 'created_at' => DB::raw('IFNULL(created_at, NOW())')]
        );
    }

    /**
     * Recall agent's workspace-specific memories.
     */
    public function recallMemories(int $wsId, int $agentId, ?string $type = null, int $limit = 20): array
    {
        // Phase 3 fix: agent_workspace_memory table is a Phase 0.9 deferred item
        // that doesn't exist yet. Wrap the query in try/catch so meetings and agent
        // reasoning calls don't crash when the table is absent. Returns empty array
        // gracefully — the agent proceeds without workspace memories.
        try {
            $q = DB::table('agent_workspace_memory')
                ->where('workspace_id', $wsId)
                ->where('agent_id', $agentId);

            if ($type) $q->where('memory_type', $type);

            $memories = $q->orderByDesc('relevance_score')
                ->orderByDesc('last_accessed_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        // Update access count
        foreach ($memories as $m) {
            DB::table('agent_workspace_memory')->where('id', $m->id)
                ->update(['access_count' => DB::raw('access_count + 1'), 'last_accessed_at' => now()]);
        }

        return $memories->toArray();
    }

    /**
     * Build workspace-specific memory context for agent prompts.
     */
    public function buildMemoryContext(int $wsId, int $agentId): string
    {
        $memories = $this->recallMemories($wsId, $agentId, null, 10);
        if (empty($memories)) return '';

        $lines = ["Your memories about this workspace:"];
        foreach ($memories as $m) {
            $lines[] = "- [{$m->memory_type}] {$m->key}: {$m->value}";
        }

        return implode("\n", $lines);
    }

    // ── Private ──────────────────────────────────────────

    private function incrementStat(int $agentId, string $key, ?string $industry = null, int $amount = 1, string $period = 'all_time'): void
    {
        $existing = DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('metric_key', $key)
            ->where('industry', $industry)
            ->where('period', $period)
            ->first();

        if ($existing) {
            DB::table('agent_experience_stats')->where('id', $existing->id)
                ->update(['value_int' => DB::raw("value_int + {$amount}"), 'updated_at' => now()]);
        } else {
            DB::table('agent_experience_stats')->insert([
                'agent_id' => $agentId, 'metric_key' => $key, 'industry' => $industry,
                'period' => $period, 'value_int' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function calculateLevel(int $tasks): string
    {
        if ($tasks >= 500) return 'Expert';
        if ($tasks >= 200) return 'Senior';
        if ($tasks >= 50) return 'Intermediate';
        if ($tasks >= 10) return 'Junior';
        return 'Novice';
    }

    private function getSpecializations(int $agentId): array
    {
        // Find engines where this agent has the most experience
        return DB::table('agent_experience_stats')
            ->where('agent_id', $agentId)
            ->where('metric_key', 'like', 'tasks_%')
            ->where('metric_key', '!=', 'tasks_completed')
            ->where('metric_key', '!=', 'tasks_succeeded')
            ->where('metric_key', '!=', 'tasks_failed')
            ->where('period', 'all_time')
            ->orderByDesc('value_int')
            ->limit(3)
            ->pluck('value_int', 'metric_key')
            ->toArray();
    }
}

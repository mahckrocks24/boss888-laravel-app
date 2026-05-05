<?php

namespace App\Core\PlanGating;

use App\Models\Plan;
use App\Models\Workspace;
use App\Models\Subscription;

class PlanGatingService
{
    /**
     * Check if a workspace can perform a specific action.
     * Returns [allowed => bool, reason => string]
     */
    public function check(int $workspaceId, string $action, array $context = []): array
    {
        $plan = $this->getActivePlan($workspaceId);
        if (! $plan) {
            return $this->deny('No active subscription');
        }

        $features = $plan->features_json ?? [];

        // 1. AI access gating
        if ($this->isAiAction($action)) {
            if ($plan->ai_access === 'none') {
                return $this->deny('AI features require AI Lite plan or above');
            }
        }

        // 2. AI Lite restrictions — research only, no generation, no agents
        if ($plan->ai_access === 'research') {
            if ($this->isGenerationAction($action)) {
                return $this->deny('Content/image/video generation requires Growth plan or above');
            }
            if ($this->isAgentAction($action)) {
                return $this->deny('AI agents require Growth plan or above');
            }
        }

        // 2b. Video generation — Pro+ only (Growth can generate images, not video)
        if (in_array($action, ['generate_video', 'create_scene_plan', 'stitch_video'])) {
            if (!$plan->companion_app) {  // companion_app = Pro+ flag
                return $this->deny('Video generation requires Pro plan or above');
            }
        }

        // 2c. APP888 companion app — Pro+ only
        if (($context['exec_api'] ?? false) && !$plan->companion_app) {
            return $this->deny('Mobile companion app requires Pro plan or above');
        }

        // 3. Feature-specific gating
        $featureMap = [
            'marketing' => ['create_campaign', 'send_campaign', 'schedule_campaign', 'create_automation'],
            'social' => ['social_create_post', 'social_publish_post', 'social_schedule_post'],
            'automation' => ['create_automation', 'trigger_automation'],
            'content_writing' => ['write_article', 'improve_draft', 'generate_outline', 'generate_headlines', 'generate_meta'],
            'image_generation' => ['generate_image', 'edit_image', 'upscale_image', 'remove_background'],
            'video_generation' => ['generate_video', 'create_scene_plan', 'stitch_video'],
        ];

        foreach ($featureMap as $feature => $actions) {
            if (in_array($action, $actions) && empty($features[$feature])) {
                return $this->deny("'{$action}' requires a plan with {$feature} access");
            }
        }

        // 4. Website limit
        if ($action === 'create_website') {
            $currentCount = \DB::table('websites')
                ->where('workspace_id', $workspaceId)
                ->where('status', '!=', 'deleted')
                ->count();
            if ($currentCount >= $plan->max_websites) {
                return $this->deny("Website limit reached ({$plan->max_websites} on {$plan->name} plan)");
            }
        }

        // 5. Agent access gating
        if ($this->isAgentAction($action) && ! $plan->includes_dmm) {
            return $this->deny('AI agents require Growth plan or above');
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Get the plan rules for a workspace (for frontend display).
     */
    public function getPlanRules(int $workspaceId): array
    {
        $plan = $this->getActivePlan($workspaceId);
        if (! $plan) {
            return $this->defaultRules();
        }

        return [
            'plan_name' => $plan->name,
            'plan_slug' => $plan->slug,
            'ai_access' => $plan->ai_access,
            'includes_dmm' => (bool) $plan->includes_dmm,
            'agent_count' => $plan->agent_count,
            'agent_level' => $plan->agent_level,
            'agent_addon_price' => $plan->agent_addon_price,
            'max_websites' => $plan->max_websites,
            'max_team_members' => $plan->max_team_members,
            'companion_app' => (bool) $plan->companion_app,
            'white_label' => (bool) $plan->white_label,
            'priority_processing' => (bool) $plan->priority_processing,
            'credit_limit' => $plan->credit_limit,
            'features' => $plan->features_json ?? [],
        ];
    }

    /**
     * Check if a workspace can use a specific agent.
     */
    public function canUseAgent(int $workspaceId, string $agentSlug): array
    {
        $plan = $this->getActivePlan($workspaceId);
        if (! $plan || ! $plan->includes_dmm) {
            return $this->deny('AI agents require Growth plan or above');
        }

        // Sarah (DMM) always allowed on AI plans
        if ($agentSlug === 'sarah') {
            return ['allowed' => true, 'reason' => null];
        }

        // Check if agent is in workspace's selected team
        $selectedAgents = \DB::table('workspace_agents')
            ->where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->pluck('agent_id');

        $agent = \App\Models\Agent::where('slug', $agentSlug)->first();
        if (! $agent || ! $selectedAgents->contains($agent->id)) {
            return $this->deny("Agent '{$agentSlug}' is not on your team. Select them in your workspace settings.");
        }

        return ['allowed' => true, 'reason' => null];
    }

    // ── Private helpers ──────────────────────────────────────

    private function getActivePlan(int $workspaceId): ?Plan
    {
        $subscription = Subscription::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->latest()
            ->first();

        if ($subscription) {
            return Plan::find($subscription->plan_id);
        }

        // Default to free plan
        return Plan::where('slug', 'free')->first();
    }

    private function isAiAction(string $action): bool
    {
        $aiActions = [
            'write_article', 'improve_draft', 'generate_outline', 'generate_headlines', 'generate_meta',
            'generate_image', 'generate_video', 'edit_image', 'upscale_image', 'remove_background',
            'create_scene_plan', 'stitch_video', 'generate_variations',
            'serp_analysis', 'ai_report', 'deep_audit', 'autonomous_goal',
            'ai_research', 'ai_brainstorm',
        ];
        return in_array($action, $aiActions);
    }

    private function isGenerationAction(string $action): bool
    {
        return in_array($action, [
            'write_article', 'improve_draft', 'generate_outline', 'generate_headlines',
            'generate_image', 'generate_video', 'edit_image', 'generate_variations',
            'create_scene_plan', 'stitch_video', 'social_create_post',
        ]);
    }

    private function isAgentAction(string $action): bool
    {
        return in_array($action, [
            'autonomous_goal', 'list_goals', 'agent_status', 'pause_goal',
            'strategy_planning', 'campaign_oversight', 'task_delegation',
        ]);
    }

    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    private function defaultRules(): array
    {
        return [
            'plan_name' => 'Free', 'plan_slug' => 'free',
            'ai_access' => 'none', 'includes_dmm' => false,
            'agent_count' => 0, 'agent_level' => null,
            'agent_addon_price' => null, 'max_websites' => 1,
            'max_team_members' => 1, 'companion_app' => false,
            'white_label' => false, 'priority_processing' => false,
            'credit_limit' => 0, 'features' => [],
        ];
    }

    /**
     * Convenience method for EngineExecutionService.
     * Wraps check() with engine/action context.
     */
    public function canExecute(int $workspaceId, string $engine, string $action): array
    {
        $result = $this->check($workspaceId, $action, ['engine' => $engine]);
        return $result;
    }
}

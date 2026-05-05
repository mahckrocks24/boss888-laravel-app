<?php

namespace App\Core\Billing;

use App\Core\PlanGating\PlanGatingService;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * FeatureGateService
 *
 * Produces the structured capability map that the frontend uses to
 * conditionally render features, show upgrade prompts, and block actions.
 *
 * Also used by PlanMiddleware for route-level gating.
 *
 * Source of truth for all plan-based feature restrictions:
 *   Free / Starter   — no AI, no agents, manual CRUD only
 *   AI Lite          — Sarah research pipeline only, no generation, no agents
 *   Growth           — full AI, images, 2 agents, Sarah, 3 sites
 *   Pro              — full AI, images + video, 5 agents, APP888, 10 sites
 *   Agency           — everything, 10 agents, 50 sites, white-label
 */
class FeatureGateService
{
    public function __construct(
        private PlanGatingService $planGating,
    ) {}

    // ── Trial awareness helper ───────────────────────────────────────────────
    // HIGH-01 FIX: TrialService is resolved lazily via app() to prevent circular
    // dependency. Both services are singletons — constructor injection would cause
    // FeatureGateService → TrialService → (nothing back) but the singleton container
    // resolves both during boot which can deadlock under certain load patterns.
    private function trialStatus(int $wsId): array
    {
        try {
            // Lazy resolution — not injected in constructor
            return app(\App\Core\Billing\TrialService::class)->getTrialStatus($wsId);
        } catch (\Throwable) {
            return ['has_trial' => false, 'active' => false, 'days_remaining' => 0, 'eligible' => true];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // CAPABILITY MAP — the primary method
    // ═══════════════════════════════════════════════════════════

    /**
     * Return the full capability map for a workspace.
     * Used by GET /workspace/capabilities and PlanMiddleware.
     */
    public function getCapabilities(int $wsId): array
    {
        $plan = $this->getActivePlan($wsId);

        if (!$plan) {
            return $this->freeCapabilities($wsId);
        }

        $slug     = $plan->slug;
        $hasAI    = in_array($plan->ai_access, ['research', 'full']);
        $hasFullAI= $plan->ai_access === 'full';
        $hasDMM   = (bool) $plan->includes_dmm;
        $hasApp   = (bool) $plan->companion_app;
        $hasWhiteLabel = (bool) $plan->white_label;

        // Agent quota
        $agentCount   = (int) ($plan->agent_count ?? 0);
        $agentQuota   = $this->getAgentQuotaStatus($wsId, $agentCount);

        // Site quota
        $maxSites     = (int) ($plan->max_websites ?? 1);
        $siteQuota    = $this->getSiteQuotaStatus($wsId, $maxSites);

        // Team quota
        $maxTeam      = (int) ($plan->max_team_members ?? 1);
        $teamCount    = $this->getTeamCount($wsId);

        return [
            // Plan info
            'plan'     => $slug,
            'plan_name'=> $plan->name,
            'credits'  => $this->getCreditInfo($wsId, $plan),

            // Engine access — all plans get manual tools
            'engines'  => [
                'crm'          => ['manual' => true,  'ai' => $hasFullAI, 'agents' => $hasDMM],
                'seo'          => ['manual' => true,  'ai' => $hasAI,     'agents' => $hasDMM],
                'write'        => ['manual' => true,  'ai' => $hasFullAI, 'agents' => $hasDMM],
                'creative'     => [
                    'manual'   => true,
                    'image'    => $hasFullAI,
                    'video'    => $hasApp,      // Pro+ only
                    'blueprint'=> $hasFullAI,
                ],
                'marketing'    => ['manual' => true,  'ai' => $hasFullAI, 'agents' => $hasDMM],
                'social'       => ['manual' => true,  'ai' => $hasFullAI, 'agents' => $hasDMM],
                'builder'      => ['manual' => true,  'ai' => $hasFullAI, 'agents' => $hasDMM],
                'calendar'     => ['manual' => true,  'ai' => false,      'agents' => false],
                'beforeafter'  => ['manual' => true,  'ai' => $hasFullAI, 'agents' => false],
                'traffic'      => ['manual' => true,  'ai' => false,      'agents' => false],
                'manualedit'   => ['manual' => true,  'ai' => $hasFullAI, 'agents' => false],
            ],

            // Agent capabilities
            'agents' => [
                'dispatch'       => $hasDMM,
                'sarah_included' => $hasDMM,
                'quota_total'    => $agentCount,
                'quota_used'     => $agentQuota['used'],
                'quota_remaining'=> $agentQuota['remaining'],
                'quota_reached'  => $agentQuota['reached'],
                'addon_available'=> $plan->agent_addon_price !== null,
                'addon_price'    => $plan->agent_addon_price,
                'level'          => $plan->agent_level,
            ],

            // Sites
            'sites' => [
                'quota'     => $maxSites,
                'used'      => $siteQuota['used'],
                'remaining' => $siteQuota['remaining'],
                'reached'   => $siteQuota['reached'],
            ],

            // Team
            'team' => [
                'quota'     => $maxTeam,
                'used'      => $teamCount,
                'remaining' => max(0, $maxTeam - $teamCount),
                'reached'   => $teamCount >= $maxTeam,
                'unlimited' => $maxTeam >= 999,
            ],

            // Platform features
            // SEO-only product mode 2026-05-01: seo_only added to custom_domain allowlist
            // (WP site has its own domain, so feature is meaningful) but NOT to
            // team_management / api_access / advanced_analytics (single-user product).
            'features' => [
                'app888'           => $hasApp,
                'white_label'      => $hasWhiteLabel,
                'priority_queue'   => (bool) $plan->priority_processing,
                'custom_domain'    => in_array($slug, ['starter', 'ai-lite', 'growth', 'pro', 'agency', 'seo_only']),
                'api_access'       => in_array($slug, ['pro', 'agency']),
                'team_management'  => in_array($slug, ['starter', 'ai-lite', 'growth', 'pro', 'agency']),
                'advanced_analytics'=> in_array($slug, ['pro', 'agency']),
            ],

            // SEO-only product mode 2026-05-01: surface workspace mode + raw
            // features_json so the frontend can drive sidebar visibility from a
            // single source of truth (`mode` + presence of feature flag).
            'mode'             => $this->getWorkspaceMode($wsId),
            'features_json'    => is_array($plan->features_json ?? null) ? $plan->features_json : [],

            // CHATBOT888 2026-05-02 — entitlement + quota for the SPA chatbot UI.
            'chatbot' => [
                'enabled'           => $this->canAccessChatbot($wsId),
                'addon_eligible'    => $this->chatbotAddonEligible($wsId),
                'kb_doc_limit'      => $this->chatbotKbDocLimit($wsId),
                'messages_quota'    => $this->chatbotMessagesQuota($wsId),
                'messages_used'     => $this->chatbotMessagesUsed($wsId),
            ],

            // Upgrade suggestion — which plan to upsell to
            'upgrade_to' => $this->upgradeTarget($slug),

            // Trial info
            'trial' => $this->trialStatus($wsId),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // INDIVIDUAL GATE CHECKS (used by middleware + controllers)
    // ═══════════════════════════════════════════════════════════

    public function canUseEngine(int $wsId, string $engine): bool
    {
        // All engines available for manual use on all plans
        // AI features within engines are gated by canUseAI()
        return true;
    }

    public function canUseAI(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        return $plan && in_array($plan->ai_access, ['research', 'full']);
    }

    /**
     * Can this workspace's plan use the WordPress SEO connector?
     *
     * Source-of-truth precedence:
     *   1. plans.features_json['seo_connector'] === true|false (explicit override)
     *   2. plans.ai_access !== 'none' (default — any plan with AI capability)
     *
     * Why this design (P0 hardening 2026-05-01): the runbook required avoiding
     * scattered hardcoded slug arrays. By using ai_access (which already exists
     * and is the cleanest gate for "is this plan paid + AI-capable"), all paid
     * plans pass automatically and `free` (ai_access='none') fails. Future
     * `seo_only` plan with `ai_access='research'` will pass automatically too.
     * Per-plan overrides are still possible via features_json['seo_connector'].
     */
    public function canUseSeoConnector(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) {
            return false;
        }
        // Explicit override on the plan row wins.
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        if (array_key_exists('seo_connector', $features)) {
            return (bool) $features['seo_connector'];
        }
        // Default: any plan with non-'none' ai_access is entitled.
        return in_array($plan->ai_access, ['research', 'full'], true);
    }

    /**
     * Resolve the active plan for a workspace publicly so middleware can
     * surface plan slug/name in error responses without re-querying.
     */
    public function getActivePlanFor(int $wsId): ?Plan
    {
        return $this->getActivePlan($wsId);
    }

    // ═══════════════════════════════════════════════════════════
    // SEO-ONLY PRODUCT MODE — 2026-05-01
    // ═══════════════════════════════════════════════════════════

    /**
     * Resolve the workspace's product mode.
     *
     * Source of truth: plans.features_json['mode'] on the active plan row.
     * Returns 'seo' for the seo_only product, 'full' for everything else
     * (including unsubscribed → free defaulting).
     *
     * Per the runbook design call (Q1): no workspaces.mode column. Mode is a
     * derived view of the active plan and changes the moment the subscription
     * changes — Stripe webhook → new plan → new mode. No backfill required.
     */
    public function getWorkspaceMode(int $wsId): string
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) return 'full';
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        $mode = $features['mode'] ?? 'full';
        return in_array($mode, ['seo', 'full'], true) ? $mode : 'full';
    }

    /**
     * Generic features_json reader. Honours an explicit boolean override on
     * the plan row; falls back to a column-derived default supplied by the
     * caller. Used by all canAccess*() helpers below so we have ONE place
     * that interprets features_json.
     */
    private function featureFlag(int $wsId, string $key, bool $default): bool
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) return $default && false;  // no plan == strictest default
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        if (array_key_exists($key, $features)) {
            return (bool) $features[$key];
        }
        return $default;
    }

    public function canAccessSeoEngine(int $wsId): bool
    {
        // Existing key 'seo_suite' is the canonical flag (do NOT rename).
        return $this->featureFlag($wsId, 'seo_suite', true);
    }

    public function canAccessSeoConnector(int $wsId): bool
    {
        // Delegate to the canonical method (P0 hardening already wired this).
        return $this->canUseSeoConnector($wsId);
    }

    public function canAccessBuilder(int $wsId): bool
    {
        // Existing key 'website_builder' (do NOT rename).
        return $this->featureFlag($wsId, 'website_builder', true);
    }

    public function canAccessCrm(int $wsId): bool
    {
        return $this->featureFlag($wsId, 'crm', true);
    }

    public function canAccessSocial(int $wsId): bool
    {
        return $this->featureFlag($wsId, 'social', false);
    }

    public function canAccessMarketing(int $wsId): bool
    {
        return $this->featureFlag($wsId, 'marketing', false);
    }

    public function canAccessCalendar(int $wsId): bool
    {
        return $this->featureFlag($wsId, 'calendar', true);
    }

    public function canAccessAutomation(int $wsId): bool
    {
        return $this->featureFlag($wsId, 'automation', false);
    }

    public function canAccessAgents(int $wsId): bool
    {
        // Existing key 'ai_agents' (do NOT rename).
        return $this->featureFlag($wsId, 'ai_agents', false);
    }

    public function canAccessSarah(int $wsId): bool
    {
        // Sarah is the DMM agent. Plan column `includes_dmm` is canonical.
        $plan = $this->getActivePlan($wsId);
        return $plan && (bool) $plan->includes_dmm;
    }

    public function canAccessStrategyRoom(int $wsId): bool
    {
        // Existing key 'meeting_room' (do NOT rename).
        return $this->featureFlag($wsId, 'meeting_room', false);
    }

    // ═══════════════════════════════════════════════════════════
    // CHATBOT888 — 2026-05-02
    // ═══════════════════════════════════════════════════════════

    /**
     * Top-level entitlement gate for Chatbot888.
     *
     * Three paths to YES:
     *   1. plans.features_json.chatbot_included === true (pro/agency/seo_only)
     *   2. plans.price >= 199 (safety fallback if features_json missing the key)
     *   3. subscriptions.chatbot_addon_item_id is non-null (add-on purchased)
     */
    public function canAccessChatbot(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) return false;

        // Path 1 — features_json explicit flag
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        if (! empty($features['chatbot_included'])) return true;

        // Path 2 — price-based safety net for new plans without the flag set
        if ((float) $plan->price >= 199.0) return true;

        // Path 3 — add-on purchased on the active subscription
        $sub = \App\Models\Subscription::where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()->first();
        return $sub && ! empty($sub->chatbot_addon_item_id);
    }

    public function canUseChatbotBooking(int $wsId): bool
    {
        return $this->canAccessChatbot($wsId);
    }

    public function canUseChatbotCrmCapture(int $wsId): bool
    {
        return $this->canAccessChatbot($wsId);
    }

    public function canUseChatbotKnowledgeBase(int $wsId): bool
    {
        return $this->canAccessChatbot($wsId);
    }

    public function chatbotKbDocLimit(int $wsId): int
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) return 0;
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        return (int) ($features['chatbot_kb_max_docs'] ?? 0);
    }

    public function chatbotMessagesQuota(int $wsId): int
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) return 0;
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        return (int) ($features['chatbot_messages_per_month'] ?? 0);
    }

    public function chatbotMessagesUsed(int $wsId): int
    {
        $month = now()->format('Ym');
        return (int) DB::table('chatbot_usage_logs')
            ->where('workspace_id', $wsId)
            ->where('month_yyyymm', $month)
            ->value('messages_count') ?: 0;
    }

    public function chatbotAddonEligible(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        if (! $plan) return false;
        $features = is_array($plan->features_json ?? null) ? $plan->features_json : [];
        return (bool) ($features['chatbot_addon_eligible'] ?? false);
    }

    public function canDispatchAgent(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        return $plan && (bool) $plan->includes_dmm;
    }

    public function canUseVideo(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        return $plan && (bool) $plan->companion_app;  // Pro+ flag
    }

    public function canUseApp888(int $wsId): bool
    {
        return $this->canUseVideo($wsId);  // same gate: Pro+
    }

    public function agentQuotaReached(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        if (!$plan) return true;
        $max  = (int) ($plan->agent_count ?? 0);
        return $this->getAgentQuotaStatus($wsId, $max)['reached'];
    }

    public function siteQuotaReached(int $wsId): bool
    {
        $plan = $this->getActivePlan($wsId);
        if (!$plan) return true;
        $max  = (int) ($plan->max_websites ?? 1);
        return $this->getSiteQuotaStatus($wsId, $max)['reached'];
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════════

    private function getActivePlan(int $wsId): ?Plan
    {
        // SEO-only product mode 2026-05-01 (Q2 design call): treat 'trialing'
        // as equivalent to 'active'. Stripe enrols every new sub in a 3-day
        // trial (`trial_period_days=3` in createCheckoutSession), so without
        // this fix every paid customer is blocked from connector + AI for the
        // first 3 days post-signup. This was a pre-existing latent bug that
        // seo_only would have exposed immediately — fixed now.
        $sub = Subscription::where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if ($sub) return Plan::find($sub->plan_id);

        return Plan::where('slug', 'free')->first();
    }

    private function getCreditInfo(int $wsId, Plan $plan): array
    {
        $credit = DB::table('credits')->where('workspace_id', $wsId)->first();
        return [
            'balance'          => (int) ($credit?->balance ?? 0),
            'reserved'         => (int) ($credit?->reserved_balance ?? 0),
            'available'        => max(0, (int)($credit?->balance ?? 0) - (int)($credit?->reserved_balance ?? 0)),
            'monthly_allowance'=> (int) ($plan->credit_limit ?? 0),
        ];
    }

    private function getAgentQuotaStatus(int $wsId, int $max): array
    {
        $used = DB::table('workspace_agents')
            ->where('workspace_id', $wsId)
            ->where('enabled', true)
            ->count();

        return [
            'used'      => $used,
            'remaining' => max(0, $max - $used),
            'reached'   => $used >= $max,
        ];
    }

    private function getSiteQuotaStatus(int $wsId, int $max): array
    {
        $used = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->count();

        return [
            'used'      => $used,
            'remaining' => max(0, $max - $used),
            'reached'   => $used >= $max,
        ];
    }

    private function getTeamCount(int $wsId): int
    {
        return DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->count();
    }

    private function upgradeTarget(?string $currentSlug): ?string
    {
        return match ($currentSlug) {
            'free'    => 'starter',
            'starter' => 'ai-lite',
            'ai-lite' => 'growth',
            'growth'  => 'pro',
            'pro'     => 'agency',
            'agency'  => null,
            default   => 'growth',
        };
    }

    private function freeCapabilities(int $wsId): array
    {
        $siteQuota = $this->getSiteQuotaStatus($wsId, 1);
        return [
            'plan' => 'free', 'plan_name' => 'Free',
            'credits' => ['balance' => 0, 'reserved' => 0, 'available' => 0, 'monthly_allowance' => 0],
            'engines' => array_fill_keys(
                ['crm','seo','write','creative','marketing','social','builder','calendar','beforeafter','traffic','manualedit'],
                ['manual' => true, 'ai' => false, 'agents' => false]
            ),
            'agents' => ['dispatch' => false, 'sarah_included' => false, 'quota_total' => 0, 'quota_used' => 0, 'quota_remaining' => 0, 'quota_reached' => true, 'addon_available' => false, 'addon_price' => null, 'level' => null],
            'sites'  => array_merge($siteQuota, ['quota' => 1]),
            'team'   => ['quota' => 1, 'used' => 1, 'remaining' => 0, 'reached' => true, 'unlimited' => false],
            'features'=> ['app888' => false, 'white_label' => false, 'priority_queue' => false, 'custom_domain' => false, 'api_access' => false, 'team_management' => false, 'advanced_analytics' => false],
            'upgrade_to' => 'starter',
        ];
    }
}

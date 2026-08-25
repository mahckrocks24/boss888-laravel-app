<?php

namespace App\Core\LaunchScope;

/**
 * LaunchScopePolicy — the SINGLE authoritative source of truth for what is
 * in scope for the LevelUp Growth launch.
 *
 * Created 2026-07-20 per the launch-scope remediation (audit
 * boss888-audit/LAUNCH-SCOPE-REMOVAL-AUDIT-2026-07-20.md, verdict SAFE WITH
 * CONDITIONS). Social automation, social intelligence, and customer-facing
 * email marketing are REMOVED from launch. This class is the one place that
 * decision is encoded; every enforcement point (kernel, cron, job, route,
 * agent registry, quick-actions) reads from HERE so the boundary can never
 * drift between layers.
 *
 * DESIGN: deny-by-name for removed actions/engines/agents, with a NARROW
 * allowlist for the retained blog-article-sharing exception (which reuses the
 * Social engine — see audit Deliverable 5). The retained exception is only
 * permitted when the call is genuinely an article share (context/param marker),
 * never for standalone social composing.
 */
class LaunchScopePolicy
{
    /**
     * Engines whose CUSTOMER-FACING surface is removed from launch.
     * NOTE: 'social' and 'marketing' are NOT blanket-removed here because:
     *   - social retains the blog-article-share sliver (see RETAINED_SOCIAL_ACTIONS)
     *   - marketing engine also hosts transactional-adjacent helpers; email
     *     MARKETING actions are denied individually in REMOVED_ACTIONS.
     * 'mention' (social listening/sentiment) is fully removed.
     */
    public const REMOVED_ENGINES = ['mention'];

    /**
     * Individual actions removed from launch, keyed by engine. An action here
     * is refused before any dispatch/credit/provider/job/notification.
     * (Aliases and historical names included — see resolveAliases().)
     */
    public const REMOVED_ACTIONS = [
        // ── Email marketing (entire customer-facing suite) ──
        'marketing' => [
            'create_campaign', 'update_campaign', 'delete_campaign', 'schedule_campaign',
            'send_campaign', 'create_automation', 'toggle_automation', 'run_automation',
            'ai_campaign_copy',
            'email_ai_generate', 'marketing_email_ai_generate',
            'email_block_rewrite', 'marketing_email_block_rewrite',
            'email_subject_suggest', 'marketing_email_subject_suggest',
            'email_spam_check', 'marketing_email_spam_check',
            'email_preview_template', 'marketing_email_preview_template',
            'email_send_test', 'marketing_email_send_test',
            'email_validate_campaign', 'marketing_email_validate_campaign',
            'email_use_template', 'marketing_email_use_template',
            'email_template_picker', 'marketing_email_template_picker',
            'create_template', 'list_templates', 'update_template', 'delete_template',
            'record_metric',
            // historical / already-neutralised (kept for defence-in-depth)
            'send_email', 'test_send_email', 'enroll_sequence',
        ],
        // ── Social AUTOMATION / INTELLIGENCE (standalone composing) ──
        // The retained blog-share CRUD lives in RETAINED_SOCIAL_ACTIONS below;
        // everything here is the removed broad-social surface.
        // Social automation un-gated for launch (DEC-0028, 2026-08-25). mention (listening/
        // sentiment) stays fully removed via REMOVED_ENGINES.
        'social' => [],
        // ── CRM email-sequence / drip (email marketing under crm engine) ──
        'crm' => [
            'enroll_sequence', 'create_sequence', 'update_sequence', 'delete_sequence',
            'toggle_sequence', 'add_step', 'remove_step',
        ],
        // ── Content pack publish — REMOVED FROM THIS EXCLUSION 2026-08-24 ──
        // OWNER DECISION (CONF-0003, DEC-0019): content-pack publishing IS in
        // the launch product, ALLOWED-WITH-APPROVAL. The old
        // 'content' => ['content_publish_pack'] exclusion was already dead
        // (mis-keyed vs the runtime action 'publish_pack', RISK-0046) and
        // already contradicted by the 2026-07-22 "Content Publisher retained"
        // scope-change note directly below and by ApprovalPolicyRegistry, which
        // governs content.publish_pack as a self-service-confirmation (human
        // approval still required). Exclusion removed so the code states one
        // intent; the approval/authorization controls remain the gate.
    ];

    /**
     * SCOPE CHANGE 2026-07-22 — Content Publisher.
     * Manual, user-approved publishing is now a RETAINED launch capability.
     * Autonomous publishing, social management and social intelligence remain
     * removed. These actions are still denied by default and are permitted ONLY
     * when the caller presents a valid ArticleShareToken, which
     * App\Core\Distribution\PublisherService mints only after an EXPLICIT user
     * approval (see ArticleShareToken::EXPLICIT_APPROVALS). Agents still may not
     * hold these tools — they remain in REMOVED_TOOLS — so no agent can publish;
     * the Publisher acts as a service with no agent_id.
     *
     * The ONLY social actions permitted at launch — the blog-article-share
     * sliver (audit Deliverable 5). Permitted ONLY in article-share context
     * (see isArticleShareContext()); standalone use is denied.
     */
    public const RETAINED_SOCIAL_ACTIONS = [
        'social_create_post', 'create_post',
        'social_schedule_post', 'schedule_post',
        'social_publish_post', 'publish_post',
        'list_posts', 'update_post', 'get_queue',
    ];

    /**
     * Agents removed from launch (audit Deliverable 4 + locked decisions:
     * chris, leo added). Never rostered, meeting-selected, task-assigned,
     * routed, or suggested.
     */
    public const REMOVED_AGENTS = [
        'jordan', 'tyler', 'zara', 'zoe', 'maya', // social (marcus restored for launch — DEC-0028)
        'vera', 'kai',                                        // email
        'chris', 'leo',                                       // locked: video/ad-copy specialists
    ];

    /**
     * Agents retained but constrained — their social/email tool grants are
     * revoked (Workstream 3), but the agent itself stays.
     */
    public const CONSTRAINED_AGENTS = ['sarah', 'dmm', 'priya', 'elena', 'max', 'nora', 'sofia'];

    /**
     * W6 - plan feature keys that must never reach a customer response.
     *
     * The DB rows are deliberately left intact so the flags can be restored in
     * one UPDATE if a capability comes back. Filtering happens on OUTPUT, which
     * is the only place that matters for truth-in-advertising.
     */
    public const REMOVED_PLAN_FEATURES = [
        'social', 'social_publishing', 'social_media', 'social_analytics',
        'marketing', 'email_campaigns', 'email_marketing', 'newsletters',
        'sequences', 'automation', 'marketing_automation',
        'mentions', 'social_listening', 'publisher', 'content_publisher',
    ];

    /**
     * Strip removed capability keys from a plan feature map.
     * Retained entitlements (crm, seo, builder, chatbot, calendar, hosting,
     * content_writing, transactional email, ...) are untouched.
     */
    public static function filterPlanFeatures($features): array
    {
        if (is_string($features)) $features = json_decode($features, true);
        if (!is_array($features)) return [];
        foreach (self::REMOVED_PLAN_FEATURES as $k) {
            unset($features[$k]);
        }
        return $features;
    }

    /**
     * Tool ids removed from launch — social + email + sequence tools. No AGENT
     * may hold or select these (canUse short-circuits). The retained
     * article-share SERVICE executes create/schedule/publish/list_post with NO
     * agent_id, so it bypasses the agent-capability check and is governed only
     * by the kernel LaunchScopePolicy (which allows them in article-share
     * context). Calendar tools (create_event/list_events), Studio/creative
     * (generate_image/generate_video), CRM (create_lead) are NOT here — retained.
     */
    public const REMOVED_TOOLS = [
        // social tools un-gated for launch (DEC-0028, 2026-08-25) — no longer removed.
        // email / marketing
        'create_campaign', 'update_campaign', 'delete_campaign', 'list_campaigns',
        'schedule_campaign', 'send_campaign', 'create_automation', 'toggle_automation',
        'ai_campaign_copy', 'create_template', 'list_templates', 'update_template',
        'delete_template', 'record_metric', 'test_send_email', 'send_email',
        'ai_generate_email', 'ai_rewrite_block', 'ai_suggest_subjects', 'ai_spam_check',
        // sequences / drip
        'enroll_sequence', 'list_sequences', 'create_sequence', 'update_sequence',
        'delete_sequence',
    ];

    public static function isRemovedTool(?string $toolId): bool
    {
        if ($toolId === null) return false;
        return in_array(strtolower(trim($toolId)), self::REMOVED_TOOLS, true);
    }

    /** Normalise aliases / historical names to a canonical form for matching. */
    private static function normalize(string $action): string
    {
        $a = strtolower(trim($action));
        // strip proposal prefixes
        $a = preg_replace('/^(daily_action_|weekly_pivot_)/', '', $a) ?? $a;
        return $a;
    }

    /**
     * Is this engine/action removed from launch?
     * Returns null if allowed, or a reason-code string if removed.
     */
    public static function deniedReason(string $engine, string $action, array $params = [], array $context = []): ?string
    {
        $engine = strtolower(trim($engine));
        $act    = self::normalize($action);

        // 1. Fully-removed engines.
        if (in_array($engine, self::REMOVED_ENGINES, true)) {
            return 'LAUNCH_SCOPE_REMOVED_ENGINE';
        }

        // 2. Social automation is IN launch (DEC-0028, 2026-08-25). These actions are allowed
        //    standalone; safety rests on the protected/review approval gate, workspace-scoping,
        //    honest truthfulness (SocialService requires a real external_id + !mock to mark
        //    published), and mock_mode. The article-share HMAC path (ArticleShareToken) still
        //    works for blog-shares; it is simply no longer REQUIRED for standalone social.
        if (in_array($act, self::RETAINED_SOCIAL_ACTIONS, true)) {
            return null; // social automation permitted (DEC-0028)
        }

        // 3. Individually-removed actions per engine.
        $removed = self::REMOVED_ACTIONS[$engine] ?? [];
        if (in_array($act, $removed, true)) {
            return 'LAUNCH_SCOPE_REMOVED_ACTION';
        }

        return null;
    }

    /** Convenience boolean. */
    public static function isRemoved(string $engine, string $action, array $params = [], array $context = []): bool
    {
        return self::deniedReason($engine, $action, $params, $context) !== null;
    }

    public static function isRemovedAgent(?string $slug): bool
    {
        if ($slug === null) return false;
        return in_array(strtolower(trim($slug)), self::REMOVED_AGENTS, true);
    }

    /**
     * W5 (2026-07-22) — an article share is now PROVEN, not asserted.
     *
     * BEFORE: this returned true for any caller that supplied
     * mode='article_share', a truthy auto_share, or source='blog_share'. Those
     * are claims, not credentials — anyone able to reach the kernel could make
     * them, and doing so unlocked the entire RETAINED_SOCIAL_ACTIONS surface
     * (create/publish/update/schedule) with ARBITRARY content and no article.
     *
     * NOW: the caller must present an ArticleShareToken, an HMAC over the
     * immutable distribution context (workspace, article, platform, account,
     * canonical URL, idempotency key) keyed by APP_KEY. Only
     * App\Core\Distribution\ArticleDistributionService mints one, and only
     * after validating every one of those. Verification additionally requires
     * each signed claim to equal the live call, so a legitimate token cannot be
     * replayed against a different article, workspace or account.
     *
     * Content is bound separately by ArticleDistributionService, which requires
     * the caption to carry the canonical URL and keeps article_id /
     * canonical_url immutable. Token + content binding together are what stop
     * article_share degrading into arbitrary social posting.
     */
    public static function isArticleShareContext(array $params, array $context): bool
    {
        $token = (string) ($params['distribution_token'] ?? $context['distribution_token'] ?? '');
        if ($token === '') {
            return false;
        }

        try {
            return ArticleShareToken::verify($token, $params, $context);
        } catch (\Throwable $e) {
            // Fail closed. A missing or broken APP_KEY must DENY, never allow.
            \Illuminate\Support\Facades\Log::warning(
                '[LaunchScope] article-share token verification error',
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }
}

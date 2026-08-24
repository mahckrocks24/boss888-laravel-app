<?php

namespace App\Core\Governance;

/**
 * Capability-driven approval policy (directive §3).
 *
 * WHY POLICY IS PER-CAPABILITY, NOT GLOBAL
 * ----------------------------------------
 * Applying "no self-approval" to every protected capability would break the
 * product. Measured on staging: **all 19 workspaces are single-owner and NONE
 * has two or more owner/admin members.** `publish_website`, `send_campaign` and
 * `social_publish_post` are self-service publishing actions where the owner
 * requesting and confirming their own publish is the intended product behaviour.
 * A blanket rule would make every workspace unable to publish anything.
 *
 * So each protected capability is classified EXPLICITLY. Nothing is silently
 * grandfathered: the six non-INFRA888 capabilities are recorded here as
 * deliberately permitting self-confirmation, which makes that decision visible
 * and reviewable rather than an accident of missing code.
 *
 * ⚠️ The classification of the six non-INFRA888 capabilities preserves TODAY'S
 * behaviour and is flagged for product review. It is a starting position chosen
 * to avoid breaking live workflows, not an assertion that it is correct.
 *
 * FAIL-CLOSED: an unknown capability in `protected` mode gets the strict default
 * (no self-approval, owner/platform-admin only).
 */
final class ApprovalPolicyRegistry
{
    public const ROLE_OWNER          = 'owner';
    public const ROLE_ADMIN          = 'admin';
    public const ROLE_MEMBER         = 'member';
    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    /**
     * Strict default for anything protected but unclassified.
     */
    private const STRICT_DEFAULT = [
        'approval_roles'        => [self::ROLE_OWNER, self::ROLE_PLATFORM_ADMIN],
        'self_approval_allowed' => false,
        'human_approval_required' => true,
        'classification'        => 'strict_default_unclassified',
    ];

    /**
     * @return array<string,array{approval_roles:array<int,string>,
     *   self_approval_allowed:bool, human_approval_required:bool, classification:string}>
     */
    private static function map(): array
    {
        return [
            // ── INFRA888 — separation of duties REQUIRED ────────────────────
            // Provisioning creates a billing relationship and real resources.
            // The requester must never be the approver.
            'infrastructure.provision_hosting' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => false,
                'human_approval_required' => true,
                'classification'          => 'separation_of_duties_required',
            ],

            // ── Self-service publishing — self-confirmation PERMITTED ───────
            // These are "confirm you meant to do this" gates on the user's own
            // content, not separation-of-duties gates. Every workspace is
            // single-owner, so forbidding self-approval would make publishing
            // impossible. Viewers are still excluded.
            // ⚠️ FLAGGED FOR PRODUCT REVIEW — preserves current behaviour.
            'builder.publish_website' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'builder.publish_builder_page' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'marketing.send_campaign' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            // MISSION-018 WS-1 (2026-08-24, RISK-0019): key was 'social_publish_post'
            // (the capability-map KEY) but forCapability() builds "$engine.$action"
            // and the runtime action is 'publish_post' — so this entry never matched
            // and fell to STRICT_DEFAULT (self_approval_allowed=false), leaving a
            // single-owner workspace unable to confirm its own publish. Keyed to the
            // action the runtime produces. human_approval_required stays true — this
            // restores owner self-confirmation, it does not remove the human gate.
            'social.publish_post' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            // MISSION-018 WS-1 (2026-08-24, RISK-0019): key was 'content_publish_pack';
            // runtime action is 'publish_pack'. Same mis-key as social.publish_post
            // above — corrected so single-owner workspaces can self-confirm a
            // content-pack publish (human_approval_required stays true).
            'content.publish_pack' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            // b17 (2026-07-24) — publishing a BLOG ARTICLE belongs in this same
            // self-service class as publish_website / publish_builder_page /
            // social_publish_post: it is the owner confirming their own content
            // should go live, not a separation-of-duties gate. It was simply
            // omitted here, so it fell through to STRICT_DEFAULT
            // (self_approval_allowed=false) and every article publish request
            // was rejected with approval_requester_unverifiable — a solo owner
            // could never publish an article Sarah had queued. Same reasoning
            // the section header already states: every workspace is
            // single-owner, so forbidding self-approval makes publishing
            // impossible. Human confirmation is still required.
            'write.publish_article' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            // ── INFRA888 Phase 2A-2 catalog authoring ──────────────────────
            // ⚠️ CLASSIFIED self_service_confirmation ONLY BECAUSE THERE IS
            // CURRENTLY EXACTLY ONE PLATFORM ADMIN (verified 2026-07-19).
            // Under separation_of_duties these operations would be permanently
            // unpublishable: the sole admin would be both requester and
            // approver. That is a governance weakness, not a design preference —
            // one person can presently change pricing for every customer.
            //
            // FLAGGED FOR BOSS: appoint a second platform administrator, then
            // move these six to separation_of_duties_required. That is a
            // one-line change per capability.
            'infrastructure.publish_product' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'infrastructure.deprecate_product' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'infrastructure.retire_product' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'infrastructure.publish_plan' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'infrastructure.withdraw_plan' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
            'infrastructure.plan_subscriber_migration' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],

            // == Phase 2B: provider control plane =========================
            // SEPARATION OF DUTIES REQUIRED - deliberately UNLIKE the six
            // catalog capabilities above, which permit self-confirmation.
            //
            // Assessed independently, as the directive requires. The catalog
            // operations change what customers can BUY; these change what the
            // platform can DO to customer infrastructure. A wrongly published
            // price is correctable. A wrongly activated credential can be used
            // to delete a live DNS zone, and revocation stops future use
            // without undoing past use.
            //
            // KNOWN AND ACCEPTED CONSEQUENCE: with a single platform
            // administrator these operations cannot presently be approved -
            // the same person would be requester and approver. Credential
            // activation is therefore BLOCKED until a second platform admin
            // exists. That is fail-closed by design. Loosening it to unblock
            // a one-person team is exactly the weakening the directive
            // forbids.
            'infrastructure.activate_provider' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => false,
                'human_approval_required' => true,
                'classification'          => 'separation_of_duties_required',
            ],
            'infrastructure.enable_provider_capability' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => false,
                'human_approval_required' => true,
                'classification'          => 'separation_of_duties_required',
            ],
            'infrastructure.activate_provider_credential' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => false,
                'human_approval_required' => true,
                'classification'          => 'separation_of_duties_required',
            ],
            'infrastructure.rotate_provider_credential' => [
                'approval_roles'          => [self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => false,
                'human_approval_required' => true,
                'classification'          => 'separation_of_duties_required',
            ],

            'seo.autonomous_goal' => [
                'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
                'self_approval_allowed'   => true,
                'human_approval_required' => true,
                'classification'          => 'self_service_confirmation',
            ],
        ];
    }

    /**
     * Resolve the policy for an approval. `review`-mode and legacy approvals
     * (which are numerous — 101 pending at migration time and largely
     * agent-proposed) keep permissive self-confirmation so the existing review
     * queue is not bricked; only PROTECTED capabilities are tightened here.
     */
    public static function forCapability(?string $engine, ?string $action, string $approvalMode = 'review'): array
    {
        $key = trim((string) $engine) . '.' . trim((string) $action);
        $map = self::map();

        if (isset($map[$key])) {
            return $map[$key] + ['capability_key' => $key];
        }

        if ($approvalMode === 'protected') {
            // Fail closed: protected but unclassified.
            return self::STRICT_DEFAULT + ['capability_key' => $key];
        }

        return [
            'approval_roles'          => [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_PLATFORM_ADMIN],
            'self_approval_allowed'   => true,
            'human_approval_required' => true,
            'classification'          => 'review_queue_default',
            'capability_key'          => $key,
        ];
    }

    /** Viewers may never approve anything, in any classification. */
    public static function rolesNeverPermitted(): array
    {
        return ['viewer'];
    }

    /** Capabilities requiring a second distinct human. */
    public static function separationOfDutiesCapabilities(): array
    {
        return array_keys(array_filter(self::map(), fn ($p) => $p['self_approval_allowed'] === false));
    }

    public static function all(): array
    {
        return self::map();
    }
}

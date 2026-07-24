<?php

namespace App\Engines\Infrastructure\States;

/**
 * Provider certification levels (Phase 2B-CERT).
 *
 * A LEVEL IS A CLAIM ABOUT EVIDENCE, NOT ABOUT EFFORT.
 *
 *   0 registered          the row exists. No operational claim whatsoever.
 *   1 read_only_verified  identity, account and scope verified WITHOUT mutation.
 *   2 sandbox_validated   mutating operations proven in an isolated/disposable zone.
 *   3 staging_certified   end-to-end proven against staging resources.
 *   4 production_approved governance, security, operational, commercial and
 *                         recovery requirements all satisfied.
 *
 * The ordering is deliberate: each level requires a strictly stronger CLASS of
 * evidence than the one below, not merely more of the same. Level 1 can be
 * reached by reading; level 2 cannot be reached without writing somewhere safe;
 * level 4 cannot be reached by testing at all, because its blockers are
 * organizational (who owns the account, who can approve).
 *
 * That last point is why level 4 exists separately from level 3. A provider can
 * be technically flawless and still be unfit for production because it is owned
 * by a personal account and has no second approver. No amount of engineering
 * closes that gap, and a level system that let engineering close it would be
 * lying.
 */
final class CertificationLevel
{
    public const REGISTERED          = 0;
    public const READ_ONLY_VERIFIED  = 1;
    public const SANDBOX_VALIDATED   = 2;
    public const STAGING_CERTIFIED   = 3;
    public const PRODUCTION_APPROVED = 4;

    public static function all(): array
    {
        return [
            self::REGISTERED, self::READ_ONLY_VERIFIED, self::SANDBOX_VALIDATED,
            self::STAGING_CERTIFIED, self::PRODUCTION_APPROVED,
        ];
    }

    public static function label(int $level): string
    {
        return match ($level) {
            self::REGISTERED          => 'Level 0 — Registered',
            self::READ_ONLY_VERIFIED  => 'Level 1 — Read-only Verified',
            self::SANDBOX_VALIDATED   => 'Level 2 — Sandbox Validated',
            self::STAGING_CERTIFIED   => 'Level 3 — Staging Certified',
            self::PRODUCTION_APPROVED => 'Level 4 — Production Approved',
            default                   => "Unknown level {$level}",
        };
    }

    /**
     * The evidence class a level requires. Used to reject a claimed level whose
     * checks were satisfied only by documentation — the single most common way
     * a certification framework becomes theatre.
     */
    public static function requiresRuntimeEvidence(int $level): bool
    {
        return $level >= self::READ_ONLY_VERIFIED;
    }

    public static function requiresMutationEvidence(int $level): bool
    {
        return $level >= self::SANDBOX_VALIDATED;
    }

    /**
     * NOTE: there is deliberately NO requiredDomains() here.
     *
     * The single authority on what a level requires is
     * CertificationChecklistRegistry::forLevel(), consumed by
     * ProviderCertificationService::computeLevel(). A second representation once
     * existed on this class and was weaker (it listed domains A-C for level 1
     * while forLevel() requires every check at or below the level). Two
     * representations of one policy is how a framework silently loosens.
     * Guarded by CertificationPolicyAuthorityTest.
     */

    /** Only level 4 may make a provider selectable for production work. */
    public static function permitsProductionSelection(int $level): bool
    {
        return $level >= self::PRODUCTION_APPROVED;
    }

}

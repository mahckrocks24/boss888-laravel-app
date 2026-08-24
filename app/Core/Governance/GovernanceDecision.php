<?php

namespace App\Core\Governance;

/**
 * The immutable outcome of one governance evaluation. P0-B (2026-07-26).
 *
 * A decision records BOTH what governance concluded (`allowed`) and what the
 * caller must actually do (`effective`). In shadow mode those deliberately
 * diverge: governance may conclude DENY while the effective answer stays ALLOW,
 * so production behaviour is unchanged and the divergence is what gets logged.
 *
 * @see GovernanceService
 * @see P0-B-IMPACT-MATRIX.md §4 (mode ladder)
 */
final class GovernanceDecision
{
    public function __construct(
        public readonly string  $capability,
        public readonly bool    $allowed,      // what governance concluded
        public readonly bool    $effective,    // what the caller must honour
        public readonly string  $mode,         // off | shadow | audit | enforce
        public readonly string  $reason,
        public readonly string  $principalType,
        public readonly ?int    $principalId   = null,
        public readonly ?int    $workspaceId   = null,
        public readonly ?string $resource      = null,
        public readonly string  $risk          = 'unknown',
        public readonly bool    $approvalRequired = false,
        public readonly bool    $mfaRequired      = false,
    ) {
    }

    /** True when governance would have blocked but the mode let it through. */
    public function isShadowDivergence(): bool
    {
        return $this->allowed === false && $this->effective === true;
    }

    /** Audit/event payload. Contains no secrets by construction. */
    public function toArray(): array
    {
        return [
            'capability'        => $this->capability,
            'allowed'           => $this->allowed,
            'effective'         => $this->effective,
            'mode'              => $this->mode,
            'reason'            => $this->reason,
            'principal_type'    => $this->principalType,
            'principal_id'      => $this->principalId,
            'workspace_id'      => $this->workspaceId,
            'resource'          => $this->resource,
            'risk'              => $this->risk,
            'approval_required' => $this->approvalRequired,
            'mfa_required'      => $this->mfaRequired,
            'shadow_divergence' => $this->isShadowDivergence(),
        ];
    }
}

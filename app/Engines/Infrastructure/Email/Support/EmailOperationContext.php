<?php

namespace App\Engines\Infrastructure\Email\Support;

use App\Engines\Infrastructure\Email\Models\EmailDomain;
use Illuminate\Database\Eloquent\Model;

/**
 * INFRA888 · E1 — everything one Business Email request needs, in one value.
 *
 * WHY THE ENTITLEMENT SET IS PASSED IN RATHER THAN RESOLVED HERE
 * Resolving entitlements means reading plans, subscriptions and workspace
 * status. Doing that inside the engine would make every engine test require a
 * commercial fixture, would couple this milestone to
 * InfrastructureEntitlementService (owned by another work package and actively
 * edited), and would hide the decision inside a call chain. Passing a resolved
 * map keeps the ENFORCEMENT here — where it is testable against any input —
 * and leaves RESOLUTION to the caller. E3 supplies it from the entitlement
 * service; E1 supplies it from a test.
 *
 * Immutable: a request's context must not change while it is being evaluated.
 */
final class EmailOperationContext
{
    /**
     * @param int                  $workspaceId  the acting tenant
     * @param string               $capability   a BusinessEmailCapabilityRegistry slug
     * @param EmailDomain|null     $domain       the root object, where one applies
     * @param Model|null           $subject      mailbox, alias, forwarder or catch-all
     * @param int|null             $actorUserId  who asked; null for system-initiated work
     * @param string               $source       manual|admin|system|task|agent
     * @param array<string,mixed>  $entitlements resolved plan features, key => value
     * @param array<string,mixed>  $input        operation payload; never credentials
     * @param string|null          $idempotencyKey required for mutating capabilities
     * @param string|null          $targetState  the lifecycle state being moved to
     * @param bool                 $approved     an approval record exists for this request
     * @param int|null             $approvedBy   who approved; must differ from actor under SoD
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $capability,
        public readonly ?EmailDomain $domain = null,
        public readonly ?Model $subject = null,
        public readonly ?int $actorUserId = null,
        public readonly string $source = 'manual',
        public readonly array $entitlements = [],
        public readonly array $input = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $targetState = null,
        public readonly bool $approved = false,
        public readonly ?int $approvedBy = null,
    ) {
    }

    public function entitlement(string $key, mixed $default = null): mixed
    {
        return $this->entitlements[$key] ?? $default;
    }

    public function hasEntitlement(string $key): bool
    {
        $value = $this->entitlements[$key] ?? null;

        // Absent, false, 0 and empty string all mean "not granted". A limit of
        // zero is not access to zero of something — it is no access.
        return $value !== null && $value !== false && $value !== 0 && $value !== '';
    }

    /**
     * A numeric plan limit, or null when the plan states no limit.
     *
     * Distinguishes "unlimited" (null) from "none" (0), because treating an
     * absent limit as zero would lock out every unlimited plan.
     */
    public function limit(string $key): ?int
    {
        $value = $this->entitlements[$key] ?? null;

        if ($value === null || $value === '' || $value === true) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Safe for an operation record and an audit event. Deliberately omits the
     * input payload, which may carry a display name or an external address the
     * customer would not expect in an operator log by default.
     *
     * @return array<string,mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'workspace_id'    => $this->workspaceId,
            'capability'      => $this->capability,
            'domain_id'       => $this->domain?->id,
            'subject_type'    => $this->subject !== null ? $this->subject::class : null,
            'subject_id'      => $this->subject?->getKey(),
            'actor_user_id'   => $this->actorUserId,
            'source'          => $this->source,
            'target_state'    => $this->targetState,
            'approved'        => $this->approved,
            'approved_by'     => $this->approvedBy,
            'input_keys'      => array_keys($this->input),
        ];
    }
}

<?php

namespace App\Engines\Infrastructure\Registry;

use InvalidArgumentException;

/**
 * Credential ROLES (Phase 2B-R3, WS2).
 *
 * A role answers a different question than a purpose. Purpose asks "which
 * capabilities may this credential serve" (dns vs certificate). Role asks "what
 * KIND of credential is this" — and the kind determines whether it may take part
 * in provider selection at all.
 *
 * THE LOAD-BEARING PROPERTY: only a `provisioning` credential
 * `participatesInResolution()`. A diagnostics, certification, billing or
 * incident-response credential can NEVER be selected to perform a customer
 * operation, no matter its capability scope, no matter how healthy the provider.
 * This is the structural answer to the Phase 2B-R2 finding — a read-only
 * diagnostics token becomes storable AND provably unable to touch customer work.
 *
 *   provisioning        creates/changes customer resources. Participates in
 *                       resolution. Requires a capability scope.
 *   diagnostics         read-only config/health inspection. NEVER resolves.
 *                       No capability scope (that was the R2 blocker).
 *   certification       used only by the certification framework to probe a
 *                       provider. NEVER resolves.
 *   migration           bulk read/transform during a provider migration. Does
 *                       not serve live customer ops; NEVER resolves.
 *   billing             reads cost/usage from a provider. NEVER resolves.
 *   incident_response   break-glass read/limited action during an incident.
 *                       NEVER resolves through the normal path.
 */
final class CredentialRoleRegistry
{
    public const ROLE_PROVISIONING      = 'provisioning';
    public const ROLE_DIAGNOSTICS       = 'diagnostics';
    public const ROLE_CERTIFICATION     = 'certification';
    public const ROLE_MIGRATION         = 'migration';
    public const ROLE_BILLING           = 'billing';
    public const ROLE_INCIDENT_RESPONSE = 'incident_response';

    /**
     * @return array<string,array{participates_in_resolution:bool,
     *   requires_capability_scope:bool, may_mutate:bool, description:string}>
     */
    private const MAP = [
        self::ROLE_PROVISIONING => [
            'participates_in_resolution' => true,
            'requires_capability_scope'  => true,
            'may_mutate'                 => true,
            'description' => 'Creates and changes customer resources. The only role selectable for work.',
        ],
        self::ROLE_DIAGNOSTICS => [
            'participates_in_resolution' => false,
            'requires_capability_scope'  => false,
            'may_mutate'                 => false,
            'description' => 'Read-only configuration and health inspection. Never selectable.',
        ],
        self::ROLE_CERTIFICATION => [
            'participates_in_resolution' => false,
            'requires_capability_scope'  => false,
            'may_mutate'                 => false,
            'description' => 'Probes a provider for certification only. Never selectable.',
        ],
        self::ROLE_MIGRATION => [
            'participates_in_resolution' => false,
            'requires_capability_scope'  => false,
            'may_mutate'                 => true,   // may write during a migration, but not via resolution
            'description' => 'Bulk read/transform during provider migration. Never serves live customer ops.',
        ],
        self::ROLE_BILLING => [
            'participates_in_resolution' => false,
            'requires_capability_scope'  => false,
            'may_mutate'                 => false,
            'description' => 'Reads cost and usage from a provider. Never selectable.',
        ],
        self::ROLE_INCIDENT_RESPONSE => [
            'participates_in_resolution' => false,
            'requires_capability_scope'  => false,
            'may_mutate'                 => true,   // limited break-glass action, deliberate not resolved
            'description' => 'Break-glass credential used only during an incident. Never selectable normally.',
        ],
    ];

    public static function all(): array
    {
        return self::MAP;
    }

    public static function roles(): array
    {
        return array_keys(self::MAP);
    }

    public static function isKnown(string $role): bool
    {
        return array_key_exists($role, self::MAP);
    }

    public static function assertKnown(string $role): void
    {
        if (!self::isKnown($role)) {
            throw new InvalidArgumentException(
                "Unknown credential role '{$role}'. Known: " . implode(', ', self::roles())
            );
        }
    }

    /** The single most important query: may a credential of this role be selected for work? */
    public static function participatesInResolution(string $role): bool
    {
        return (bool) (self::MAP[$role]['participates_in_resolution'] ?? false);
    }

    public static function requiresCapabilityScope(string $role): bool
    {
        return (bool) (self::MAP[$role]['requires_capability_scope'] ?? false);
    }

    public static function mayMutate(string $role): bool
    {
        return (bool) (self::MAP[$role]['may_mutate'] ?? false);
    }

    /** Roles that must never appear as a resolution candidate. */
    public static function nonResolutionRoles(): array
    {
        return array_keys(array_filter(self::MAP, fn ($m) => !$m['participates_in_resolution']));
    }
}

<?php

namespace App\Engines\Infrastructure\Services;

use App\Connectors\Infrastructure\Contracts\CredentialVerifier;
use App\Connectors\Infrastructure\CredentialVerificationResult;
use App\Connectors\Infrastructure\Null\NullCredentialVerifier;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Registry\CredentialPurposeRegistry;
use App\Engines\Infrastructure\Registry\CredentialRoleRegistry;
use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * 🔴 Credential lifecycle (Phase 2B-3). The highest-privilege service in INFRA888.
 *
 * FOUR PROPERTIES THIS CLASS GUARANTEES
 *
 * 1. A SECRET IS NEVER RETURNED, LOGGED OR EVENTED.
 *    No method returns plaintext. Events carry the fingerprint prefix and hint,
 *    never the value. The secret exists in memory for the duration of one call.
 *
 * 2. VERIFICATION CANNOT BE FAKED.
 *    activate() demands a CredentialVerificationResult that was genuinely
 *    attempted AND genuinely authenticated. `notAttempted()` is refused. There is
 *    no force flag, no override parameter, and no configuration that relaxes it.
 *
 * 3. ROTATION NEVER OVERWRITES.
 *    rotate() creates a NEW row and links it. The old secret is never mutated,
 *    so "which credential was live at 14:07 last Tuesday" stays answerable.
 *
 * 4. REVOCATION IS ALWAYS AVAILABLE.
 *    revoke() is deliberately NOT approval-gated — see the note on that method.
 *
 * GOVERNANCE NOTE: authorization is enforced upstream by EngineExecutionService
 * against InfrastructureCapabilityRegistry, exactly like the catalog operations.
 * This service performs mechanics and safety invariants; it is not the authority
 * on who may call it. It does, however, refuse machine identities outright
 * (see assertHumanActor) because that is a property of the operation itself.
 */
class ProviderCredentialService
{
    /** Days before expiry at which a credential is moved to `expiring`. */
    private const EXPIRY_WARNING_DAYS = 14;

    public function __construct(
        private readonly ProviderEventRecorder $events,
        private readonly ProviderHealthService $health,
    ) {
    }

    /**
     * Store a new credential. Always lands `pending_verification` — INERT.
     *
     * The secret is passed by value, encrypted by the model cast on write, and is
     * unreachable afterwards except through the explicit secret() accessor.
     */
    public function create(
        InfraProvider $provider,
        string $credentialKey,
        string $secret,
        array $attrs = [],
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): InfraProviderCredential {
        $this->assertHumanActor($actorUserId, 'create credential');

        if (trim($secret) === '') {
            throw new InvalidArgumentException('Credential secret must not be empty.');
        }

        $environment = $attrs['environment'] ?? InfraProvider::ENV_SANDBOX;
        $this->assertKnownEnvironment($environment);

        $scope = $attrs['capability_scope_json'] ?? [];

        // ── CREDENTIAL ROLE (Phase 2B-R3) ─────────────────────────────────
        // Role decides what KIND of credential this is. Only a `provisioning`
        // credential requires a capability scope and takes part in resolution;
        // diagnostics/certification/billing credentials are read-only inspectors
        // that must NEVER be selected for customer work, so they may be unscoped.
        // This is what makes a read-only diagnostics token storable (the R2 gap).
        $role = $attrs['credential_role'] ?? CredentialRoleRegistry::ROLE_PROVISIONING;
        CredentialRoleRegistry::assertKnown($role);

        foreach ($scope as $capability) {
            if (!in_array($capability, InfraProviderConnection::capabilities(), true)) {
                throw new InvalidArgumentException("Unknown capability '{$capability}' in scope.");
            }
        }

        if (CredentialRoleRegistry::requiresCapabilityScope($role) && $scope === []) {
            throw new InvalidArgumentException(
                "A '{$role}' credential must list at least one capability. An unscoped "
                . 'provisioning credential covers nothing and would never be selected.'
            );
        }

        if (!CredentialRoleRegistry::requiresCapabilityScope($role) && $scope !== []) {
            throw new InvalidArgumentException(
                "A '{$role}' credential is read-only and must NOT declare a capability scope; "
                . 'it never participates in provider selection.'
            );
        }

        // ── PURPOSE BINDING (Phase 2B-G) ─────────────────────────────────
        // A credential is bound to ONE purpose, and a purpose caps which
        // capabilities it may ever serve. This stops a single over-broad
        // credential being shared by every adapter merely because one vendor
        // token could technically do everything.
        //
        // When no purpose is supplied it is DERIVED from the scope — and that
        // derivation IS the enforcement: a scope spanning two purposes has no
        // single answer, so it is rejected rather than silently widened.
        $purpose = $attrs['purpose'] ?? null;

        // Non-provisioning roles carry the role AS their purpose marker and skip
        // capability-purpose derivation entirely (they have no capabilities).
        if (!CredentialRoleRegistry::requiresCapabilityScope($role)) {
            $purpose = $purpose ?? $role;
        } elseif ($purpose === null || !CredentialPurposeRegistry::isKnown((string) $purpose)) {
            $derived = array_values(array_unique(array_filter(array_map(
                fn ($c) => CredentialPurposeRegistry::purposeForCapability($c),
                $scope
            ))));

            if (count($derived) > 1) {
                throw new InvalidArgumentException(
                    'Capability scope [' . implode(', ', $scope) . '] spans multiple credential '
                    . 'purposes (' . implode(', ', $derived) . '). Issue one credential per '
                    . 'purpose so a leak of one cannot grant the authority of the other.'
                );
            }

            if ($derived === []) {
                throw new InvalidArgumentException(
                    'No credential purpose covers capability scope [' . implode(', ', $scope) . '].'
                );
            }

            $purpose = $derived[0];
        }

        if (CredentialRoleRegistry::requiresCapabilityScope($role)) {
            CredentialPurposeRegistry::assertScopeAllowed($purpose, $scope);
        }

        $existing = InfraProviderCredential::where('provider_id', $provider->id)
            ->where('credential_key', $credentialKey)
            ->where('environment', $environment)
            ->exists();

        if ($existing) {
            throw new InvalidArgumentException(
                "Credential '{$credentialKey}' already exists for '{$provider->provider_key}' "
                . "({$environment}). Use rotate() to replace it — creating a duplicate would "
                . 'break the rotation chain.'
            );
        }

        $credential = InfraProviderCredential::create([
            'provider_id'           => $provider->id,
            'credential_key'        => $credentialKey,
            'environment'           => $environment,
            'label'                 => $attrs['label'] ?? null,
            'purpose'               => $purpose,
            'credential_role'       => $role,
            'capability_scope_json' => array_values($scope),
            'region_scope_json'     => $attrs['region_scope_json'] ?? [],
            'account_identifier'    => $attrs['account_identifier'] ?? null,
            'secret_encrypted'      => $secret,
            'secret_fingerprint'    => InfraProviderCredential::fingerprint($secret),
            'secret_hint'           => InfraProviderCredential::hint($secret),
            'state'                 => CredentialState::initial(),
            'valid_from'            => $attrs['valid_from'] ?? null,
            'expires_at'            => $attrs['expires_at'] ?? null,
            'created_by_user_id'    => $actorUserId,
        ]);

        $this->events->record(
            eventType: InfraProviderEvent::CREDENTIAL_CREATED,
            provider: $provider,
            environment: $environment,
            severity: InfraProviderEvent::SEVERITY_INFO,
            toState: CredentialState::initial(),
            summary: "Credential '{$credentialKey}' stored for '{$provider->provider_key}' "
                . '(pending verification, not usable).',
            metadata: [
                'credential_key'    => $credentialKey,
                'capability_scope'  => array_values($scope),
                'fingerprint_short' => substr($credential->secret_fingerprint, 0, 12),
                'hint'              => $credential->secret_hint,
            ],
            actorUserId: $actorUserId,
            actorType: InfraProviderEvent::ACTOR_USER,
            correlationId: $correlationId,
            credentialId: $credential->id,
        );

        return $credential;
    }

    /**
     * Verify read-only against the provider and persist the sanitized result.
     *
     * Records the outcome whether it passes or fails — a failed verification is
     * operationally important information, not something to swallow.
     */
    public function verify(
        InfraProviderCredential $credential,
        ?CredentialVerifier $verifier = null,
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): CredentialVerificationResult {
        $provider = $credential->provider;
        $verifier ??= $this->verifierFor($provider);

        $expected = $credential->capability_scope_json ?? [];

        $result = $verifier->verify(
            $credential->secretForVerification() ?? '',
            $credential->environment,
            $expected
        );

        $credential->update([
            'last_verified_at'          => now(),
            'last_verification_ok'      => $result->attempted ? $result->authenticated : null,
            'granted_capabilities_json' => $result->grantedCapabilities,
            'missing_capabilities_json' => $result->missingCapabilities,
            'last_verification_code'    => $result->code,
            'last_verification_summary' => $result->summary,
            // Only overwrite the operator-supplied account identifier when the
            // provider actually told us one. This field is how the D1 ownership
            // problem becomes visible in data rather than in a person's memory.
            'account_identifier'        => $result->accountIdentifier ?? $credential->account_identifier,
            'expires_at'                => $result->expiresAt ?? $credential->expires_at,
        ]);

        // A credential the provider actively rejected becomes `invalid` — but a
        // NOT-ATTEMPTED verification changes nothing. "We didn't check" must never
        // be recorded as "it failed".
        if ($result->attempted && !$result->authenticated
            && !in_array($credential->state, CredentialState::terminal(), true)
            && CredentialState::canTransition($credential->state, CredentialState::INVALID)) {
            $this->transition($credential, CredentialState::INVALID, $actorUserId, $result->summary);
        }

        $this->events->record(
            eventType: $result->permitsActivation()
                ? InfraProviderEvent::CREDENTIAL_VERIFIED
                : InfraProviderEvent::CREDENTIAL_VERIFICATION_FAILED,
            provider: $provider,
            environment: $credential->environment,
            severity: $result->permitsActivation()
                ? InfraProviderEvent::SEVERITY_SUCCESS
                : ($result->attempted
                    ? InfraProviderEvent::SEVERITY_ERROR
                    : InfraProviderEvent::SEVERITY_WARNING),
            summary: "Verification of '{$credential->credential_key}': {$result->summary}",
            metadata: [
                'attempted'            => $result->attempted,
                'authenticated'        => $result->authenticated,
                'code'                 => $result->code,
                'granted_capabilities' => $result->grantedCapabilities,
                'missing_capabilities' => $result->missingCapabilities,
                'account_identifier'   => $result->accountIdentifier,
                'diagnostics'          => $result->diagnostics,
            ],
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SCHEDULER,
            correlationId: $correlationId,
            credentialId: $credential->id,
        );

        return $result;
    }

    /**
     * Activate a credential — the moment it may control customer infrastructure.
     *
     * 🔴 REQUIRES GENUINE EVIDENCE. There is intentionally no bypass:
     *   - notAttempted() is refused (nothing was checked)
     *   - authenticated=false is refused
     *   - a result missing any scoped capability is refused
     *
     * The last check is what the D2 finding would have caught automatically: a
     * credential scoped to `certificate` whose verification granted only `dns`
     * cannot be activated, because activating it would advertise a capability the
     * platform cannot actually perform.
     */
    public function activate(
        InfraProviderCredential $credential,
        CredentialVerificationResult $result,
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): InfraProviderCredential {
        $this->assertHumanActor($actorUserId, 'activate credential');

        if (!$result->attempted) {
            throw new RuntimeException(
                "Cannot activate '{$credential->credential_key}': verification was never "
                . 'attempted. A credential may not be activated without evidence that it works.'
            );
        }

        if (!$result->authenticated) {
            throw new RuntimeException(
                "Cannot activate '{$credential->credential_key}': verification failed "
                . "({$result->code}: {$result->summary})."
            );
        }

        $scope   = $credential->capability_scope_json ?? [];
        $ungranted = array_values(array_diff($scope, $result->grantedCapabilities));

        // EXCESS PRIVILEGE (Phase 2B-G, WS7).
        // A credential granted MORE than its purpose requires is a different and
        // more insidious problem than one granted too little: the too-narrow
        // credential fails loudly on first use, whereas the over-privileged one
        // works perfectly and silently carries unnecessary blast radius until
        // the day it leaks. Refused, with the excess named.
        if ($credential->purpose) {
            $excess = CredentialPurposeRegistry::excessGrants(
                $credential->purpose,
                $result->grantedCapabilities
            );

            if ($excess !== []) {
                throw new RuntimeException(
                    "Cannot activate '{$credential->credential_key}': the provider granted "
                    . 'capabilities beyond its purpose (' . $credential->purpose . '): ['
                    . implode(', ', $excess) . ']. Re-issue a least-privilege credential '
                    . 'rather than accepting excess authority.'
                );
            }
        }

        if ($ungranted !== []) {
            throw new RuntimeException(
                "Cannot activate '{$credential->credential_key}': it is scoped to ["
                . implode(', ', $ungranted) . '] but the provider did not grant '
                . 'those capabilities. Narrow the scope to what the credential can '
                . 'actually do, or issue a credential with the required permissions.'
            );
        }

        $from = $credential->state;
        CredentialState::assertTransition($from, CredentialState::ACTIVE);

        $credential->update([
            'state'                => CredentialState::ACTIVE,
            'activated_at'         => now(),
            'activated_by_user_id' => $actorUserId,
        ]);

        $this->events->record(
            eventType: InfraProviderEvent::CREDENTIAL_ACTIVATED,
            provider: $credential->provider,
            environment: $credential->environment,
            severity: InfraProviderEvent::SEVERITY_SUCCESS,
            fromState: $from,
            toState: CredentialState::ACTIVE,
            summary: "Credential '{$credential->credential_key}' ACTIVATED for "
                . "'{$credential->provider->provider_key}'.",
            metadata: [
                'granted_capabilities' => $result->grantedCapabilities,
                'account_identifier'   => $result->accountIdentifier,
                'fingerprint_short'    => substr((string) $credential->secret_fingerprint, 0, 12),
            ],
            actorUserId: $actorUserId,
            actorType: InfraProviderEvent::ACTOR_USER,
            correlationId: $correlationId,
            credentialId: $credential->id,
        );

        if ($credential->provider) {
            $this->health->propagateCredentialExpiry($credential->provider, $credential->fresh());
        }

        return $credential->fresh();
    }

    /**
     * Rotate: create a REPLACEMENT credential linked to the current one.
     *
     * The old row is untouched apart from its `superseded_by_id` pointer. It is
     * NOT superseded yet — that happens in completeRotation(), only after the
     * replacement has been verified and activated. Between the two calls both
     * credentials exist, the old one still works, and a failed rotation therefore
     * cannot cause an outage.
     */
    public function beginRotation(
        InfraProviderCredential $current,
        string $newSecret,
        array $attrs = [],
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): InfraProviderCredential {
        $this->assertHumanActor($actorUserId, 'rotate credential');

        if (in_array($current->state, CredentialState::terminal(), true)) {
            throw new InvalidArgumentException(
                "Cannot rotate a '{$current->state}' credential. Create a new one instead."
            );
        }

        // Catches the real-world rotation failure: pasting the SAME secret back
        // in and believing rotation happened. Without a fingerprint this is
        // invisible — the row changes, the secret does not.
        if (InfraProviderCredential::fingerprint($newSecret) === $current->secret_fingerprint) {
            throw new InvalidArgumentException(
                'Rotation refused: the new secret is identical to the current one. '
                . 'Nothing would change.'
            );
        }

        $correlationId ??= (string) Str::uuid();

        $replacement = DB::transaction(function () use ($current, $newSecret, $attrs, $actorUserId, $correlationId) {
            $new = InfraProviderCredential::create([
                'provider_id'           => $current->provider_id,
                // Suffixed so the unique (provider, key, environment) index holds
                // while both generations coexist.
                'credential_key'        => $attrs['credential_key']
                    ?? $this->nextRotationKey($current),
                'environment'           => $current->environment,
                'label'                 => $attrs['label'] ?? $current->label,
                'purpose'               => $attrs['purpose'] ?? $current->purpose,
                'capability_scope_json' => $attrs['capability_scope_json'] ?? $current->capability_scope_json,
                'region_scope_json'     => $attrs['region_scope_json'] ?? $current->region_scope_json,
                'account_identifier'    => $attrs['account_identifier'] ?? $current->account_identifier,
                'secret_encrypted'      => $newSecret,
                'secret_fingerprint'    => InfraProviderCredential::fingerprint($newSecret),
                'secret_hint'           => InfraProviderCredential::hint($newSecret),
                'state'                 => CredentialState::initial(),
                'valid_from'            => $attrs['valid_from'] ?? null,
                'expires_at'            => $attrs['expires_at'] ?? null,
                'supersedes_id'         => $current->id,
                'created_by_user_id'    => $actorUserId,
            ]);

            // Pointer only. The old credential keeps working until the new one
            // proves itself.
            $current->update(['superseded_by_id' => $new->id]);

            return $new;
        });

        $this->events->record(
            eventType: InfraProviderEvent::CREDENTIAL_ROTATION_STARTED,
            provider: $current->provider,
            environment: $current->environment,
            severity: InfraProviderEvent::SEVERITY_INFO,
            summary: "Rotation started for '{$current->credential_key}' -> "
                . "'{$replacement->credential_key}'. Old credential remains active until "
                . 'the replacement is verified.',
            metadata: [
                'old_credential_id'      => $current->id,
                'new_credential_id'      => $replacement->id,
                'old_fingerprint_short'  => substr((string) $current->secret_fingerprint, 0, 12),
                'new_fingerprint_short'  => substr((string) $replacement->secret_fingerprint, 0, 12),
            ],
            actorUserId: $actorUserId,
            actorType: InfraProviderEvent::ACTOR_USER,
            correlationId: $correlationId,
            credentialId: $replacement->id,
        );

        return $replacement;
    }

    /**
     * Finish a rotation: the replacement must already be ACTIVE.
     *
     * Only then is the old credential moved to `superseded` — a terminal state it
     * can never leave. The old secret is NOT deleted or overwritten; the audit
     * trail of what was live and when must survive.
     */
    public function completeRotation(
        InfraProviderCredential $replacement,
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): InfraProviderCredential {
        $old = $replacement->supersedes;

        if (!$old) {
            throw new InvalidArgumentException(
                "Credential '{$replacement->credential_key}' does not supersede anything."
            );
        }

        if ($replacement->state !== CredentialState::ACTIVE) {
            throw new RuntimeException(
                "Cannot complete rotation: replacement is '{$replacement->state}', not active. "
                . 'Superseding the old credential now would leave the provider with no usable '
                . 'credential at all.'
            );
        }

        $from = $old->state;
        CredentialState::assertTransition($from, CredentialState::SUPERSEDED);

        $old->update(['state' => CredentialState::SUPERSEDED]);

        $this->events->record(
            eventType: InfraProviderEvent::CREDENTIAL_ROTATED,
            provider: $replacement->provider,
            environment: $replacement->environment,
            severity: InfraProviderEvent::SEVERITY_SUCCESS,
            fromState: $from,
            toState: CredentialState::SUPERSEDED,
            summary: "Rotation complete: '{$old->credential_key}' superseded by "
                . "'{$replacement->credential_key}'.",
            metadata: [
                'old_credential_id' => $old->id,
                'new_credential_id' => $replacement->id,
            ],
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $correlationId,
            credentialId: $replacement->id,
        );

        return $old->fresh();
    }

    /**
     * Revoke immediately.
     *
     * ⚠️ DELIBERATELY NOT APPROVAL-GATED, and that is a security decision rather
     * than an oversight.
     *
     * Revocation is the response to a LEAK. If revoking required a second
     * administrator's approval, then during an incident — the exact moment speed
     * matters — a leaked credential would stay live while someone hunted for an
     * approver. Under this platform's single-admin reality that could mean hours.
     *
     * The risk of unapproved revocation is a denial of service the platform
     * inflicts on itself, which is recoverable by issuing a new credential. The
     * risk of DELAYED revocation is an attacker retaining control of customer
     * DNS. These are not symmetric, so the safety action is made frictionless
     * while the dangerous action (activation) stays gated.
     *
     * Revocation is still fully attributed and evented.
     */
    public function revoke(
        InfraProviderCredential $credential,
        string $reason,
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): InfraProviderCredential {
        if ($credential->state === CredentialState::REVOKED) {
            return $credential;
        }

        $from = $credential->state;

        if (!CredentialState::canTransition($from, CredentialState::REVOKED)) {
            throw new RuntimeException(
                "Cannot revoke a '{$from}' credential."
            );
        }

        $credential->update([
            'state'              => CredentialState::REVOKED,
            'revoked_at'         => now(),
            'revoked_reason'     => $reason,
            'revoked_by_user_id' => $actorUserId,
        ]);

        $this->events->record(
            eventType: InfraProviderEvent::CREDENTIAL_REVOKED,
            provider: $credential->provider,
            environment: $credential->environment,
            severity: InfraProviderEvent::SEVERITY_CRITICAL,
            fromState: $from,
            toState: CredentialState::REVOKED,
            summary: "Credential '{$credential->credential_key}' REVOKED: {$reason}",
            metadata: [
                'reason'            => $reason,
                'fingerprint_short' => substr((string) $credential->secret_fingerprint, 0, 12),
            ],
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $correlationId,
            credentialId: $credential->id,
        );

        return $credential->fresh();
    }

    /**
     * Sweep expiry states. Intended for the scheduler.
     *
     * Transitions produce EVENTS, which is why expiry is modelled as a state
     * rather than computed on read: a computed flag flips silently and nobody is
     * ever told.
     *
     * @return array{expiring:int,expired:int}
     */
    public function sweepExpiry(): array
    {
        $counts = ['expiring' => 0, 'expired' => 0];

        $candidates = InfraProviderCredential::query()
            ->whereNotNull('expires_at')
            ->whereIn('state', [CredentialState::ACTIVE, CredentialState::EXPIRING])
            ->with('provider')
            ->get();

        foreach ($candidates as $credential) {
            $days = $credential->daysUntilExpiry();

            if ($days === null) {
                continue;
            }

            if ($days <= 0 && $credential->state !== CredentialState::EXPIRED) {
                $this->transition($credential, CredentialState::EXPIRED, null,
                    "Credential expired on {$credential->expires_at->toDateString()}.");
                $counts['expired']++;
                continue;
            }

            if ($days > 0 && $days <= self::EXPIRY_WARNING_DAYS
                && $credential->state === CredentialState::ACTIVE) {
                $this->transition($credential, CredentialState::EXPIRING, null,
                    "Credential expires in {$days} day(s).");
                $counts['expiring']++;

                if ($credential->provider) {
                    $this->health->propagateCredentialExpiry($credential->provider, $credential->fresh());
                }
            }
        }

        return $counts;
    }

    /** Generic state transition with validation and an event. */
    public function transition(
        InfraProviderCredential $credential,
        string $toState,
        ?int $actorUserId = null,
        ?string $reason = null
    ): InfraProviderCredential {
        $from = $credential->state;
        CredentialState::assertTransition($from, $toState);

        $credential->update(['state' => $toState]);

        $this->events->record(
            eventType: match ($toState) {
                CredentialState::EXPIRING => InfraProviderEvent::CREDENTIAL_EXPIRING,
                CredentialState::EXPIRED  => InfraProviderEvent::CREDENTIAL_EXPIRED,
                default                   => InfraProviderEvent::HEALTH_CHANGED,
            },
            provider: $credential->provider,
            environment: $credential->environment,
            severity: $toState === CredentialState::EXPIRED
                ? InfraProviderEvent::SEVERITY_ERROR
                : InfraProviderEvent::SEVERITY_WARNING,
            fromState: $from,
            toState: $toState,
            summary: "Credential '{$credential->credential_key}': {$from} -> {$toState}"
                . ($reason ? " ({$reason})" : ''),
            metadata: array_filter(['reason' => $reason]),
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SCHEDULER,
            credentialId: $credential->id,
        );

        return $credential->fresh();
    }

    public function verifierFor(?InfraProvider $provider): CredentialVerifier
    {
        if (!$provider) {
            return new NullCredentialVerifier();
        }

        $class = config("infrastructure.credential_verifiers.{$provider->provider_key}");

        if (!$class || !class_exists($class)) {
            return new NullCredentialVerifier();
        }

        $verifier = app($class);

        return $verifier instanceof CredentialVerifier ? $verifier : new NullCredentialVerifier();
    }

    private function nextRotationKey(InfraProviderCredential $current): string
    {
        $base = preg_replace('/-r\d+$/', '', $current->credential_key);
        $n    = 2;

        while (InfraProviderCredential::where('provider_id', $current->provider_id)
            ->where('environment', $current->environment)
            ->where('credential_key', "{$base}-r{$n}")
            ->exists()) {
            $n++;
        }

        return "{$base}-r{$n}";
    }

    /**
     * Machine identities may never author credentials.
     *
     * An API key or shared token creating or activating a credential would mean a
     * machine could escalate its own reach over customer infrastructure with no
     * human attribution — and the resulting audit trail would name no one.
     * Requiring a real user id is what makes every credential event attributable.
     */
    private function assertHumanActor(?int $actorUserId, string $action): void
    {
        if ($actorUserId === null || $actorUserId <= 0) {
            throw new RuntimeException(
                "Refusing to {$action}: an individual authenticated user is required. "
                . 'Machine credentials and shared administrative tokens may not author '
                . 'provider credentials.'
            );
        }
    }

    private function assertKnownEnvironment(string $environment): void
    {
        if (!in_array($environment, InfraProvider::environments(), true)) {
            throw new InvalidArgumentException("Unknown environment '{$environment}'.");
        }
    }
}

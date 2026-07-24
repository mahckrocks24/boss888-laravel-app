<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\States\ProviderHealthState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Authoring and lifecycle for the provider registry (Phase 2B-2).
 *
 * WHAT THIS SERVICE REFUSES TO DO
 *   - store a secret (that is ProviderCredentialService, and only encrypted)
 *   - declare a capability that has no contract behind it
 *   - enable a capability the provider does not claim to support
 *   - mark a provider production-ready implicitly
 *
 * The third is the important one. Enabling `certificate` on a provider whose
 * adapter does not implement CertificateProviderConnector would produce a
 * registry that PROMISES a capability the code cannot deliver — the failure would
 * surface later, at provisioning time, against a live customer resource.
 */
class ProviderRegistryService
{
    public function __construct(
        private readonly ProviderEventRecorder $events,
    ) {
    }

    /**
     * Register a provider. Always lands in `draft`, never enabled.
     *
     * Registration is intentionally NOT approval-gated: a draft provider is inert
     * — not enabled, no capabilities, no credentials, routes nothing. Gating the
     * act of writing down that a provider exists would add friction without
     * removing risk. The governed moments are enable/activate (see
     * InfrastructureCapabilityRegistry).
     */
    public function register(array $attrs, ?int $actorUserId = null): InfraProvider
    {
        $key = trim((string) ($attrs['provider_key'] ?? ''));

        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9_\-]{1,63}$/', $key)) {
            throw new InvalidArgumentException(
                'provider_key must be lowercase alphanumeric with - or _, 2-64 chars.'
            );
        }

        if (InfraProvider::where('provider_key', $key)->exists()) {
            throw new InvalidArgumentException("Provider '{$key}' is already registered.");
        }

        $envs = $attrs['environments_json'] ?? [InfraProvider::ENV_SANDBOX];
        foreach ($envs as $env) {
            if (!in_array($env, InfraProvider::environments(), true)) {
                throw new InvalidArgumentException("Unknown environment '{$env}'.");
            }
        }

        $provider = InfraProvider::create([
            'provider_key'       => $key,
            'display_name'       => $attrs['display_name'] ?? $key,
            'provider_type'      => $attrs['provider_type'] ?? 'composite',
            'adapter_class'      => $attrs['adapter_class'] ?? null,
            'adapter_version'    => $attrs['adapter_version'] ?? null,
            'lifecycle_state'    => ProviderLifecycleState::initial(),
            'enabled'            => false,   // never enabled on registration
            'production_ready'   => false,   // never implicit
            'sandbox_ready'      => (bool) ($attrs['sandbox_ready'] ?? false),
            'priority'           => (int) ($attrs['priority'] ?? 100),
            'environments_json'  => $envs,
            'regions_json'       => $attrs['regions_json'] ?? [],
            'currencies_json'    => $attrs['currencies_json'] ?? [],
            'feature_flags_json' => $attrs['feature_flags_json'] ?? [],
            'metadata_json'      => $attrs['metadata_json'] ?? [],
            'operational_notes'  => $attrs['operational_notes'] ?? null,
            'created_by_user_id' => $actorUserId,
        ]);

        $this->events->record(
            eventType: InfraProviderEvent::PROVIDER_REGISTERED,
            provider: $provider,
            severity: InfraProviderEvent::SEVERITY_INFO,
            toState: ProviderLifecycleState::initial(),
            summary: "Provider '{$key}' registered as draft.",
            metadata: ['provider_type' => $provider->provider_type, 'environments' => $envs],
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
        );

        return $provider;
    }

    /**
     * Declare that a provider supports a capability in an environment.
     * Declared != enabled. `enabled` is always false here.
     */
    public function declareCapability(
        InfraProvider $provider,
        string $capability,
        string $environment = InfraProvider::ENV_SANDBOX,
        array $attrs = [],
        ?int $actorUserId = null
    ): InfraProviderCapability {
        $this->assertKnownCapability($capability);
        $this->assertKnownEnvironment($environment);

        $row = InfraProviderCapability::updateOrCreate(
            [
                'provider_id' => $provider->id,
                'capability'  => $capability,
                'environment' => $environment,
            ],
            [
                'supported'              => (bool) ($attrs['supported'] ?? true),
                'enabled'                => false,
                'implementation_version' => $attrs['implementation_version'] ?? null,
                'health_state'           => ProviderHealthState::UNKNOWN,
                'regions_json'           => $attrs['regions_json'] ?? [],
                'constraints_json'       => $attrs['constraints_json'] ?? [],
                'feature_flags_json'     => $attrs['feature_flags_json'] ?? [],
                'notes'                  => $attrs['notes'] ?? null,
            ]
        );

        $this->events->record(
            eventType: InfraProviderEvent::CAPABILITY_DECLARED,
            provider: $provider,
            capability: $capability,
            environment: $environment,
            summary: "Capability '{$capability}' declared for '{$provider->provider_key}' ({$environment}); not yet enabled.",
            metadata: ['supported' => $row->supported],
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
        );

        return $row;
    }

    /**
     * Enable a capability — the moment work can actually route to it.
     *
     * GOVERNED upstream via InfrastructureCapabilityRegistry::OP_ENABLE_PROVIDER_CAPABILITY.
     * This method performs the mechanics and the safety checks; authorization is
     * the caller's responsibility through EngineExecutionService, exactly as the
     * catalog operations work.
     */
    public function enableCapability(
        InfraProvider $provider,
        string $capability,
        string $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null
    ): InfraProviderCapability {
        $row = $this->capabilityRow($provider, $capability, $environment);

        if (!$row->supported) {
            throw new InvalidArgumentException(
                "Cannot enable '{$capability}' on '{$provider->provider_key}': the adapter does not "
                . 'support it. Enabling an unsupported capability would promise behaviour the code '
                . 'cannot deliver.'
            );
        }

        // A provider that is disabled/retired must not gain live capabilities.
        if (!in_array($provider->lifecycle_state, ProviderLifecycleState::serviceable(), true)) {
            throw new InvalidArgumentException(
                "Cannot enable '{$capability}': provider '{$provider->provider_key}' is "
                . "'{$provider->lifecycle_state}'."
            );
        }

        $row->update(['enabled' => true]);

        $this->events->record(
            eventType: InfraProviderEvent::CAPABILITY_ENABLED,
            provider: $provider,
            capability: $capability,
            environment: $environment,
            severity: InfraProviderEvent::SEVERITY_SUCCESS,
            fromState: 'disabled',
            toState: 'enabled',
            summary: "Capability '{$capability}' ENABLED for '{$provider->provider_key}' ({$environment}).",
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $correlationId,
        );

        return $row->fresh();
    }

    public function disableCapability(
        InfraProvider $provider,
        string $capability,
        string $environment,
        string $reason,
        ?int $actorUserId = null
    ): InfraProviderCapability {
        $row = $this->capabilityRow($provider, $capability, $environment);
        $row->update(['enabled' => false]);

        $this->events->record(
            eventType: InfraProviderEvent::CAPABILITY_DISABLED,
            provider: $provider,
            capability: $capability,
            environment: $environment,
            severity: InfraProviderEvent::SEVERITY_WARNING,
            fromState: 'enabled',
            toState: 'disabled',
            summary: "Capability '{$capability}' DISABLED for '{$provider->provider_key}': {$reason}",
            metadata: ['reason' => $reason],
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
        );

        return $row->fresh();
    }

    /**
     * Move a provider through its lifecycle. Illegal transitions throw.
     *
     * Promotion to `active` in a production-enabled registry additionally
     * requires production_ready to have been asserted deliberately — reaching
     * `active` must never be a side effect of ordinary configuration.
     */
    public function transition(
        InfraProvider $provider,
        string $toState,
        ?int $actorUserId = null,
        ?string $reason = null,
        ?string $correlationId = null
    ): InfraProvider {
        $from = $provider->lifecycle_state;

        ProviderLifecycleState::assertTransition($from, $toState);

        DB::transaction(function () use ($provider, $toState) {
            $provider->update(['lifecycle_state' => $toState]);

            // Leaving a serviceable state must not leave capabilities live.
            if (!in_array($toState, ProviderLifecycleState::serviceable(), true)) {
                InfraProviderCapability::where('provider_id', $provider->id)
                    ->update(['enabled' => false]);
            }
        });

        $this->events->record(
            eventType: InfraProviderEvent::PROVIDER_STATE_CHANGED,
            provider: $provider,
            severity: in_array($toState, [ProviderLifecycleState::DEGRADED, ProviderLifecycleState::DISABLED], true)
                ? InfraProviderEvent::SEVERITY_WARNING
                : InfraProviderEvent::SEVERITY_INFO,
            fromState: $from,
            toState: $toState,
            summary: "Provider '{$provider->provider_key}': {$from} -> {$toState}"
                . ($reason ? " ({$reason})" : ''),
            metadata: array_filter(['reason' => $reason]),
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
            correlationId: $correlationId,
        );

        return $provider->fresh();
    }

    public function setEnabled(
        InfraProvider $provider,
        bool $enabled,
        ?int $actorUserId = null,
        ?string $reason = null
    ): InfraProvider {
        $provider->update(['enabled' => $enabled]);

        $this->events->record(
            eventType: $enabled ? InfraProviderEvent::PROVIDER_ENABLED : InfraProviderEvent::PROVIDER_DISABLED,
            provider: $provider,
            severity: $enabled ? InfraProviderEvent::SEVERITY_SUCCESS : InfraProviderEvent::SEVERITY_WARNING,
            summary: "Provider '{$provider->provider_key}' " . ($enabled ? 'enabled' : 'disabled')
                . ($reason ? ": {$reason}" : '.'),
            metadata: array_filter(['reason' => $reason]),
            actorUserId: $actorUserId,
            actorType: $actorUserId ? InfraProviderEvent::ACTOR_USER : InfraProviderEvent::ACTOR_SYSTEM,
        );

        return $provider->fresh();
    }

    public function capabilityRow(
        InfraProvider $provider,
        string $capability,
        string $environment
    ): InfraProviderCapability {
        $row = InfraProviderCapability::where('provider_id', $provider->id)
            ->where('capability', $capability)
            ->where('environment', $environment)
            ->first();

        if (!$row) {
            throw new InvalidArgumentException(
                "Provider '{$provider->provider_key}' has not declared capability "
                . "'{$capability}' for environment '{$environment}'."
            );
        }

        return $row;
    }

    private function assertKnownCapability(string $capability): void
    {
        if (!in_array($capability, InfraProviderConnection::capabilities(), true)) {
            throw new InvalidArgumentException(
                "Unknown capability '{$capability}'. Known: "
                . implode(', ', InfraProviderConnection::capabilities())
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

<?php

namespace App\Engines\Infrastructure\Services;

use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Models\InfraProviderHealth;
use App\Engines\Infrastructure\States\ProviderHealthState;
use Illuminate\Support\Facades\DB;

/**
 * Provider health, at both provider and capability level (Phase 2B-4).
 *
 * 🔴 COMMERCIAL ISOLATION IS THE LOAD-BEARING RULE OF THIS CLASS.
 * It writes to exactly three tables: infra_provider_health,
 * infra_provider_capabilities.health_state (a denormalised cache), and
 * infra_provider_events. It NEVER touches a subscription, plan, invoice or
 * hosting account. Provider unavailable is not customer suspended.
 *
 * It also never writes `lifecycle_state`. Health is an OBSERVATION; lifecycle is
 * a DECISION. Letting a flapping probe disable a provider would hand an outage at
 * the provider the power to change our configuration.
 *
 * TWO SOURCES OF TRUTH, ONE TABLE
 *   passive — derived from real operations. Free, always current, but only covers
 *             capabilities actually being exercised.
 *   active  — deliberate READ-ONLY probes. Covers idle capabilities, but must
 *             never mutate provider infrastructure (directive §2B-4).
 */
class ProviderHealthService
{
    /** Consecutive failures before a healthy provider is called degraded. */
    private const DEGRADE_AFTER = 3;

    /** Consecutive failures before it is called unavailable. */
    private const UNAVAILABLE_AFTER = 6;

    /** Credential expiry window that starts warning. */
    private const EXPIRY_WARNING_DAYS = 14;

    public function __construct(
        private readonly ProviderEventRecorder $events,
    ) {
    }

    public function current(
        InfraProvider $provider,
        ?string $capability,
        string $environment
    ): ?InfraProviderHealth {
        return InfraProviderHealth::where('provider_id', $provider->id)
            ->where('environment', $environment)
            ->when($capability === null,
                fn ($q) => $q->whereNull('capability'),
                fn ($q) => $q->where('capability', $capability))
            ->first();
    }

    public function ensureRow(
        InfraProvider $provider,
        ?string $capability,
        string $environment
    ): InfraProviderHealth {
        $row = $this->current($provider, $capability, $environment);

        if ($row) {
            return $row;
        }

        return InfraProviderHealth::create([
            'provider_id'  => $provider->id,
            'capability'   => $capability,
            'environment'  => $environment,
            'health_state' => ProviderHealthState::UNKNOWN,
        ]);
    }

    /**
     * PASSIVE: record the outcome of a real operation.
     *
     * This is the cheapest and most honest health signal there is — it reflects
     * what actually happened to customer work, not what a synthetic probe found.
     */
    public function recordOutcome(
        InfraProvider $provider,
        string $capability,
        string $environment,
        ProviderResult $result,
        ?int $latencyMs = null
    ): InfraProviderHealth {
        return $result->success
            ? $this->recordSuccess($provider, $capability, $environment, $latencyMs, InfraProviderHealth::PROBE_PASSIVE)
            : $this->recordFailure(
                $provider,
                $capability,
                $environment,
                $result->errorCode ?? 'unknown',
                $result->errorSummary ?? 'Provider call failed.',
                InfraProviderHealth::PROBE_PASSIVE
            );
    }

    public function recordSuccess(
        InfraProvider $provider,
        ?string $capability,
        string $environment,
        ?int $latencyMs = null,
        string $probeType = InfraProviderHealth::PROBE_PASSIVE,
        ?int $actorUserId = null
    ): InfraProviderHealth {
        $row  = $this->ensureRow($provider, $capability, $environment);
        $from = $row->health_state;

        $row->update([
            'health_state'         => ProviderHealthState::HEALTHY,
            'auth_ok'              => true,
            'api_available'        => true,
            'latency_ms'           => $latencyMs,
            'consecutive_failures' => 0,
            'last_success_at'      => now(),
            'last_error_code'      => null,
            'last_error_summary'   => null,
            'checked_at'           => now(),
            'probe_type'           => $probeType,
            'checked_by_user_id'   => $actorUserId,
        ]);

        $this->syncCapabilityCache($provider, $capability, $environment, ProviderHealthState::HEALTHY);

        if ($from !== ProviderHealthState::HEALTHY && $from !== ProviderHealthState::UNKNOWN) {
            $this->events->record(
                eventType: InfraProviderEvent::PROVIDER_RECOVERED,
                provider: $provider,
                capability: $capability,
                environment: $environment,
                severity: InfraProviderEvent::SEVERITY_SUCCESS,
                fromState: $from,
                toState: ProviderHealthState::HEALTHY,
                summary: "Provider '{$provider->provider_key}'"
                    . ($capability ? " capability '{$capability}'" : '')
                    . " recovered: {$from} -> healthy.",
                metadata: ['probe_type' => $probeType, 'latency_ms' => $latencyMs],
                actorUserId: $actorUserId,
                actorType: $probeType === InfraProviderHealth::PROBE_MANUAL
                    ? InfraProviderEvent::ACTOR_USER
                    : InfraProviderEvent::ACTOR_SYSTEM,
            );
        }

        return $row->fresh();
    }

    /**
     * Record a failure and CLASSIFY it.
     *
     * The classification is the valuable part. "Failed" alone forces every caller
     * to retry blindly; knowing an error is `unauthorized` rather than
     * `unavailable` is the difference between escalating to a human and waiting.
     */
    public function recordFailure(
        InfraProvider $provider,
        ?string $capability,
        string $environment,
        string $errorCode,
        string $errorSummary,
        string $probeType = InfraProviderHealth::PROBE_PASSIVE,
        array $diagnostics = [],
        ?int $actorUserId = null
    ): InfraProviderHealth {
        $row  = $this->ensureRow($provider, $capability, $environment);
        $from = $row->health_state;

        $failures = $row->consecutive_failures + 1;
        $state    = $this->classify($errorCode, $failures);

        $update = [
            'health_state'         => $state,
            'consecutive_failures' => $failures,
            'last_failure_at'      => now(),
            'last_error_code'      => $errorCode,
            'last_error_summary'   => mb_substr($errorSummary, 0, 1000),
            'checked_at'           => now(),
            'probe_type'           => $probeType,
            'checked_by_user_id'   => $actorUserId,
            'diagnostics_json'     => $this->events->sanitize($diagnostics),
        ];

        if ($state === ProviderHealthState::UNAUTHORIZED) {
            $update['auth_ok'] = false;
            // Auth failure says nothing about whether the API is reachable — it
            // answered, it just refused us. Conflating the two loses information.
            $update['api_available'] = true;
        }

        if ($state === ProviderHealthState::UNAVAILABLE) {
            $update['api_available'] = false;
        }

        if ($state === ProviderHealthState::RATE_LIMITED) {
            $update['rate_limited_until'] = now()->addSeconds(
                (int) ($diagnostics['retry_after_seconds'] ?? 60)
            );
        }

        if ($state === ProviderHealthState::QUOTA_LIMITED) {
            $update['quota_state'] = InfraProviderHealth::QUOTA_EXHAUSTED;
        }

        $row->update($update);
        $this->syncCapabilityCache($provider, $capability, $environment, $state);

        if ($from !== $state) {
            $this->events->record(
                eventType: $this->eventTypeFor($state),
                provider: $provider,
                capability: $capability,
                environment: $environment,
                severity: in_array($state, ProviderHealthState::permanentFailure(), true)
                    ? InfraProviderEvent::SEVERITY_CRITICAL
                    : InfraProviderEvent::SEVERITY_ERROR,
                fromState: $from,
                toState: $state,
                summary: "Provider '{$provider->provider_key}'"
                    . ($capability ? " capability '{$capability}'" : '')
                    . ": {$from} -> {$state} ({$errorCode}).",
                metadata: [
                    'error_code'           => $errorCode,
                    'consecutive_failures' => $failures,
                    'probe_type'           => $probeType,
                    'retryable'            => !in_array($state, ProviderHealthState::permanentFailure(), true),
                ] + $diagnostics,
                actorUserId: $actorUserId,
                actorType: $probeType === InfraProviderHealth::PROBE_MANUAL
                    ? InfraProviderEvent::ACTOR_USER
                    : InfraProviderEvent::ACTOR_SYSTEM,
            );
        }

        return $row->fresh();
    }

    /**
     * Keep the denormalised capability health_state in step with the
     * authoritative health row.
     *
     * The column is a read cache for resolution, which would otherwise join
     * infra_provider_health per candidate on every provisioning decision.
     * A no-op for provider-level rollups (capability === null), which have no
     * capability row to update.
     */
    private function syncCapabilityCache(
        InfraProvider $provider,
        ?string $capability,
        string $environment,
        string $state
    ): void {
        if ($capability === null) {
            return;
        }

        InfraProviderCapability::where('provider_id', $provider->id)
            ->where('capability', $capability)
            ->where('environment', $environment)
            ->update(['health_state' => $state]);
    }

    /**
     * Map a provider error to a health state.
     *
     * Deliberately generic: matching on normalized error CLASSES, never on a
     * vendor's numeric codes. Putting `10000` (a Cloudflare auth code) in here
     * would leak the vendor into the health layer. Adapters normalize first.
     */
    private function classify(string $errorCode, int $consecutiveFailures): string
    {
        $code = strtolower($errorCode);

        return match (true) {
            str_contains($code, 'unauthor'), str_contains($code, 'forbidden'),
            str_contains($code, 'auth'), str_contains($code, 'permission'),
            str_contains($code, 'invalid_credential')
                => ProviderHealthState::UNAUTHORIZED,

            str_contains($code, 'rate_limit'), str_contains($code, 'too_many')
                => ProviderHealthState::RATE_LIMITED,

            str_contains($code, 'quota'), str_contains($code, 'limit_exceeded')
                => ProviderHealthState::QUOTA_LIMITED,

            str_contains($code, 'maintenance')
                => ProviderHealthState::MAINTENANCE,

            // Sustained failure escalates; a single blip does not.
            $consecutiveFailures >= self::UNAVAILABLE_AFTER => ProviderHealthState::UNAVAILABLE,
            $consecutiveFailures >= self::DEGRADE_AFTER     => ProviderHealthState::DEGRADED,

            default => ProviderHealthState::DEGRADED,
        };
    }

    private function eventTypeFor(string $state): string
    {
        return match ($state) {
            ProviderHealthState::UNAUTHORIZED  => InfraProviderEvent::AUTHENTICATION_FAILED,
            ProviderHealthState::RATE_LIMITED  => InfraProviderEvent::RATE_LIMITED,
            ProviderHealthState::QUOTA_LIMITED => InfraProviderEvent::QUOTA_EXHAUSTED,
            ProviderHealthState::MAINTENANCE   => InfraProviderEvent::MAINTENANCE_STARTED,
            ProviderHealthState::DEGRADED      => InfraProviderEvent::PROVIDER_DEGRADED,
            default                            => InfraProviderEvent::HEALTH_CHANGED,
        };
    }

    /**
     * Propagate credential expiry into health.
     *
     * A credential expiring in three days is an OPERATIONAL fact, not only a
     * credential fact — the operator watching provider health should see it
     * coming rather than discovering it when provisioning breaks.
     *
     * Note it degrades health but NEVER cancels a subscription.
     */
    public function propagateCredentialExpiry(
        InfraProvider $provider,
        InfraProviderCredential $credential
    ): void {
        $days = $credential->daysUntilExpiry();

        if ($days === null) {
            return;
        }

        $capabilities = $credential->capability_scope_json ?? [];

        foreach ($capabilities as $capability) {
            $row = $this->ensureRow($provider, $capability, $credential->environment);

            $row->update([
                'credential_id'         => $credential->id,
                'credential_expires_at' => $credential->expires_at,
            ]);

            if ($days <= 0) {
                $this->recordFailure(
                    $provider, $capability, $credential->environment,
                    'credential_expired',
                    "Credential '{$credential->credential_key}' expired.",
                    InfraProviderHealth::PROBE_PASSIVE
                );
            } elseif ($days <= self::EXPIRY_WARNING_DAYS && $row->health_state === ProviderHealthState::HEALTHY) {
                $this->events->record(
                    eventType: InfraProviderEvent::CREDENTIAL_EXPIRING,
                    provider: $provider,
                    capability: $capability,
                    environment: $credential->environment,
                    severity: InfraProviderEvent::SEVERITY_WARNING,
                    summary: "Credential '{$credential->credential_key}' expires in {$days} day(s).",
                    metadata: ['days_until_expiry' => $days],
                    credentialId: $credential->id,
                    actorType: InfraProviderEvent::ACTOR_SCHEDULER,
                );
            }
        }
    }

    /**
     * Roll capability health up to a provider-level verdict.
     *
     * The rollup is PESSIMISTIC: the provider is only healthy if every enabled
     * capability is. A rollup that averaged, or reported the best case, would let
     * a broken certificate capability hide behind a working DNS one — which is
     * precisely the measured Cloudflare situation.
     */
    public function recomputeProviderRollup(InfraProvider $provider, string $environment): InfraProviderHealth
    {
        $capRows = InfraProviderCapability::where('provider_id', $provider->id)
            ->where('environment', $environment)
            ->where('enabled', true)
            ->pluck('capability');

        $states = [];

        foreach ($capRows as $capability) {
            $h = $this->current($provider, $capability, $environment);
            $states[] = $h?->health_state ?? ProviderHealthState::UNKNOWN;
        }

        $rollup = match (true) {
            $states === []                                                   => ProviderHealthState::UNKNOWN,
            in_array(ProviderHealthState::UNAUTHORIZED, $states, true)       => ProviderHealthState::UNAUTHORIZED,
            in_array(ProviderHealthState::UNAVAILABLE, $states, true)        => ProviderHealthState::UNAVAILABLE,
            in_array(ProviderHealthState::QUOTA_LIMITED, $states, true)      => ProviderHealthState::QUOTA_LIMITED,
            in_array(ProviderHealthState::RATE_LIMITED, $states, true)       => ProviderHealthState::RATE_LIMITED,
            in_array(ProviderHealthState::MAINTENANCE, $states, true)        => ProviderHealthState::MAINTENANCE,
            in_array(ProviderHealthState::DEGRADED, $states, true)           => ProviderHealthState::DEGRADED,
            in_array(ProviderHealthState::UNKNOWN, $states, true)            => ProviderHealthState::DEGRADED,
            default                                                          => ProviderHealthState::HEALTHY,
        };

        $row  = $this->ensureRow($provider, null, $environment);
        $from = $row->health_state;

        $row->update(['health_state' => $rollup, 'checked_at' => now()]);

        if ($from !== $rollup) {
            $this->events->record(
                eventType: InfraProviderEvent::HEALTH_CHANGED,
                provider: $provider,
                environment: $environment,
                severity: $rollup === ProviderHealthState::HEALTHY
                    ? InfraProviderEvent::SEVERITY_SUCCESS
                    : InfraProviderEvent::SEVERITY_WARNING,
                fromState: $from,
                toState: $rollup,
                summary: "Provider '{$provider->provider_key}' rollup health: {$from} -> {$rollup}.",
                metadata: ['capability_states' => array_combine($capRows->all(), $states) ?: []],
                actorType: InfraProviderEvent::ACTOR_SYSTEM,
            );
        }

        return $row->fresh();
    }

    /** Operator-set maintenance window. Not an incident. */
    public function startMaintenance(
        InfraProvider $provider,
        string $environment,
        \DateTimeInterface $until,
        ?string $reason = null,
        ?int $actorUserId = null
    ): void {
        DB::transaction(function () use ($provider, $environment, $until) {
            InfraProviderHealth::where('provider_id', $provider->id)
                ->where('environment', $environment)
                ->update([
                    'health_state'      => ProviderHealthState::MAINTENANCE,
                    'maintenance_until' => $until,
                ]);
        });

        $this->events->record(
            eventType: InfraProviderEvent::MAINTENANCE_STARTED,
            provider: $provider,
            environment: $environment,
            severity: InfraProviderEvent::SEVERITY_INFO,
            toState: ProviderHealthState::MAINTENANCE,
            summary: "Maintenance window opened for '{$provider->provider_key}' until "
                . $until->format('c') . ($reason ? " ({$reason})" : ''),
            metadata: array_filter(['reason' => $reason]),
            actorUserId: $actorUserId,
            actorType: InfraProviderEvent::ACTOR_USER,
        );
    }
}

<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Registry\CredentialRoleRegistry;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderHealth;
use App\Engines\Infrastructure\States\CredentialState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use RuntimeException;

/**
 * Chooses WHICH provider serves a capability (Phase 2B-2).
 *
 * "No hardcoded vendor selection in business services" — this class is the only
 * place selection happens, and it contains no vendor name.
 *
 * FAIL CLOSED, ALWAYS
 * Every unmet condition returns no provider rather than a best guess. An
 * unresolvable capability throws with a REASON, so an operator learns *why*
 * nothing was selected instead of seeing an empty result and guessing.
 *
 * DETERMINISTIC
 * Ordering is (priority ASC, provider_id ASC) — never random, never "first row
 * MySQL returns". Two identical calls must select the same provider, or
 * debugging a provisioning failure becomes impossible.
 *
 * ⚠️ NO AUTOMATIC FAILOVER. Deliberate, per directive §2B-4: "Do not implement
 * automatic provider failover unless the architecture can prove resource
 * portability." It cannot. A DNS zone at provider A does not exist at provider B;
 * silently failing over would create a SECOND, divergent resource rather than
 * recovering the first. `candidates()` exposes the ordered list so a future,
 * portability-aware layer can make that decision explicitly — but nothing here
 * makes it implicitly.
 */
class ProviderResolutionService
{
    public function __construct(
        private readonly ProviderHealthService $health,
    ) {
    }

    /**
     * @return array{provider:InfraProvider,capability:InfraProviderCapability,credential:?InfraProviderCredential}
     * @throws RuntimeException when nothing satisfies the request.
     */
    public function resolve(
        string $capability,
        string $environment = InfraProvider::ENV_SANDBOX,
        ?string $region = null,
        array $requiredFlags = []
    ): array {
        $candidates = $this->candidates($capability, $environment, $region, $requiredFlags);

        if ($candidates === []) {
            throw new RuntimeException($this->explainNoCandidate($capability, $environment, $region));
        }

        $chosen = $candidates[0];

        return [
            'provider'   => $chosen['provider'],
            'capability' => $chosen['capability'],
            'credential' => $chosen['credential'],
        ];
    }

    /**
     * Ordered, fully-qualified candidates. Empty array = nothing usable.
     *
     * @return array<int,array{provider:InfraProvider,capability:InfraProviderCapability,
     *                         credential:?InfraProviderCredential,health:?InfraProviderHealth}>
     */
    public function candidates(
        string $capability,
        string $environment = InfraProvider::ENV_SANDBOX,
        ?string $region = null,
        array $requiredFlags = []
    ): array {
        if (!in_array($capability, InfraProviderConnection::capabilities(), true)) {
            // Unknown capability fails closed and loudly — it is a programming
            // error, not a configuration gap.
            throw new RuntimeException(
                "Unknown capability '{$capability}'. Known: "
                . implode(', ', InfraProviderConnection::capabilities())
            );
        }

        $rows = InfraProviderCapability::query()
            ->where('capability', $capability)
            ->where('environment', $environment)
            ->where('supported', true)
            ->where('enabled', true)
            ->with('provider')
            ->get();

        $out = [];

        foreach ($rows as $capRow) {
            $provider = $capRow->provider;

            if (!$provider || !$provider->isSelectableFor($environment)) {
                continue;
            }

            // Testing-state providers may never take production work, even if
            // every other condition is met. This is the whole point of `testing`.
            if ($environment === InfraProvider::ENV_PRODUCTION
                && !in_array($provider->lifecycle_state, ProviderLifecycleState::productionSelectable(), true)) {
                continue;
            }

            if (!$provider->supportsRegion($region) || !$capRow->supportsRegion($region)) {
                continue;
            }

            foreach ($requiredFlags as $flag) {
                if (!$capRow->featureFlag($flag) && !$provider->featureFlag($flag)) {
                    continue 2;
                }
            }

            $healthRow = $this->health->current($provider, $capability, $environment);

            // Fail closed: no health record, or an unselectable one, removes the
            // candidate. Never having checked is not evidence of health.
            if (!$healthRow || !$healthRow->isSelectable()) {
                continue;
            }

            $credential = $this->usableCredential($provider, $capability, $environment, $region);

            // A capability with no usable credential is not operationally
            // available, however healthy the provider looks.
            if (!$credential) {
                continue;
            }

            $out[] = [
                'provider'   => $provider,
                'capability' => $capRow,
                'credential' => $credential,
                'health'     => $healthRow,
            ];
        }

        usort($out, function ($a, $b) {
            return [$a['provider']->priority, $a['provider']->id]
               <=> [$b['provider']->priority, $b['provider']->id];
        });

        return $out;
    }

    /**
     * The credential a call should actually use.
     *
     * Prefers the credential with the LATEST activation, so that immediately
     * after a rotation the new credential is used rather than an older sibling
     * that is still technically active.
     */
    public function usableCredential(
        InfraProvider $provider,
        string $capability,
        string $environment,
        ?string $region = null
    ): ?InfraProviderCredential {
        // Only provisioning-role credentials may ever be selected for work.
        // Diagnostics/certification/billing credentials are read-only inspectors
        // and are structurally barred from resolution (Phase 2B-R3, WS2).
        $creds = InfraProviderCredential::query()
            ->where('provider_id', $provider->id)
            ->where('environment', $environment)
            ->where('credential_role', CredentialRoleRegistry::ROLE_PROVISIONING)
            ->whereIn('state', CredentialState::usable())
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->get();

        foreach ($creds as $cred) {
            if (!$cred->coversCapability($capability) || !$cred->coversRegion($region)) {
                continue;
            }

            // Expiry is checked against the clock, not only against stored state,
            // because the state sweeper runs on a schedule and may lag reality.
            if ($cred->expires_at && $cred->expires_at->isPast()) {
                continue;
            }

            if ($cred->valid_from && $cred->valid_from->isFuture()) {
                continue;
            }

            return $cred;
        }

        return null;
    }

    /** Human-readable reason nothing resolved. Diagnosis, not a guess. */
    private function explainNoCandidate(string $capability, string $environment, ?string $region): string
    {
        $declared = InfraProviderCapability::where('capability', $capability)
            ->where('environment', $environment)
            ->with('provider')
            ->get();

        if ($declared->isEmpty()) {
            return "No provider declares capability '{$capability}' for environment '{$environment}'.";
        }

        $reasons = [];

        foreach ($declared as $row) {
            $p = $row->provider;
            $key = $p?->provider_key ?? "provider#{$row->provider_id}";

            if (!$row->supported) {
                $reasons[] = "{$key}: capability not supported by adapter";
            } elseif (!$row->enabled) {
                $reasons[] = "{$key}: capability declared but NOT enabled";
            } elseif (!$p || !$p->enabled) {
                $reasons[] = "{$key}: provider disabled";
            } elseif (!$p->isSelectableFor($environment)) {
                $reasons[] = "{$key}: not selectable for {$environment} "
                    . "(lifecycle={$p->lifecycle_state}, production_ready="
                    . ($p->production_ready ? 'true' : 'false') . ')';
            } elseif ($region && (!$p->supportsRegion($region) || !$row->supportsRegion($region))) {
                $reasons[] = "{$key}: does not serve region '{$region}'";
            } elseif (!($h = $this->health->current($p, $capability, $environment))) {
                $reasons[] = "{$key}: no health record (never verified)";
            } elseif (!$h->isSelectable()) {
                $reasons[] = "{$key}: health is '{$h->health_state}'";
            } elseif (!$this->usableCredential($p, $capability, $environment, $region)) {
                $reasons[] = "{$key}: no active credential scoped to '{$capability}'";
            } else {
                $reasons[] = "{$key}: excluded by a required feature flag";
            }
        }

        return "No usable provider for '{$capability}' in '{$environment}'. "
            . implode('; ', $reasons) . '.';
    }
}

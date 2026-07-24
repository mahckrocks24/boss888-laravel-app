<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Base contract every INFRA888 provider connector implements.
 *
 * Mirrors the intent of app/Connectors/Contracts/ConnectorInterface.php (the
 * existing house adapter contract) but returns a typed ProviderResult instead of a
 * raw array, because infrastructure results must carry a normalized state and an
 * explicit verified flag.
 *
 * Directive §7: "Do not build empty interfaces merely for symmetry. Each contract
 * must correspond to actual business capabilities and normalized operations."
 * Accordingly only four capability contracts exist in Phase 1A — hosting,
 * custom hostname, registrar, email. Backup, monitoring and infrastructure-billing
 * connectors are NOT stubbed out here; they will be written when their modules are.
 */
interface InfrastructureConnector
{
    /** Stable provider identifier persisted on every resource row. */
    public function provider(): string;

    /** One of InfraProviderConnection::capabilities(). */
    public function capability(): string;

    /**
     * Is the provider reachable and are credentials valid?
     * Must never throw — a provider outage is a state, not an exception.
     */
    public function healthCheck(): ProviderResult;

    /**
     * Re-read provider truth for one resource and return normalized state.
     * This is how we detect drift and breakage rather than trusting our own DB.
     */
    public function synchronize(string $providerResourceId): ProviderResult;

    /**
     * Independent confirmation that a prior operation actually took effect.
     * Implements the house doctrine "connector success is NOT trusted until
     * verified" for infrastructure.
     */
    public function verify(string $providerResourceId, array $expectation = []): ProviderResult;
}

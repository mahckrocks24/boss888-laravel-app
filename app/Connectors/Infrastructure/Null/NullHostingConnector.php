<?php

namespace App\Connectors\Infrastructure\Null;

use App\Connectors\Infrastructure\Contracts\HostingProviderConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Models\InfraProviderConnection;

/**
 * Deterministic no-op hosting connector.
 *
 * Phase 1B: supports configured success / retryable failure / terminal failure so
 * the governed lifecycle can be proven end to end without a provider.
 *
 * DETERMINISM IS THE POINT (directive §13: "Do not use random behavior in
 * tests"). Outcome is chosen by explicit input only:
 *   1. $spec['simulate']                       — per-request, non-production only
 *   2. config('infrastructure.null_connector.outcome') — environment default
 *   3. 'success'
 *
 * Provider correlation IDs and resource IDs are derived from the idempotency key
 * by hash, so the same request always yields the same identifiers.
 *
 * GUARANTEES: never contacts an external provider, never writes DNS, never
 * provisions a server, never sends email, never creates a certificate, never
 * mutates a live website. There is no network client in this class.
 */
class NullHostingConnector implements HostingProviderConnector
{
    public function provider(): string
    {
        return 'null';
    }

    public function capability(): string
    {
        return InfraProviderConnection::CAPABILITY_HOSTING;
    }

    public function healthCheck(): ProviderResult
    {
        return ProviderResult::verified('active', null, 'null-connector', [
            'note' => 'Null connector: no provider is configured.',
        ]);
    }

    public function synchronize(string $providerResourceId): ProviderResult
    {
        return ProviderResult::accepted('active', $providerResourceId, 'null');
    }

    public function verify(string $providerResourceId, array $expectation = []): ProviderResult
    {
        // Nothing real exists to confirm, and saying otherwise would be the exact
        // false-success pattern removed from the social engine on 2026-07-15.
        return ProviderResult::accepted('active', $providerResourceId, 'null', [
            'note' => 'Null connector cannot independently verify provider state.',
        ]);
    }

    public function provisionHosting(array $spec, string $idempotencyKey): ProviderResult
    {
        $outcome = $this->outcome($spec);
        $correlation = 'null-corr-' . substr(hash('sha256', $idempotencyKey), 0, 16);

        return match ($outcome) {
            'retryable_failure' => ProviderResult::failed(
                errorCode: 'provider_unavailable',
                errorSummary: 'The hosting provider is temporarily unavailable. This will be retried automatically.',
                retryClassification: 'retryable',
                correlationId: $correlation,
                providerState: 'null-simulated-retryable',
            ),
            'terminal_failure' => ProviderResult::failed(
                errorCode: 'provider_rejected',
                errorSummary: 'The hosting provider rejected this request. Please contact support.',
                retryClassification: 'permanent',
                correlationId: $correlation,
                providerState: 'null-simulated-terminal',
            ),
            default => ProviderResult::accepted(
                normalizedState: 'provisioning',
                providerResourceId: 'null-' . substr(hash('sha256', $idempotencyKey), 0, 24),
                providerState: 'null',
                data: array_intersect_key($spec, array_flip(['name', 'region', 'environment'])),
                correlationId: $correlation,
            ),
        };
    }

    public function suspendHosting(string $providerResourceId, string $reason, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::accepted('suspended', $providerResourceId, 'null', [], $idempotencyKey);
    }

    public function restoreHosting(string $providerResourceId, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::accepted('active', $providerResourceId, 'null', [], $idempotencyKey);
    }

    public function terminateHosting(string $providerResourceId, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::accepted('terminated', $providerResourceId, 'null', [], $idempotencyKey);
    }

    public function getHostingStatus(string $providerResourceId): ProviderResult
    {
        return ProviderResult::accepted('active', $providerResourceId, 'null');
    }

    public function getUsage(string $providerResourceId): ProviderResult
    {
        return ProviderResult::accepted('active', $providerResourceId, 'null', [
            'storage_mb'   => 0,
            'bandwidth_mb' => 0,
            'measured_at'  => now()->toIso8601String(),
        ]);
    }

    private function outcome(array $spec): string
    {
        $requested = $spec['simulate'] ?? null;

        if ($requested && !app()->environment('production')) {
            return (string) $requested;
        }

        return (string) config('infrastructure.null_connector.outcome', 'success');
    }
}

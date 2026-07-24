<?php

namespace App\Connectors\Infrastructure\Null;

use App\Connectors\Infrastructure\Contracts\CustomHostnameConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Models\InfraProviderConnection;

/**
 * Deterministic no-op custom-hostname connector.
 *
 * Phase 1A default. The real Cloudflare-for-SaaS implementation is blocked on
 * decisions D1 (zone account ownership) and D2 (token scopes) recorded in the
 * Phase 0.1 readiness audit, and must not be written against an unverified API.
 */
class NullCustomHostnameConnector implements CustomHostnameConnector
{
    public function provider(): string
    {
        return 'null';
    }

    public function capability(): string
    {
        return InfraProviderConnection::CAPABILITY_CUSTOM_HOSTNAME;
    }

    public function healthCheck(): ProviderResult
    {
        return ProviderResult::verified('active', null, 'null-connector', [
            'note' => 'Null connector: no custom-hostname provider is configured.',
        ]);
    }

    public function synchronize(string $providerResourceId): ProviderResult
    {
        return $this->synchronizeHostname($providerResourceId);
    }

    public function verify(string $providerResourceId, array $expectation = []): ProviderResult
    {
        return ProviderResult::accepted('awaiting_dns', $providerResourceId, 'null', [
            'note' => 'Null connector cannot independently verify hostname state.',
        ]);
    }

    public function createHostname(string $hostname, array $options, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::accepted(
            normalizedState: 'awaiting_dns',
            providerResourceId: 'null-host-' . substr(hash('sha256', $hostname . $idempotencyKey), 0, 20),
            providerState: 'null',
            data: ['hostname' => $hostname],
            correlationId: $idempotencyKey,
        );
    }

    public function getHostname(string $providerResourceId): ProviderResult
    {
        return ProviderResult::accepted('awaiting_dns', $providerResourceId, 'null');
    }

    public function getValidationInstructions(string $providerResourceId): ProviderResult
    {
        // Shaped like a real response so consumers are built against the right
        // contract, but explicitly flagged as non-actionable.
        return ProviderResult::accepted('awaiting_dns', $providerResourceId, 'null', [
            'method'  => 'cname',
            'records' => [],
            'note'    => 'No provider configured — no real DNS instructions available.',
        ]);
    }

    public function synchronizeHostname(string $providerResourceId): ProviderResult
    {
        return ProviderResult::accepted('awaiting_dns', $providerResourceId, 'null');
    }

    public function disconnectHostname(string $providerResourceId, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::accepted('disconnected', $providerResourceId, 'null', [], $idempotencyKey);
    }
}

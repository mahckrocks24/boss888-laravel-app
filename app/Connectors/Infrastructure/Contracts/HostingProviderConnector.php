<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Hosting capability contract.
 *
 * Every method takes an idempotency key: directive §10 requires idempotency, and
 * TaskExecutionJob retries 4x with backoff, so a non-idempotent provision would
 * create four accounts.
 *
 * Note what is absent: there is no createServer(), no rebootServer(), no
 * sshCommand(). Directive §16 — the customer owns a service entitlement, not a
 * VPS object, and INFRA888 is not a server-management interface.
 */
interface HostingProviderConnector extends InfrastructureConnector
{
    /**
     * @param array{name:string,region?:string,environment?:string,
     *              storage_mb?:int,bandwidth_mb?:int,sites?:int} $spec
     */
    public function provisionHosting(array $spec, string $idempotencyKey): ProviderResult;

    public function suspendHosting(string $providerResourceId, string $reason, string $idempotencyKey): ProviderResult;

    public function restoreHosting(string $providerResourceId, string $idempotencyKey): ProviderResult;

    /** Destructive and irreversible. Always approval-gated upstream. */
    public function terminateHosting(string $providerResourceId, string $idempotencyKey): ProviderResult;

    public function getHostingStatus(string $providerResourceId): ProviderResult;

    /**
     * Returns normalized usage in ProviderResult::$data as:
     *   ['storage_mb' => int, 'bandwidth_mb' => int, 'measured_at' => ISO8601]
     */
    public function getUsage(string $providerResourceId): ProviderResult;
}

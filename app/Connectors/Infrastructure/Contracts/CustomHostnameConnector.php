<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Custom-hostname capability (ADR-001: Cloudflare for SaaS is the first
 * implementation, but nothing here names it).
 *
 * getValidationInstructions() is a first-class method rather than a hardcoded
 * template. The legacy CustomDomainService returned instructions telling the
 * customer to create a CNAME it had already created itself (:78-84) because the
 * instructions were assumed rather than read from the provider. Instructions must
 * be DERIVED from the provider's actual response.
 */
interface CustomHostnameConnector extends InfrastructureConnector
{
    public function createHostname(string $hostname, array $options, string $idempotencyKey): ProviderResult;

    public function getHostname(string $providerResourceId): ProviderResult;

    /**
     * Returns customer-facing DNS instructions in ProviderResult::$data as:
     *   ['records' => [['type'=>'CNAME','name'=>'...','value'=>'...','ttl'=>int], ...],
     *    'method'  => 'txt'|'http'|'cname']
     */
    public function getValidationInstructions(string $providerResourceId): ProviderResult;

    public function synchronizeHostname(string $providerResourceId): ProviderResult;

    /** Destructive. Approval-gated upstream. */
    public function disconnectHostname(string $providerResourceId, string $idempotencyKey): ProviderResult;
}

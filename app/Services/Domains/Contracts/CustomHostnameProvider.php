<?php

namespace App\Services\Domains\Contracts;

use App\Services\Domains\CustomHostnameResult;
use App\Services\Domains\ProviderException;

/**
 * Provider-neutral contract for connecting external custom hostnames to a SaaS
 * platform. All provider-specific API behaviour (Cloudflare for SaaS, or any
 * future provider) lives behind this interface; the application service never
 * sees a Cloudflare field. Implementations throw {@see ProviderException} on
 * hard/unexpected provider failures and return {@see CustomHostnameResult} for
 * normal outcomes (including "not found" as null where noted).
 */
interface CustomHostnameProvider
{
    public function key(): string;

    /** Create a custom hostname. @throws ProviderException */
    public function createCustomHostname(string $hostname, array $options = []): CustomHostnameResult;

    /** Fetch one by provider id. Null if the provider no longer has it. @throws ProviderException on transport errors */
    public function getCustomHostname(string $providerHostnameId): ?CustomHostnameResult;

    /** List hostnames matching an exact hostname (for orphan detection / idempotency). @return CustomHostnameResult[] */
    public function listCustomHostnames(string $hostname): array;

    /** Re-trigger domain-control validation, then return the fresh snapshot. @throws ProviderException */
    public function verifyCustomHostname(string $providerHostnameId): CustomHostnameResult;

    /** Delete a custom hostname. Returns true if deleted OR already absent (idempotent). @throws ProviderException on transport errors */
    public function deleteCustomHostname(string $providerHostnameId): bool;

    /**
     * Customer-facing DNS instructions derived from a result: the routing CNAME
     * plus any ownership/SSL validation record. Provider-neutral shape:
     *   [ ['type'=>'CNAME'|'TXT','name'=>..,'value'=>..,'purpose'=>'routing|ownership|ssl'], ... ]
     */
    public function getValidationInstructions(CustomHostnameResult $result): array;

    /** Normalized SSL/certificate status for a hostname, or null. */
    public function getCertificateStatus(string $providerHostnameId): ?string;
}

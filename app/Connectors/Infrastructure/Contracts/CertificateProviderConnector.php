<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * TLS certificate capability contract (Phase 2B-1).
 *
 * 🔴 THIS CONTRACT FIXES A LEAKED PROVIDER ASSUMPTION.
 *
 * Until now, SSL had no capability constant and no contract. Certificate
 * lifecycle was implicit inside `CustomHostnameConnector`, where issuance and
 * renewal are bundled into hostname creation.
 *
 * That bundling is **Cloudflare-for-SaaS shaped thinking**. It is true for
 * Cloudflare, and false almost everywhere else:
 *
 *   Cloudflare for SaaS : cert issued and renewed as part of the custom hostname
 *   Let's Encrypt / ACME: cert is a separate object with its own lifecycle
 *   Cloudflare Origin CA: separate, 15-year, unrelated to custom hostnames
 *   Uploaded/BYO cert    : supplied by the customer, no issuance at all
 *
 * Had the abstraction stayed as-is, swapping Cloudflare for an ACME-based
 * provider would have required changing business services — exactly the
 * dependency the architecture exists to prevent. Certificates therefore get
 * their own capability and their own contract.
 *
 * Additive: `CustomHostnameConnector` is unchanged. A provider that genuinely
 * bundles both may implement both interfaces.
 */
interface CertificateProviderConnector extends InfrastructureConnector
{
    /**
     * Request a certificate for one or more names.
     *
     * @param  array<int,string>  $hostnames  SANs; a single-element array is the common case
     * @param  array{validation_method?:string,key_type?:string,validity_days?:int}  $options
     */
    public function requestCertificate(array $hostnames, array $options, string $idempotencyKey): ProviderResult;

    /**
     * @return ProviderResult data: ['status'=>string,'issued_at'=>?string,'expires_at'=>?string,
     *                               'issuer'=>?string,'hostnames'=>string[]]
     */
    public function getCertificate(string $providerResourceId): ProviderResult;

    /**
     * What the customer must do to prove control. Derived from the provider's
     * actual response, never from a hardcoded template — the legacy
     * CustomDomainService shipped invented instructions and that defect must not
     * be repeated.
     *
     * @return ProviderResult data: ['method'=>'dns-01'|'http-01'|'txt'|'email',
     *                               'records'=>[['type'=>string,'name'=>string,'value'=>string], ...]]
     */
    public function getValidationRequirements(string $providerResourceId): ProviderResult;

    /**
     * Renewal is explicit rather than assumed. Some providers auto-renew, some
     * do not; the commercial layer must be able to ask either way.
     */
    public function renewCertificate(string $providerResourceId, string $idempotencyKey): ProviderResult;

    /** Destructive. Approval-gated upstream. */
    public function revokeCertificate(string $providerResourceId, string $reason, string $idempotencyKey): ProviderResult;

    /**
     * Expiry visibility is a first-class need: the Phase 0 audit found the
     * platform had NO certificate-expiry monitoring at all.
     *
     * @return ProviderResult data: ['expires_at'=>?string,'days_remaining'=>?int,'auto_renews'=>bool]
     */
    public function getExpiry(string $providerResourceId): ProviderResult;
}

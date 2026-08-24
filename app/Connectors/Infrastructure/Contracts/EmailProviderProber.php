<?php

namespace App\Connectors\Infrastructure\Contracts;

/**
 * INFRA888 · E6 — observe raw provider behaviour without knowing the provider.
 *
 * Live validation has to see things the connector deliberately abstracts away:
 * HTTP status codes, response headers, envelope shape, pagination hints. That
 * is legitimately below the connector seam — but it must not drag the vendor's
 * URL grammar up into a console command, which is exactly what the white-label
 * guards caught it doing.
 *
 * So the operator asks for a LOGICAL target — "the mailbox list", "a domain
 * that does not exist" — and the adapter decides what that means. Raw HTTP
 * facts come back; no path, no hostname, no vendor identity.
 */
interface EmailProviderProber
{
    public const TARGET_ACCOUNT          = 'account';
    public const TARGET_DOMAIN           = 'domain';
    public const TARGET_DOMAIN_RECORDS   = 'domain.records';
    public const TARGET_DOMAIN_DIAGNOSE  = 'domain.diagnostics';
    public const TARGET_DOMAIN_USAGE     = 'domain.usage';
    public const TARGET_MAILBOXES        = 'mailboxes';
    public const TARGET_MAILBOX          = 'mailbox';
    public const TARGET_ALIASES          = 'aliases';
    public const TARGET_REWRITES         = 'rewrites';
    public const TARGET_FORWARDINGS      = 'forwardings';
    public const TARGET_MISSING_DOMAIN   = 'missing.domain';
    public const TARGET_MISSING_MAILBOX  = 'missing.mailbox';

    /**
     * @param array{domain?:string,local_part?:string} $args
     *
     * @return array{status:int,headers:array,shape:string,row_count:?int,latency_ms:int,retry_after:?int,top_level_keys:array}
     */
    public function probe(string $target, array $args = []): array;

    /**
     * The same account endpoint with a deliberately invalid credential, to prove
     * an authentication failure maps correctly. The real key is never involved.
     *
     * @return array{status:int,latency_ms:int}
     */
    public function probeUnauthenticated(): array;

    /** Logical targets this adapter can probe. */
    public function targets(): array;
}

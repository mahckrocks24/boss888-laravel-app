<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * DNS capability contract (Phase 2B-1).
 *
 * WHY THIS DID NOT EXIST BEFORE
 * -----------------------------
 * `InfraProviderConnection::CAPABILITY_DNS` has been declared since Phase 1A,
 * but no contract stood behind it. Resolving that capability would have thrown.
 * This closes the gap additively — no existing interface changes.
 *
 * DELIBERATELY PROVIDER-NEUTRAL
 * A DNS provider here is whoever answers for a zone: Cloudflare, Route 53,
 * DNSimple, or the customer's own registrar. Nothing in this interface assumes
 * a proxying CDN, an anycast network, or Cloudflare's record model.
 *
 * Record types are the DNS standard set, not a vendor's subset. `proxied` is
 * deliberately ABSENT from the interface — it is a Cloudflare concept and
 * belongs in an adapter's own options payload, never in the contract.
 */
interface DnsProviderConnector extends InfrastructureConnector
{
    /**
     * Does this provider answer for the given zone, and is it usable?
     *
     * @return ProviderResult data: ['zone_id'=>string,'name'=>string,'active'=>bool,'nameservers'=>string[]]
     */
    public function getZone(string $zoneName): ProviderResult;

    /**
     * @return ProviderResult data: ['records'=>[['id'=>string,'type'=>string,'name'=>string,
     *                                            'content'=>string,'ttl'=>int], ...]]
     */
    public function listRecords(string $zoneId, array $filter = []): ProviderResult;

    /**
     * @param array{type:string,name:string,content:string,ttl?:int,priority?:int,options?:array} $record
     */
    public function createRecord(string $zoneId, array $record, string $idempotencyKey): ProviderResult;

    public function updateRecord(string $zoneId, string $recordId, array $record, string $idempotencyKey): ProviderResult;

    /** Destructive. Approval-gated upstream. */
    public function deleteRecord(string $zoneId, string $recordId, string $idempotencyKey): ProviderResult;

    /**
     * Independent confirmation that a record resolves publicly — NOT merely that
     * the provider's API accepted it. Propagation is the thing customers
     * experience, and "the API said 200" is not evidence of it.
     *
     * @return ProviderResult data: ['resolves'=>bool,'observed'=>string[],'expected'=>string]
     */
    public function verifyRecordPropagation(string $fqdn, string $type, string $expectedContent): ProviderResult;
}

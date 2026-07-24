<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Business email capability contract.
 *
 * Directive §14: INFRA888 does NOT operate a mail server. It orchestrates an
 * established provider. There is therefore no method here for queue management,
 * spam filtering, or MTA configuration.
 *
 * capabilities() exists because providers differ materially — some cannot set a
 * mailbox password via API, some require an administrator to act provider-side.
 * The product must not promise what the selected provider's API cannot do, so
 * capability differences are represented explicitly rather than assumed.
 */
interface EmailProviderConnector extends InfrastructureConnector
{
    /**
     * @return ProviderResult data: [
     *   'can_set_password'=>bool,'can_reset_password'=>bool,'supports_aliases'=>bool,
     *   'supports_forwarders'=>bool,'max_aliases_per_mailbox'=>?int,
     *   'requires_provider_admin'=>bool,'storage_options_mb'=>int[]]
     */
    public function capabilities(): ProviderResult;

    public function verifyDomain(string $domain, string $idempotencyKey): ProviderResult;

    /**
     * @return ProviderResult data: ['spf'=>bool,'dkim'=>bool,'dmarc'=>bool,'mx'=>bool]
     */
    public function getDomainAuthStatus(string $domain): ProviderResult;

    public function createMailbox(string $domain, string $localPart, array $options, string $idempotencyKey): ProviderResult;

    public function suspendMailbox(string $providerResourceId, string $idempotencyKey): ProviderResult;

    /** Destructive — mail is unrecoverable after provider retention lapses. */
    public function deleteMailbox(string $providerResourceId, string $idempotencyKey): ProviderResult;

    public function setMailboxQuota(string $providerResourceId, int $quotaMb, string $idempotencyKey): ProviderResult;

    public function requestPasswordReset(string $providerResourceId, string $idempotencyKey): ProviderResult;

    public function createAlias(string $providerResourceId, string $alias, string $idempotencyKey): ProviderResult;

    public function createForwarder(string $providerResourceId, string $destination, string $idempotencyKey): ProviderResult;
}

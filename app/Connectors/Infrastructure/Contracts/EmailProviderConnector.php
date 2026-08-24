<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapabilitySet;
use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\ProviderResult;

/**
 * Business Email capability contract — FINALISED IN E2.
 *
 * INFRA888 does NOT operate a mail server. It orchestrates an established
 * provider. There is therefore no method here for queue management, spam
 * filtering or MTA configuration, and there never will be.
 *
 * ─── WHAT E2 CHANGED, AND WHY ───────────────────────────────────────────────
 *
 * E1 registered fifteen capabilities and found that five of them had no method
 * on this interface. Rather than invent names, E1 declared them as gaps. E2
 * closes all five and fixes three further defects the review surfaced:
 *
 *  1. RESTORE EXISTED ONLY AS SUSPEND. A contract that can stop mail but not
 *     start it again is a one-way door. Added restoreMailbox().
 *
 *  2. ALIASES AND FORWARDERS COULD BE CREATED BUT NOT REMOVED. Added
 *     deleteAlias() and deleteForwarder(). Both take the ALIAS's or FORWARDER's
 *     own opaque reference — not the mailbox's, which was the shape the old
 *     createAlias() implied and which would have made deletion impossible for
 *     any provider modelling an alias as a first-class object.
 *
 *  3. CATCH-ALL WAS ABSENT ENTIRELY. Added configureCatchAll() AND
 *     clearCatchAll() as separate methods with separate capability flags —
 *     clearing is not configuring-with-a-null, because a spec that means either
 *     depending on one nullable field is a routing accident waiting to happen.
 *
 *  4. USAGE COULD NOT BE READ. Added getUsage(). Note there is deliberately no
 *     `syncUsage()`: the provider is only ever READ, and "sync" is what the
 *     ENGINE does with the answer. Two methods would have implied the provider
 *     was being mutated by a measurement.
 *
 *  5. MAILBOX UPDATE MEANT QUOTA ONLY. `setMailboxQuota()` could not change a
 *     display name, so E1's `email.mailbox.update` capability was mapped to a
 *     method that did not implement it. Replaced by updateMailbox(MailboxSpec).
 *
 *  6. DNS REQUIREMENTS COULD NOT BE ASKED FOR. `getDomainAuthStatus()` reports
 *     whether records are right; nothing reported what they should BE. Added
 *     getDnsRequirements(), returning abstract MailDnsRecord objects rather
 *     than provider prose — see that class for why this is the load-bearing
 *     white-label decision in the whole contract.
 *
 *  7. DOMAIN ONBOARDING WAS IMPLICIT. E1 mapped `email.domain.onboard` to a
 *     read. Registering a domain at a provider is a mutation almost everywhere.
 *     Added onboardDomain().
 *
 *  8. NOTHING COULD ENUMERATE. Without a listing there is no reconciliation,
 *     and without reconciliation drift is undetectable — the platform would
 *     trust its own database indefinitely. Added getInventory().
 *
 * ─── THE TWO METHODS THAT DO NOT RETURN ProviderResult ──────────────────────
 *
 * capabilitySet() and supports() are local declarations, not provider calls.
 * They make no network request and cannot fail. Wrapping them in a result type
 * that models failure, retry classification and verification would be dishonest
 * about what they are, and would make every "should this button exist?" question
 * a potential outage. EVERY method that talks to a provider returns
 * ProviderResult; these two do not talk to anything.
 *
 * ─── RULES EVERY IMPLEMENTATION MUST HONOUR ─────────────────────────────────
 *
 * ACCEPTED IS NOT VERIFIED. Return ProviderResult::accepted() when the provider
 * acknowledged the request, and verified() ONLY when the effect was independently
 * read back. An adapter that returns verified() because an API returned 200 has
 * broken the contract in the way that matters most.
 *
 * AMBIGUITY IS NEITHER. A timeout or indeterminate response on a MUTATING call
 * must be reported as failed() with an ambiguous error code and retry
 * classification `manual` — never as success, and never as a retryable failure.
 * Blind retry after a timeout is how a mailbox gets created twice, and creating
 * a mailbox twice can split a customer's inbound mail.
 *
 * NEVER RETURN A PASSWORD. requestPasswordReset() asks the provider to run its
 * own reset flow. No implementation may return credential material in
 * ProviderResult::$data, because that data is persisted to infra_operations.
 *
 * OPAQUE REFERENCES. Every `*Ref` parameter is a provider-issued string. No
 * implementation may assume, and no caller may parse, its format.
 *
 * NO VENDOR VOCABULARY. Method names, parameter names and capability flags
 * describe what Business Email does, not what any vendor calls it.
 */
interface EmailProviderConnector extends InfrastructureConnector
{
    // ─────────────────────────────────────────────────────────────────────────
    // Capability discovery — local, free, cannot fail.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What this configured provider can actually do.
     *
     * Must be answerable without a network call. A provider that discovers its
     * own capabilities at runtime caches them and returns a set built from the
     * cache.
     */
    public function capabilitySet(): EmailProviderCapabilitySet;

    /**
     * Convenience over capabilitySet(). The engine calls this BEFORE opening a
     * governed operation, so an unsupported action never becomes a provider
     * failure or a queued retry.
     *
     * @param string $capability an EmailProviderCapability constant
     */
    public function supports(string $capability): bool;

    // ─────────────────────────────────────────────────────────────────────────
    // Domain — read
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Is this domain known to the provider, and is mail authentication in place?
     *
     * @return ProviderResult data: ['active'=>bool,'mx'=>bool,'spf'=>bool,'dkim'=>bool,'dmarc'=>bool]
     */
    public function getDomainAuthStatus(string $domain, ProviderCallContext $context): ProviderResult;

    /**
     * What the customer must publish in DNS.
     *
     * @return ProviderResult data: ['records'=>MailDnsRecord[]]
     *
     * Implementations MUST derive these from the provider's actual response.
     * The legacy CustomDomainService shipped invented instructions and that
     * defect must not be repeated here — a customer who publishes a guessed
     * record gets mail that silently does not arrive.
     */
    public function getDnsRequirements(string $domain, ProviderCallContext $context): ProviderResult;

    // ─────────────────────────────────────────────────────────────────────────
    // Domain — mutate
    // ─────────────────────────────────────────────────────────────────────────

    /** Register the domain with the provider. Creates no mailbox. */
    public function onboardDomain(string $domain, ProviderCallContext $context): ProviderResult;

    /**
     * Ask the provider to re-check ownership and authentication.
     *
     * `accepted` means the check was queued; `verified` means the provider
     * confirmed the records are in place. The engine may only mark a domain
     * verified on the latter.
     */
    public function verifyDomain(string $domain, ProviderCallContext $context): ProviderResult;

    // ─────────────────────────────────────────────────────────────────────────
    // Mailbox
    // ─────────────────────────────────────────────────────────────────────────

    /** Billable and quota-consuming. @return ProviderResult providerResourceId = the new mailbox reference */
    /**
     * Create a mailbox — INFRA888 · E7.4.
     *
     * The optional password is the CUSTOMER'S OWN, supplied in the same
     * synchronous request in which they chose it, and is the only credential
     * this platform ever handles. It is a SecretString rather than a field on
     * MailboxSpec because a spec is persisted into operation records and a
     * password must never travel inside one.
     *
     * Null means "create without a credential". A provider that refuses that
     * declares it by failing; the engine does not pretend otherwise.
     */
    public function createMailbox(
        string $domain,
        MailboxSpec $spec,
        ProviderCallContext $context,
        ?\App\Connectors\Infrastructure\BusinessEmail\SecretString $password = null
    ): ProviderResult;

    /** Display name and/or quota. Null fields in the spec mean "leave unchanged". */
    public function updateMailbox(string $mailboxRef, MailboxSpec $spec, ProviderCallContext $context): ProviderResult;

    public function suspendMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult;

    public function restoreMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult;

    /** Destructive — mail is unrecoverable once provider retention lapses. */
    public function deleteMailbox(string $mailboxRef, ProviderCallContext $context): ProviderResult;

    /**
     * Trigger the provider's own reset flow.
     *
     * MUST NOT return a password, a temporary password, a reset token or a reset
     * URL in $data. That payload is persisted to infra_operations.
     */
    public function requestPasswordReset(string $mailboxRef, ProviderCallContext $context): ProviderResult;

    /** @return ProviderResult data: ['mailbox'=>RemoteMailbox] */
    public function getMailboxStatus(string $mailboxRef, ProviderCallContext $context): ProviderResult;

    /**
     * Set a mailbox password on the customer's behalf — INFRA888 · E7.3.
     *
     * A SecretString, deliberately not a MailboxSpec: a spec is a value object
     * that gets logged into operation records and audit rows, and a password
     * must never travel inside one. SecretString cannot be serialised, cannot
     * be stringified, and can be read exactly once.
     *
     * Implementations MUST NOT log the value, place it in a ProviderResult, or
     * retain it after the call. A provider that cannot do this declares
     * MAILBOX_PASSWORD_SET unsupported.
     */
    public function setMailboxPassword(
        string $mailboxRef,
        \App\Connectors\Infrastructure\BusinessEmail\SecretString $password,
        ProviderCallContext $context
    ): ProviderResult;

    // ─────────────────────────────────────────────────────────────────────────
    // Routing
    // ─────────────────────────────────────────────────────────────────────────

    /** @return ProviderResult providerResourceId = the new alias's own reference */
    public function createAlias(string $domain, AliasSpec $spec, ProviderCallContext $context): ProviderResult;

    public function deleteAlias(string $aliasRef, ProviderCallContext $context): ProviderResult;

    /** @return ProviderResult providerResourceId = the new forwarder's own reference */
    public function createForwarder(string $domain, ForwarderSpec $spec, ProviderCallContext $context): ProviderResult;

    public function deleteForwarder(string $forwarderRef, ProviderCallContext $context): ProviderResult;

    /** Set or change where unassigned addresses deliver. */
    public function configureCatchAll(string $domain, CatchAllSpec $spec, ProviderCallContext $context): ProviderResult;

    /** Turn the catch-all off. Separate from configure, deliberately. */
    public function clearCatchAll(string $domain, ProviderCallContext $context): ProviderResult;

    /** @return ProviderResult data: ['catchall'=>RemoteCatchAll] */
    public function getCatchAll(string $domain, ProviderCallContext $context): ProviderResult;

    // ─────────────────────────────────────────────────────────────────────────
    // Observation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Storage and message counters.
     *
     * @return ProviderResult data: ['samples'=>MailboxUsageSample[]]
     *
     * A provider that does not report a given measure MUST return null for it.
     * Substituting zero produces a number the customer cannot tell is invented.
     */
    public function getUsage(string $domain, ProviderCallContext $context): ProviderResult;

    /**
     * Everything the provider believes exists for this domain — the sole input
     * to reconciliation.
     *
     * @return ProviderResult data: ['inventory'=>ProviderInventory]
     *
     * An implementation that cannot enumerate exhaustively MUST return an
     * inventory marked incomplete rather than a short list. A truncated list
     * presented as complete makes every unlisted object look deleted.
     */
    public function getInventory(string $domain, ProviderCallContext $context): ProviderResult;
}

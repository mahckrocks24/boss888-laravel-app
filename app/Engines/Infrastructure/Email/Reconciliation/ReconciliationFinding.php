<?php

namespace App\Engines\Infrastructure\Email\Reconciliation;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — one difference between what we intend and what exists.
 *
 * A FINDING IS A FACT, NOT AN INSTRUCTION. Nothing here says what to do about
 * it, and reconciliation never acts on one. That separation is the whole design:
 * the S6–S8.3 observation stack exists because a monitoring system that also
 * repairs is a monitoring system whose reports you cannot trust — it has already
 * changed the thing it is describing.
 *
 * SEVERITY IS ABOUT THE CUSTOMER, NOT ABOUT US. A missing mailbox means someone
 * is not receiving mail right now; an unexpected one is untidy. Both are drift,
 * and treating them as equally urgent would bury the first under the second.
 */
final class ReconciliationFinding
{
    // ── the thirteen kinds ───────────────────────────────────────────────────
    /** We have a mailbox record; the provider does not. Mail is not arriving. */
    public const MISSING_MAILBOX = 'missing_mailbox';
    /** The provider has a mailbox we never created. Someone used their console. */
    public const UNEXPECTED_MAILBOX = 'unexpected_mailbox';
    public const QUOTA_MISMATCH = 'quota_mismatch';
    public const SUSPENSION_MISMATCH = 'suspension_mismatch';
    public const MISSING_ALIAS = 'missing_alias';
    public const UNEXPECTED_ALIAS = 'unexpected_alias';
    public const MISSING_FORWARDER = 'missing_forwarder';
    public const UNEXPECTED_FORWARDER = 'unexpected_forwarder';
    public const CATCHALL_MISMATCH = 'catchall_mismatch';
    /** We hold a binding whose provider object is gone. */
    public const PROVIDER_OBJECT_MISSING = 'provider_object_missing';
    /** One provider reference used by two objects. A provider or paging defect. */
    public const DUPLICATE_PROVIDER_OBJECT = 'duplicate_provider_object';
    public const USAGE_STALE = 'usage_stale';
    public const PROVIDER_UNREACHABLE = 'provider_unreachable';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_INFO = 'info';

    /** @return array<int,string> */
    public static function kinds(): array
    {
        return [
            self::MISSING_MAILBOX, self::UNEXPECTED_MAILBOX, self::QUOTA_MISMATCH,
            self::SUSPENSION_MISMATCH, self::MISSING_ALIAS, self::UNEXPECTED_ALIAS,
            self::MISSING_FORWARDER, self::UNEXPECTED_FORWARDER, self::CATCHALL_MISMATCH,
            self::PROVIDER_OBJECT_MISSING, self::DUPLICATE_PROVIDER_OBJECT,
            self::USAGE_STALE, self::PROVIDER_UNREACHABLE,
        ];
    }

    /** Default severity per kind. Ordered by what a customer would feel. */
    public static function defaultSeverities(): array
    {
        return [
            // Mail is not being delivered to something we told the customer exists.
            self::MISSING_MAILBOX          => self::SEVERITY_CRITICAL,
            self::MISSING_ALIAS            => self::SEVERITY_HIGH,
            self::MISSING_FORWARDER        => self::SEVERITY_HIGH,
            self::PROVIDER_OBJECT_MISSING  => self::SEVERITY_CRITICAL,
            self::PROVIDER_UNREACHABLE     => self::SEVERITY_CRITICAL,
            // Mail may be going somewhere the customer did not authorise.
            self::UNEXPECTED_MAILBOX       => self::SEVERITY_HIGH,
            self::UNEXPECTED_ALIAS         => self::SEVERITY_HIGH,
            self::UNEXPECTED_FORWARDER     => self::SEVERITY_HIGH,
            self::CATCHALL_MISMATCH        => self::SEVERITY_HIGH,
            // Wrong, but nothing is being lost.
            self::SUSPENSION_MISMATCH      => self::SEVERITY_MEDIUM,
            self::QUOTA_MISMATCH           => self::SEVERITY_MEDIUM,
            self::DUPLICATE_PROVIDER_OBJECT => self::SEVERITY_MEDIUM,
            self::USAGE_STALE              => self::SEVERITY_LOW,
        ];
    }

    /**
     * @param string      $kind     one of self::kinds()
     * @param string      $subject  the address or domain the finding is about
     * @param string      $summary  operator-readable statement of the difference
     * @param array       $detail   structured comparison; operator surface only
     * @param string|null $severity override, or null for the kind's default
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $summary,
        public readonly array $detail = [],
        ?string $severity = null,
    ) {
        if (! in_array($kind, self::kinds(), true)) {
            throw new InvalidArgumentException("ReconciliationFinding: unknown kind '{$kind}'.");
        }

        $this->severity = $severity ?? (self::defaultSeverities()[$kind] ?? self::SEVERITY_MEDIUM);
    }

    public readonly string $severity;

    /**
     * What a customer may see: that something is wrong and roughly how bad, in
     * our own words. Never the provider reference, never the raw comparison.
     */
    public function toCustomerArray(): array
    {
        return [
            'kind'     => $this->kind,
            'subject'  => $this->subject,
            'severity' => $this->severity,
            'message'  => self::customerMessages()[$this->kind] ?? 'Something about this service needs attention.',
        ];
    }

    /** The operator view, including the structured comparison. */
    public function toAdminArray(): array
    {
        return [
            'kind'     => $this->kind,
            'subject'  => $this->subject,
            'severity' => $this->severity,
            'summary'  => $this->summary,
            'detail'   => $this->detail,
        ];
    }

    /**
     * Customer-safe wording. Deliberately vague about mechanism: a customer
     * needs to know their mail may not be arriving, not that our binding table
     * disagrees with a provider enumeration.
     *
     * @return array<string,string>
     */
    public static function customerMessages(): array
    {
        return [
            self::MISSING_MAILBOX           => 'This mailbox is not currently active and may not be receiving mail.',
            self::UNEXPECTED_MAILBOX        => 'We found a mailbox on this domain that was not set up here.',
            self::QUOTA_MISMATCH            => 'This mailbox\'s storage limit does not match your settings.',
            self::SUSPENSION_MISMATCH       => 'This mailbox\'s status does not match your settings.',
            self::MISSING_ALIAS             => 'This alias is not currently active.',
            self::UNEXPECTED_ALIAS          => 'We found an address routing rule that was not set up here.',
            self::MISSING_FORWARDER         => 'This forwarding rule is not currently active.',
            self::UNEXPECTED_FORWARDER      => 'We found a forwarding rule that was not set up here.',
            self::CATCHALL_MISMATCH         => 'Catch-all delivery does not match your settings.',
            self::PROVIDER_OBJECT_MISSING   => 'This item is not currently active and may not be receiving mail.',
            self::DUPLICATE_PROVIDER_OBJECT => 'This item needs attention. We are looking into it.',
            self::USAGE_STALE               => 'Storage figures for this domain may be out of date.',
            self::PROVIDER_UNREACHABLE      => 'We could not check this service just now. Your settings are unchanged.',
        ];
    }
}

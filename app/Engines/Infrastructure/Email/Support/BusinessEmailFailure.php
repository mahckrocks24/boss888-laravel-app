<?php

namespace App\Engines\Infrastructure\Email\Support;

/**
 * INFRA888 · E1 — typed failure codes for the Business Email engine.
 *
 * Every refusal this engine can make has a code here, a customer-safe message,
 * and a retry classification. Three properties are load-bearing:
 *
 *   1. NO PROVIDER LANGUAGE. These messages are what a customer sees. Not one
 *    of them names a vendor, quotes a provider error, or exposes a provider
 *    identifier. Provider detail belongs in infra_operations.failure_summary,
 *    which is an operator surface.
 *
 *   2. RETRY CLASSIFICATION IS EXPLICIT. `manual` is not a synonym for
 *    `permanent`: "the provider is not configured" will resolve, but only when
 *    a human configures one. Classifying it as permanent would stop a
 *    legitimate retry forever; classifying it as retryable would spin a queue
 *    against something that cannot succeed today.
 *
 *   3. NOT-CONFIGURED IS A FIRST-CLASS ANSWER, not an exception and not a
 *    silent no-op. Business Email has no adapter in E1, so this is the honest
 *    outcome of every execution attempt, and the engine must say so in a shape
 *    callers can handle.
 */
final class BusinessEmailFailure
{
    // ── availability ─────────────────────────────────────────────────────────

    /** No email provider is wired up. The E1 answer to every execution attempt. */
    public const PROVIDER_NOT_CONFIGURED = 'email_provider_not_configured';

    /** A provider is configured but is not reachable or not healthy right now. */
    public const PROVIDER_UNAVAILABLE = 'email_provider_unavailable';

    /** The configured provider's adapter does not implement this operation. */
    public const CAPABILITY_NOT_SUPPORTED = 'email_capability_not_supported';

    // ── request validity ─────────────────────────────────────────────────────

    public const UNKNOWN_CAPABILITY = 'email_unknown_capability';
    public const INVALID_CONTEXT = 'email_invalid_context';
    public const INVALID_ADDRESS = 'email_invalid_address';
    public const RESERVED_LOCAL_PART = 'email_reserved_local_part';

    // ── authorisation and tenancy ────────────────────────────────────────────

    public const NOT_ENTITLED = 'email_not_entitled';
    public const QUOTA_EXCEEDED = 'email_quota_exceeded';
    public const CROSS_TENANT = 'email_cross_tenant';
    public const APPROVAL_REQUIRED = 'email_approval_required';

    // ── state and evidence ───────────────────────────────────────────────────

    public const ILLEGAL_TRANSITION = 'email_illegal_transition';
    public const DOMAIN_NOT_VERIFIED = 'email_domain_not_verified';
    public const CUSTODY_INSUFFICIENT = 'email_custody_insufficient';
    public const LOOP_RISK = 'email_forwarding_loop_risk';

    /**
     * Customer-safe wording. Deliberately free of vendor names, provider error
     * codes, provider URLs and internal identifiers.
     *
     * @return array<string,string>
     */
    public static function messages(): array
    {
        return [
            self::PROVIDER_NOT_CONFIGURED  => 'Business Email is not available on this account yet.',
            self::PROVIDER_UNAVAILABLE     => 'Business Email is temporarily unavailable. Nothing has been changed.',
            self::CAPABILITY_NOT_SUPPORTED => 'This action is not available for your Business Email service.',

            self::UNKNOWN_CAPABILITY       => 'That Business Email action does not exist.',
            self::INVALID_CONTEXT          => 'This request is missing information needed to act on it.',
            self::INVALID_ADDRESS          => 'That email address is not valid.',
            self::RESERVED_LOCAL_PART      => 'That address is reserved and cannot be assigned.',

            self::NOT_ENTITLED             => 'Your plan does not include Business Email.',
            self::QUOTA_EXCEEDED           => 'You have reached the limit included in your plan.',
            self::CROSS_TENANT             => 'That item does not belong to this workspace.',
            self::APPROVAL_REQUIRED        => 'This action needs to be approved before it can run.',

            self::ILLEGAL_TRANSITION       => 'This action is not available in the current state.',
            self::DOMAIN_NOT_VERIFIED      => 'This domain\'s DNS records have not been verified yet.',
            self::CUSTODY_INSUFFICIENT     => 'We do not have enough control over this domain to manage its email.',
            self::LOOP_RISK                => 'This forwarding rule would create a mail loop and was not created.',
        ];
    }

    /**
     * retryable = try again automatically.
     * manual    = a human must change something first; automatic retry cannot help.
     * permanent = the request itself is wrong; retrying identically never works.
     *
     * @return array<string,string>
     */
    public static function retryClassifications(): array
    {
        return [
            // Resolvable, but only by a human configuring a provider.
            self::PROVIDER_NOT_CONFIGURED  => 'manual',
            self::PROVIDER_UNAVAILABLE     => 'retryable',
            self::CAPABILITY_NOT_SUPPORTED => 'permanent',

            self::UNKNOWN_CAPABILITY       => 'permanent',
            self::INVALID_CONTEXT          => 'permanent',
            self::INVALID_ADDRESS          => 'permanent',
            self::RESERVED_LOCAL_PART      => 'permanent',

            self::NOT_ENTITLED             => 'manual',
            self::QUOTA_EXCEEDED           => 'manual',
            self::CROSS_TENANT             => 'permanent',
            self::APPROVAL_REQUIRED        => 'manual',

            self::ILLEGAL_TRANSITION       => 'permanent',
            self::DOMAIN_NOT_VERIFIED      => 'manual',
            self::CUSTODY_INSUFFICIENT     => 'manual',
            self::LOOP_RISK                => 'permanent',
        ];
    }

    /**
     * The normalized state a failure leaves the request in. `unavailable` is
     * distinct from `failed`: nothing was attempted, so nothing is broken.
     *
     * @return array<string,string>
     */
    public static function normalizedStates(): array
    {
        return [
            self::PROVIDER_NOT_CONFIGURED  => 'unavailable',
            self::PROVIDER_UNAVAILABLE     => 'unavailable',
            self::CAPABILITY_NOT_SUPPORTED => 'unsupported',

            self::UNKNOWN_CAPABILITY       => 'rejected',
            self::INVALID_CONTEXT          => 'rejected',
            self::INVALID_ADDRESS          => 'rejected',
            self::RESERVED_LOCAL_PART      => 'rejected',

            self::NOT_ENTITLED             => 'denied',
            self::QUOTA_EXCEEDED           => 'denied',
            self::CROSS_TENANT             => 'denied',
            self::APPROVAL_REQUIRED        => 'pending_approval',

            self::ILLEGAL_TRANSITION       => 'rejected',
            self::DOMAIN_NOT_VERIFIED      => 'blocked',
            self::CUSTODY_INSUFFICIENT     => 'blocked',
            self::LOOP_RISK                => 'blocked',
        ];
    }

    public static function codes(): array
    {
        return array_keys(self::messages());
    }

    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::messages());
    }

    public static function message(string $code): string
    {
        return self::messages()[$code] ?? 'Business Email could not complete that request.';
    }

    public static function retryClassification(string $code): string
    {
        return self::retryClassifications()[$code] ?? 'permanent';
    }

    public static function normalizedState(string $code): string
    {
        return self::normalizedStates()[$code] ?? 'failed';
    }
}

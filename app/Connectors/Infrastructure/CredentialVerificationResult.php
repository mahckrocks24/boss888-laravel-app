<?php

namespace App\Connectors\Infrastructure;

/**
 * Outcome of a read-only credential verification (Phase 2B-3).
 *
 * 🔴 CONTAINS NO SECRET MATERIAL. It describes what a credential CAN DO, never
 * what it IS. Every field here is safe to persist and safe to show an operator.
 *
 * THE THREE-WAY OUTCOME IS THE POINT
 * ----------------------------------
 * A boolean pass/fail is not enough, because there is a third real answer:
 *
 *   authenticated = true,  attempted = true   -> we know it works, and its scope
 *   authenticated = false, attempted = true   -> we know it does not work
 *   attempted = false                         -> WE DID NOT CHECK
 *
 * The third must never be collapsed into either of the others. Reporting
 * "unverified" as failure causes false alarms; reporting it as success is worse —
 * it would mark a credential verified without evidence, which the directive
 * explicitly forbids. `notAttempted()` exists so that "we couldn't check" is a
 * first-class answer.
 *
 * `grantedCapabilities` / `missingCapabilities` come from what the provider
 * ACTUALLY allowed during the probe — not from what the operator claimed when
 * pasting the credential in. That distinction is exactly what caught the measured
 * D2 scope gap: the token was believed to be full-access and proved to be
 * DNS-only.
 */
final class CredentialVerificationResult
{
    public function __construct(
        public readonly bool $attempted,
        public readonly bool $authenticated,
        /** @var array<int,string> capabilities the provider confirmed */
        public readonly array $grantedCapabilities = [],
        /** @var array<int,string> requested capabilities the provider refused */
        public readonly array $missingCapabilities = [],
        /** Provider-side account this credential belongs to. Non-secret. */
        public readonly ?string $accountIdentifier = null,
        public readonly ?string $accountLabel = null,
        public readonly ?\DateTimeInterface $expiresAt = null,
        /** Normalized, non-secret diagnostic code. */
        public readonly ?string $code = null,
        public readonly ?string $summary = null,
        /** Sanitized diagnostics only. Never a raw provider response. */
        public readonly array $diagnostics = [],
    ) {
    }

    public static function success(
        array $granted,
        array $missing = [],
        ?string $accountIdentifier = null,
        ?string $accountLabel = null,
        ?\DateTimeInterface $expiresAt = null,
        ?string $summary = null,
        array $diagnostics = []
    ): self {
        return new self(
            attempted: true,
            authenticated: true,
            grantedCapabilities: $granted,
            missingCapabilities: $missing,
            accountIdentifier: $accountIdentifier,
            accountLabel: $accountLabel,
            expiresAt: $expiresAt,
            code: 'ok',
            summary: $summary ?? 'Credential authenticated.',
            diagnostics: $diagnostics,
        );
    }

    public static function failure(
        string $code,
        string $summary,
        array $missing = [],
        array $diagnostics = []
    ): self {
        return new self(
            attempted: true,
            authenticated: false,
            missingCapabilities: $missing,
            code: $code,
            summary: $summary,
            diagnostics: $diagnostics,
        );
    }

    /**
     * No verification was performed. NOT a success and NOT a failure.
     *
     * The honest answer when no verifier is configured for a provider, or when a
     * verification would have required a mutating call and was therefore refused.
     */
    public static function notAttempted(string $reason): self
    {
        return new self(
            attempted: false,
            authenticated: false,
            code: 'not_attempted',
            summary: $reason,
        );
    }

    /** Only a genuinely attempted, genuinely successful check may activate. */
    public function permitsActivation(): bool
    {
        return $this->attempted && $this->authenticated;
    }

    public function covers(string $capability): bool
    {
        return in_array($capability, $this->grantedCapabilities, true);
    }
}

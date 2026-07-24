<?php

namespace App\Connectors\Infrastructure\Testing;

use App\Connectors\Infrastructure\Contracts\CredentialVerifier;
use App\Connectors\Infrastructure\CredentialVerificationResult;
use RuntimeException;

/**
 * Deterministic verifier for exercising the credential lifecycle (Phase 2B-G, WS3).
 *
 * 🔴 THIS IS NOT A PROVIDER. It contacts nothing and proves nothing about any
 * real vendor. It exists so the GOVERNANCE path — submit, verify, approve,
 * activate, rotate, revoke — can be exercised end to end while D1 and D2 remain
 * unresolved.
 *
 * FOUR STRUCTURAL SAFEGUARDS AGAINST IT EVER BEING MISTAKEN FOR REAL
 *
 * 1. Namespace `App\Connectors\Infrastructure\Testing` — visible at every import.
 * 2. `providerKey()` returns a value prefixed `controlled-` which cannot collide
 *    with a real provider key.
 * 3. **It refuses to construct when the application environment is production.**
 *    Not a warning, not a log line — a thrown exception. Configuration mistakes
 *    are normal; this must fail loudly rather than quietly verify.
 * 4. Every result it returns carries `diagnostics.simulated = true`, so the
 *    marker survives into the persisted verification record and the event log.
 *    An operator reading history months later can see the result was simulated
 *    without needing to know this class exists.
 *
 * It NEVER fabricates a real provider's response. `success()` here means "the
 * controlled scenario said success", which is exactly why safeguard 4 exists.
 */
final class ControlledCredentialVerifier implements CredentialVerifier
{
    public const OUTCOME_VERIFIED          = 'verified';
    public const OUTCOME_UNAUTHORIZED      = 'unauthorized';
    public const OUTCOME_MISSING_CAPABILITY = 'missing_capability';
    public const OUTCOME_EXPIRED           = 'expired';
    public const OUTCOME_REVOKED           = 'revoked';
    public const OUTCOME_RATE_LIMITED      = 'rate_limited';
    public const OUTCOME_UNAVAILABLE       = 'provider_unavailable';
    public const OUTCOME_NOT_ATTEMPTED     = 'not_attempted';
    /** Grants MORE than asked — exercises the excess-privilege detector. */
    public const OUTCOME_EXCESS_GRANT      = 'excess_grant';

    public int $callCount = 0;

    public function __construct(
        private readonly string $outcome = self::OUTCOME_VERIFIED,
        /** @var array<int,string> capabilities to report as granted */
        private readonly array $grantedCapabilities = [],
        private readonly ?string $accountIdentifier = 'controlled-account',
        private readonly ?\DateTimeInterface $expiresAt = null,
    ) {
        // Safeguard 3. Deliberately an exception.
        if (function_exists('app') && app()->environment('production')) {
            throw new RuntimeException(
                'ControlledCredentialVerifier must never be constructed in production. '
                . 'It simulates verification and would certify credentials without evidence.'
            );
        }

        if (!in_array($this->outcome, self::outcomes(), true)) {
            throw new RuntimeException("Unknown controlled outcome '{$this->outcome}'.");
        }
    }

    public static function outcomes(): array
    {
        return [
            self::OUTCOME_VERIFIED, self::OUTCOME_UNAUTHORIZED,
            self::OUTCOME_MISSING_CAPABILITY, self::OUTCOME_EXPIRED,
            self::OUTCOME_REVOKED, self::OUTCOME_RATE_LIMITED,
            self::OUTCOME_UNAVAILABLE, self::OUTCOME_NOT_ATTEMPTED,
            self::OUTCOME_EXCESS_GRANT,
        ];
    }

    public function providerKey(): string
    {
        return 'controlled-verifier';
    }

    public function verifiableCapabilities(): array
    {
        return $this->grantedCapabilities;
    }

    public function verify(
        string $secret,
        string $environment,
        array $expectedCapabilities = []
    ): CredentialVerificationResult {
        $this->callCount++;

        // Safeguard 4 — the marker that survives into persisted history.
        $diag = [
            'simulated'         => true,
            'controlled_outcome' => $this->outcome,
            'verifier'          => self::class,
            'environment'       => $environment,
        ];

        return match ($this->outcome) {
            self::OUTCOME_VERIFIED => CredentialVerificationResult::success(
                granted: $this->grantedCapabilities ?: $expectedCapabilities,
                accountIdentifier: $this->accountIdentifier,
                accountLabel: 'Controlled Verification Scenario',
                expiresAt: $this->expiresAt,
                summary: 'SIMULATED: credential authenticated under a controlled scenario.',
                diagnostics: $diag,
            ),

            // Grants everything asked for PLUS extra — the over-privileged token
            // case, which works perfectly and silently until it leaks.
            self::OUTCOME_EXCESS_GRANT => CredentialVerificationResult::success(
                granted: array_values(array_unique(array_merge(
                    $this->grantedCapabilities ?: $expectedCapabilities,
                    ['registrar', 'hosting'],
                ))),
                accountIdentifier: $this->accountIdentifier,
                accountLabel: 'Controlled Verification Scenario',
                summary: 'SIMULATED: credential authenticated with MORE scope than requested.',
                diagnostics: $diag,
            ),

            self::OUTCOME_MISSING_CAPABILITY => CredentialVerificationResult::success(
                granted: $this->grantedCapabilities,
                missing: array_values(array_diff($expectedCapabilities, $this->grantedCapabilities)),
                accountIdentifier: $this->accountIdentifier,
                summary: 'SIMULATED: authenticated, but not all requested capabilities were granted.',
                diagnostics: $diag,
            ),

            self::OUTCOME_UNAUTHORIZED => CredentialVerificationResult::failure(
                'unauthorized', 'SIMULATED: the provider rejected this credential.',
                diagnostics: $diag),

            self::OUTCOME_EXPIRED => CredentialVerificationResult::failure(
                'expired', 'SIMULATED: the credential has expired.', diagnostics: $diag),

            self::OUTCOME_REVOKED => CredentialVerificationResult::failure(
                'revoked', 'SIMULATED: the credential was revoked at the provider.',
                diagnostics: $diag),

            self::OUTCOME_RATE_LIMITED => CredentialVerificationResult::failure(
                'rate_limited', 'SIMULATED: verification was rate limited.',
                diagnostics: $diag + ['retry_after_seconds' => 60]),

            self::OUTCOME_UNAVAILABLE => CredentialVerificationResult::failure(
                'provider_unavailable', 'SIMULATED: the provider is unreachable.',
                diagnostics: $diag),

            // Distinct from failure: nothing was checked at all.
            self::OUTCOME_NOT_ATTEMPTED => CredentialVerificationResult::notAttempted(
                'SIMULATED: verification was not attempted.'),
        };
    }
}

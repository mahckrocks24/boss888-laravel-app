<?php

namespace App\Connectors\Infrastructure\Null;

use App\Connectors\Infrastructure\Contracts\CredentialVerifier;
use App\Connectors\Infrastructure\CredentialVerificationResult;

/**
 * The default verifier for any provider without a real one (Phase 2B-3).
 *
 * IT ALWAYS RETURNS `notAttempted()`. NEVER SUCCESS.
 *
 * This is the entire point. The tempting shortcut — have the Null verifier
 * return success so the lifecycle can be demonstrated end-to-end without a
 * provider — would mean credentials get marked verified and activated with no
 * evidence whatsoever, which is precisely what the directive forbids: "Do not
 * mark credentials verified without an actual safe verification result."
 *
 * The consequence is deliberate and correct: with no real verifier configured, no
 * credential can ever reach `active`. Activation requires evidence, and evidence
 * requires a provider. The lifecycle is still fully testable — the test suite
 * supplies fake verifiers that return controlled, genuinely-attempted results —
 * but nothing in production configuration can accidentally self-certify.
 */
final class NullCredentialVerifier implements CredentialVerifier
{
    public function providerKey(): string
    {
        return 'null';
    }

    public function verify(
        string $secret,
        string $environment,
        array $expectedCapabilities = []
    ): CredentialVerificationResult {
        return CredentialVerificationResult::notAttempted(
            'No credential verifier is configured for this provider. The credential '
            . 'remains unverified and cannot be activated. This is not a failure — '
            . 'nothing was checked.'
        );
    }

    public function verifiableCapabilities(): array
    {
        return [];
    }
}

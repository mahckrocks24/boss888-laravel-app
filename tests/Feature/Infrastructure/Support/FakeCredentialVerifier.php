<?php

namespace Tests\Feature\Infrastructure\Support;

use App\Connectors\Infrastructure\Contracts\CredentialVerifier;
use App\Connectors\Infrastructure\CredentialVerificationResult;

/**
 * Controllable verifier for tests.
 *
 * Exists because NullCredentialVerifier ALWAYS returns notAttempted() and can
 * therefore never activate anything — which is the correct production behaviour
 * but makes the activation path untestable. This fake supplies genuinely
 * "attempted" results with controlled outcomes.
 *
 * It records the secret it was handed so a test can assert the secret actually
 * reached the verifier (proving encryption round-trips) without the production
 * code ever exposing one.
 */
final class FakeCredentialVerifier implements CredentialVerifier
{
    public ?string $lastSecret = null;
    public int $callCount = 0;

    public function __construct(
        private readonly CredentialVerificationResult $result,
        private readonly string $providerKey = 'fake',
    ) {
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function verify(
        string $secret,
        string $environment,
        array $expectedCapabilities = []
    ): CredentialVerificationResult {
        $this->lastSecret = $secret;
        $this->callCount++;

        return $this->result;
    }

    public function verifiableCapabilities(): array
    {
        return $this->result->grantedCapabilities;
    }

    public static function granting(array $capabilities, ?string $account = 'acct-test-1'): self
    {
        return new self(CredentialVerificationResult::success(
            granted: $capabilities,
            accountIdentifier: $account,
            accountLabel: 'Test Account',
        ));
    }

    public static function rejecting(string $code = 'unauthorized', string $summary = 'Token rejected.'): self
    {
        return new self(CredentialVerificationResult::failure($code, $summary));
    }

    public static function unreachable(): self
    {
        return new self(CredentialVerificationResult::notAttempted('No verifier configured.'));
    }
}

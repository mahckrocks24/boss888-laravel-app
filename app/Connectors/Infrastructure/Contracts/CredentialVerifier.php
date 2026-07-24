<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\CredentialVerificationResult;

/**
 * Generic credential verification (Phase 2B-3).
 *
 * 🔴 IMPLEMENTATIONS MUST BE STRICTLY READ-ONLY.
 *
 * A verifier proves a credential works by READING something — listing zones,
 * fetching account details, calling a token-introspection endpoint. It must never
 * create, update or delete a provider resource, and it must never create a
 * throwaway object "just to test". Verification runs against live provider
 * accounts, frequently, and often unattended; a verifier with side effects would
 * be a scheduled mutation of customer infrastructure.
 *
 * WHAT A VERIFIER MUST NOT DO
 *   - write anything at the provider
 *   - return, log or persist the secret it was handed
 *   - report success it did not observe (return notAttempted() instead)
 *   - assume capability scope from configuration rather than from the response
 *
 * The last is the one that matters most in practice. The measured D2 finding —
 * a token believed to be full-access that proved DNS-only — is exactly what
 * happens when scope is assumed rather than probed. `$expectedCapabilities` is
 * therefore what we want to CHECK, never what we get to CLAIM.
 */
interface CredentialVerifier
{
    /** The provider_key this verifier serves. */
    public function providerKey(): string;

    /**
     * Verify a secret, read-only.
     *
     * @param  string  $secret                Plaintext, in memory only. Never persisted or logged.
     * @param  string  $environment           sandbox|production
     * @param  array<int,string> $expectedCapabilities  What we want to confirm — not what to assert.
     */
    public function verify(
        string $secret,
        string $environment,
        array $expectedCapabilities = []
    ): CredentialVerificationResult;

    /**
     * Capabilities this verifier is able to CHECK.
     *
     * A capability absent from this list cannot be confirmed by this verifier, so
     * it must be reported as unverified rather than assumed granted.
     *
     * @return array<int,string>
     */
    public function verifiableCapabilities(): array;
}

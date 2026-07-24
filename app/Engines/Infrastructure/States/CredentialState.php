<?php

namespace App\Engines\Infrastructure\States;

/**
 * Lifecycle of a provider credential (Phase 2B-3).
 *
 *   pending_verification  stored, INERT — never used for a real call
 *   active                verified and in use
 *   expiring              still valid, expiry within the warning window
 *   expired               past expires_at
 *   revoked               deliberately killed by an operator
 *   invalid               the provider rejected it
 *   superseded            replaced by a newer credential in a rotation chain
 *
 * WHY `pending_verification` IS THE INITIAL STATE, ALWAYS
 * A credential that has just been pasted in is an unproven claim. Until a
 * read-only verification actually succeeds, the platform has no evidence it
 * works, no evidence of its scope, and no evidence of WHICH ACCOUNT it belongs
 * to — the last being exactly the D1 ownership problem. So a new credential is
 * inert by construction, and `ProviderCredentialService` refuses to mark one
 * verified without a real verification result (directive: "Do not mark
 * credentials verified without an actual safe verification result").
 *
 * WHY `expiring` IS A STATE RATHER THAN A COMPUTED FLAG
 * Because it must be actionable and auditable. A computed boolean produces no
 * event when it flips; a state transition does, so "nobody was told the
 * credential was about to expire" stops being possible.
 *
 * TERMINAL STATES ARE TERMINAL ON PURPOSE
 * `revoked` and `superseded` never return. Reviving a revoked credential would
 * defeat revocation as a security control — the replacement must be a NEW row,
 * which also preserves the rotation chain.
 */
final class CredentialState extends StateMachine
{
    public const PENDING_VERIFICATION = 'pending_verification';
    public const ACTIVE               = 'active';
    public const EXPIRING             = 'expiring';
    public const EXPIRED              = 'expired';
    public const REVOKED              = 'revoked';
    public const INVALID              = 'invalid';
    public const SUPERSEDED           = 'superseded';

    public static function initial(): string
    {
        return self::PENDING_VERIFICATION;
    }

    public static function states(): array
    {
        return [
            self::PENDING_VERIFICATION, self::ACTIVE, self::EXPIRING,
            self::EXPIRED, self::REVOKED, self::INVALID, self::SUPERSEDED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::PENDING_VERIFICATION => [self::ACTIVE, self::INVALID, self::REVOKED, self::EXPIRED],
            self::ACTIVE               => [self::EXPIRING, self::EXPIRED, self::REVOKED, self::INVALID, self::SUPERSEDED],
            // A renewed credential legitimately returns to active.
            self::EXPIRING             => [self::ACTIVE, self::EXPIRED, self::REVOKED, self::INVALID, self::SUPERSEDED],
            // An expired credential may be re-verified if the provider extended it.
            self::EXPIRED              => [self::ACTIVE, self::REVOKED, self::SUPERSEDED],
            // An invalid credential may become valid again (restored provider
            // permissions) — but only through verification, never by assertion.
            self::INVALID              => [self::ACTIVE, self::REVOKED, self::SUPERSEDED],
            self::REVOKED              => [],
            self::SUPERSEDED           => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::REVOKED, self::SUPERSEDED];
    }

    /** The ONLY states in which a credential may be handed to a live call. */
    public static function usable(): array
    {
        return [self::ACTIVE, self::EXPIRING];
    }

    /** States requiring operator attention. */
    public static function needsAttention(): array
    {
        return [self::EXPIRING, self::EXPIRED, self::INVALID];
    }

    public static function operational(): array
    {
        return [self::ACTIVE];
    }
}

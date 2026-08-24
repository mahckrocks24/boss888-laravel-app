<?php

namespace App\Engines\Infrastructure\Email\Support;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * INFRA888 · E2 — how the engine reads a provider's answer.
 *
 * FIVE OUTCOMES, AND THE FIFTH IS THE ONE THAT MATTERS
 *
 *   VERIFIED   the provider did it AND we read it back. Only this may mark a
 *              customer-visible state as working.
 *   ACCEPTED   the provider acknowledged. Nothing is confirmed. The operation
 *              stays open and a read-back resolves it.
 *   RETRYABLE  transient. The same request may be re-issued.
 *   PERMANENT  the request is wrong. Re-issuing it identically never works.
 *   AMBIGUOUS  we do not know whether it happened.
 *
 * AMBIGUOUS IS NOT A KIND OF FAILURE. Treating it as one leads to a retry, and
 * a retried create after a timeout is how a customer ends up with two mailboxes
 * on the same address and mail split between them. Treating it as success is
 * worse. The only safe response is to read provider truth back, which is why
 * this classification exists as a separate concept rather than as a flag on
 * retry classification.
 *
 * WHY THE CLASSIFICATION IS DERIVED RATHER THAN CARRIED
 * ProviderResult is shared with hosting, registrar and certificate work
 * packages. Adding an `ambiguous()` factory to it would change a primitive
 * three other packages depend on, for one package's benefit. Instead the
 * ambiguity is expressed in fields ProviderResult already has — a known error
 * code plus `manual` retry classification — and read here. Nothing shared had
 * to change.
 */
final class ProviderOutcome
{
    public const VERIFIED = 'verified';
    public const ACCEPTED = 'accepted';
    public const RETRYABLE = 'retryable';
    public const PERMANENT = 'permanent';
    public const AMBIGUOUS = 'ambiguous';

    /**
     * Error codes that mean "we do not know". An adapter returning one of these
     * is stating that the mutation may or may not have taken effect.
     */
    public const AMBIGUOUS_CODES = [
        'provider_timeout',
        'provider_indeterminate',
        'provider_malformed_response',
    ];

    public static function classify(ProviderResult $result): string
    {
        if ($result->success) {
            // A success with no usable reference is not a success we can act
            // on: we cannot bind to the object, so we do not know whether one
            // exists. That is ambiguity, not completion.
            if ($result->verified) {
                return self::VERIFIED;
            }

            return self::ACCEPTED;
        }

        if (self::isAmbiguousCode((string) $result->errorCode)) {
            return self::AMBIGUOUS;
        }

        return $result->retryClassification === 'retryable' ? self::RETRYABLE : self::PERMANENT;
    }

    public static function isAmbiguousCode(string $code): bool
    {
        return in_array($code, self::AMBIGUOUS_CODES, true);
    }

    /**
     * A provider claimed success but returned nothing we can bind to. Callers
     * must treat this as ambiguity rather than storing an empty reference —
     * an empty provider reference is indistinguishable from "never provisioned"
     * and would make the object unreachable forever.
     */
    public static function isUnbindableSuccess(ProviderResult $result, bool $referenceRequired): bool
    {
        return $result->success
            && $referenceRequired
            && ($result->providerResourceId === null || trim($result->providerResourceId) === '');
    }

    /** Outcomes after which no state may be presented to a customer as done. */
    public static function isUnconfirmed(string $outcome): bool
    {
        return in_array($outcome, [self::ACCEPTED, self::AMBIGUOUS], true);
    }

    /** Outcomes an automatic retry may act on. Deliberately excludes AMBIGUOUS. */
    public static function isAutoRetryable(string $outcome): bool
    {
        return $outcome === self::RETRYABLE;
    }

    public static function all(): array
    {
        return [self::VERIFIED, self::ACCEPTED, self::RETRYABLE, self::PERMANENT, self::AMBIGUOUS];
    }
}

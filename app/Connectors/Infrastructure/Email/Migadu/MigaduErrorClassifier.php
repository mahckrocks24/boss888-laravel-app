<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * INFRA888 · E5 — provider outcome to platform semantics, in one place.
 *
 * The whole point of E2's ProviderOutcome vocabulary is that a timeout on a
 * mutating call is NEITHER success NOR failure. This class is where that rule
 * either holds or quietly dies, so it is the only thing in the adapter allowed
 * to decide a retry classification.
 *
 * The provider documents exactly three response codes — 200, 400 and 422 — which
 * is far fewer than a real integration meets. Everything else here is derived
 * from HTTP semantics rather than invented provider behaviour, and anything
 * genuinely unrecognised classifies as PERMANENT for mutations. Defaulting an
 * unknown mutating outcome to "retryable" is how you create four mailboxes.
 */
final class MigaduErrorClassifier
{
    public const KIND_UNREACHABLE   = 'unreachable';
    public const KIND_INDETERMINATE = 'indeterminate';
    public const KIND_MALFORMED     = 'malformed';

    /** Neutral codes. No provider vocabulary crosses this boundary. */
    public const AUTH_FAILED        = 'provider_auth_failed';
    public const FORBIDDEN          = 'provider_forbidden';
    public const NOT_FOUND          = 'provider_not_found';
    public const DUPLICATE          = 'provider_duplicate';
    public const VALIDATION_FAILED  = 'provider_validation_failed';
    public const DNS_NOT_READY      = 'provider_dns_not_ready';
    public const LIMIT_EXCEEDED     = 'provider_limit_exceeded';
    public const RATE_LIMITED       = 'provider_rate_limited';
    public const UNAVAILABLE        = 'provider_unavailable';
    public const TIMEOUT            = 'provider_timeout';
    public const INDETERMINATE      = 'provider_indeterminate';
    public const MALFORMED          = 'provider_malformed_response';
    public const UNKNOWN            = 'provider_unknown_error';
    public const UNSUPPORTED        = 'provider_capability_unsupported';

    /**
     * These three are the ambiguous set E2 defined. The engine already refuses
     * to auto-retry them; repeating the list here keeps the adapter honest even
     * if it is reused elsewhere.
     */
    public const AMBIGUOUS = [self::TIMEOUT, self::INDETERMINATE, self::MALFORMED];

    /**
     * Classify a completed HTTP exchange.
     *
     * @param int         $status HTTP status actually returned
     * @param array       $body   decoded JSON body, already size-bounded
     * @param bool        $mutating whether the call could have changed provider state
     * @return array{0:string,1:string,2:string} [errorCode, retryClassification, summary]
     */
    public static function classifyStatus(int $status, array $body, bool $mutating): array
    {
        $detail = self::detail($body);

        return match (true) {
            $status === 401 => [
                self::AUTH_FAILED, 'permanent',
                'The email service rejected our credentials.',
            ],
            $status === 403 => [
                self::FORBIDDEN, 'permanent',
                'The email service refused this operation for the account in use.',
            ],
            $status === 404 => [
                self::NOT_FOUND, 'permanent',
                'The email service does not have this object.',
            ],
            $status === 409 => [
                self::DUPLICATE, 'permanent',
                'The email service already has an object with this address.',
            ],
            $status === 429 => [
                self::RATE_LIMITED, 'retryable',
                'The email service is rate limiting us.',
            ],
            // The provider documents 422 as "DNS checks or validation failed".
            // Those are two very different things, so the body decides: a DNS
            // failure is a normal waiting state, not an error.
            $status === 422 && self::looksLikeDns($detail) => [
                self::DNS_NOT_READY, 'retryable',
                'The domain is not verified yet — DNS records have not been observed.',
            ],
            $status === 422 => [
                self::VALIDATION_FAILED, 'permanent',
                'The email service rejected the request as invalid.',
            ],
            $status === 400 && self::looksLikeDuplicate($detail) => [
                self::DUPLICATE, 'permanent',
                'The email service already has an object with this address.',
            ],
            $status === 400 && self::looksLikeLimit($detail) => [
                self::LIMIT_EXCEEDED, 'permanent',
                'The email service reports an account limit has been reached.',
            ],
            $status === 400 => [
                self::VALIDATION_FAILED, 'permanent',
                'The email service rejected the request.',
            ],
            $status === 408 || $status === 504 => [
                // A gateway timeout on a mutating call is the textbook ambiguous
                // case: the request may already have been applied upstream.
                $mutating ? self::INDETERMINATE : self::TIMEOUT,
                $mutating ? 'ambiguous' : 'retryable',
                'The email service did not respond in time.',
            ],
            $status >= 500 => [
                self::UNAVAILABLE, 'retryable',
                'The email service is temporarily unavailable.',
            ],
            default => [
                self::UNKNOWN,
                // Unknown plus mutating is never a safe retry.
                $mutating ? 'ambiguous' : 'retryable',
                'The email service returned an unexpected response.',
            ],
        };
    }

    /** Classify a transport-level failure, where no status exists. */
    public static function classifyTransport(MigaduTransportException $e, bool $mutating): array
    {
        return match ($e->kind) {
            // Never sent, or refused before the request body was delivered.
            // Nothing can have changed, so even a mutation may be retried.
            self::KIND_UNREACHABLE => [
                self::UNAVAILABLE, 'retryable',
                'We could not reach the email service.',
            ],
            self::KIND_MALFORMED => [
                self::MALFORMED, $mutating ? 'ambiguous' : 'retryable',
                'The email service returned a response we could not read.',
            ],
            default => [
                $mutating ? self::INDETERMINATE : self::TIMEOUT,
                $mutating ? 'ambiguous' : 'retryable',
                'The email service did not complete the request in time.',
            ],
        };
    }

    /**
     * Build the ProviderResult. Ambiguity gets its own normalized state so no
     * caller can mistake it for a failure and safely "retry".
     */
    public static function toResult(
        string $errorCode,
        string $retryClassification,
        string $summary,
        ?string $correlationId = null
    ): ProviderResult {
        $ambiguous = $retryClassification === 'ambiguous';

        return ProviderResult::failed(
            errorCode: $errorCode,
            errorSummary: $summary,
            // ProviderResult understands retryable/permanent. Ambiguous must not
            // be reported as retryable, so it is carried as permanent-with-a-
            // needs-reconciliation state and the engine picks it up from there.
            retryClassification: $ambiguous ? 'permanent' : $retryClassification,
            normalizedState: $ambiguous ? 'needs_reconciliation' : 'failed',
            correlationId: $correlationId,
        );
    }

    public static function isAmbiguous(string $errorCode): bool
    {
        return in_array($errorCode, self::AMBIGUOUS, true);
    }

    /** A capability this provider does not have. Never a failure to retry. */
    public static function unsupported(string $capability, string $reason): ProviderResult
    {
        return ProviderResult::failed(
            errorCode: self::UNSUPPORTED,
            errorSummary: $reason,
            retryClassification: 'permanent',
            normalizedState: 'unsupported',
        );
    }

    // ── body sniffing ───────────────────────────────────────────────────────
    //
    // The provider does not publish an error-code vocabulary, so these read the
    // human-readable message. They are deliberately conservative: a miss falls
    // through to a safe generic classification rather than a confident wrong one.

    private static function detail(array $body): string
    {
        foreach (['error', 'message', 'errors', 'detail'] as $key) {
            if (isset($body[$key])) {
                return strtolower(is_string($body[$key]) ? $body[$key] : json_encode($body[$key]));
            }
        }

        return '';
    }

    private static function looksLikeDns(string $detail): bool
    {
        foreach (['dns', 'mx', 'txt record', 'verification', 'not verified', 'ownership'] as $needle) {
            if (str_contains($detail, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeDuplicate(string $detail): bool
    {
        foreach (['already', 'taken', 'exists', 'duplicate'] as $needle) {
            if (str_contains($detail, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeLimit(string $detail): bool
    {
        foreach (['limit', 'quota', 'exceeded', 'too many'] as $needle) {
            if (str_contains($detail, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * NO PROVIDER WORDING IS EVER APPENDED TO A SUMMARY.
     *
     * An earlier version of this class attached the provider's own message when
     * it looked short and harmless. The E5 guard caught it immediately: the
     * provider's message can contain the vendor's name, and errorSummary is
     * persisted on the operation record and rendered on operator screens. A
     * length check is not a white-label control.
     *
     * Provider detail is used to CLASSIFY — that is what the sniffers below are
     * for — and is then discarded. If a future milestone needs the raw text for
     * diagnosis, it belongs in an admin-only field with its own guard, not
     * concatenated into a string that travels.
     */
    private static function neverAppendProviderText(): void
    {
    }
}

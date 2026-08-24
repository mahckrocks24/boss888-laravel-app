<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * BUILDER888 P1-8B (2026-08-10) — the customer/internal error boundary.
 *
 * Journey A showed a customer a raw PHP TypeError:
 *
 *   "Website rendering failed: str_replace(): Argument #2 ($replace) must be
 *    of type string when argument #1 ($search) is a string"
 *
 * A customer must never see engine internals; an operator must never lose
 * them. This class splits one Throwable into exactly those two audiences and
 * mints the correlation id that ties them together.
 *
 * It classifies — it does not swallow. Every call logs the full internal cause.
 */
final class BuilderErrorContract
{
    public const GENERATION_FAILED      = 'generation_failed';
    public const INVALID_GENERATION     = 'invalid_generation';
    public const TEMPLATE_RENDER_FAILED = 'template_render_failed';
    public const PROVIDER_UNAVAILABLE   = 'provider_unavailable';
    public const TIMEOUT                = 'timeout';
    public const VALIDATION_FAILED      = 'validation_failed';
    public const PERSISTENCE_FAILED     = 'persistence_failed';
    /** Plan/entitlement refusal — actionable by the customer, not an internal fault. */
    public const LIMIT_REACHED          = 'limit_reached';

    /** Wording the customer sees. Never mentions a class, file, provider or engine. */
    private const CUSTOMER_MESSAGE = [
        self::GENERATION_FAILED      => "We couldn't finish building this website. Your progress is safe — please try again.",
        self::INVALID_GENERATION     => "We couldn't use the content that was generated for this website. Your progress is safe — please try again.",
        self::TEMPLATE_RENDER_FAILED => "We couldn't finish building this website. Your progress is safe — please try again.",
        self::PROVIDER_UNAVAILABLE   => "Our AI service is temporarily unavailable. Your progress is safe — please try again in a few minutes.",
        self::TIMEOUT                => "This took longer than expected and we stopped it. Your progress is safe — please try again.",
        self::VALIDATION_FAILED      => "Some of the details provided couldn't be used. Please review them and try again.",
        self::PERSISTENCE_FAILED     => "We couldn't save this website. Your progress is safe — please try again.",
        // Carries the platform's own limit wording; see failureWithMessage().
        self::LIMIT_REACHED          => 'You have reached your plan\'s website limit. Upgrade to create more.',
    ];

    /** Whether an identical retry is worth attempting. */
    private const RETRYABLE = [
        self::GENERATION_FAILED      => true,
        self::INVALID_GENERATION     => true,
        self::TEMPLATE_RENDER_FAILED => false,
        self::PROVIDER_UNAVAILABLE   => true,
        self::TIMEOUT                => true,
        self::VALIDATION_FAILED      => false,
        self::PERSISTENCE_FAILED     => true,
        self::LIMIT_REACHED          => false,
    ];

    /**
     * Classify a failure, log the internal cause, return the customer-facing half.
     *
     * @return array{type: string, build_outcome: string, error_category: string,
     *               build_error: string, correlation_id: string, retryable: bool}
     */
    public static function fromThrowable(
        Throwable $e,
        string $fallbackCategory = self::GENERATION_FAILED,
        array $context = []
    ): array {
        $category      = self::classify($e, $fallbackCategory);
        $correlationId = self::correlationId();

        // The internal half — everything the operator needs, nothing the
        // customer sees. Deliberately verbose.
        Log::error('[Builder888] operation failed', array_merge($context, [
            'correlation_id'  => $correlationId,
            'error_category'  => $category,
            'exception_class' => get_class($e),
            'internal_cause'  => $e->getMessage(),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
            'retryable'       => self::RETRYABLE[$category] ?? false,
        ]));

        return self::failure($category, $correlationId);
    }

    /**
     * A failure envelope without a Throwable (validation, guard refusals).
     *
     * @return array{type: string, build_outcome: string, error_category: string,
     *               build_error: string, correlation_id: string, retryable: bool}
     */
    public static function failure(string $category, ?string $correlationId = null): array
    {
        $category      = isset(self::CUSTOMER_MESSAGE[$category]) ? $category : self::GENERATION_FAILED;
        $correlationId = $correlationId ?? self::correlationId();

        return [
            // 'error' is the vocabulary this contract already uses and the SPA
            // already handles. A failed build must never be labelled 'complete'.
            'type'           => 'error',
            'build_outcome'  => 'error',
            'error_category' => $category,
            'build_error'    => self::CUSTOMER_MESSAGE[$category],
            'correlation_id' => $correlationId,
            'retryable'      => self::RETRYABLE[$category] ?? false,
        ];
    }

    /**
     * A failure envelope carrying a specific, already-customer-safe message.
     * Used for plan/entitlement refusals where the platform has authored the
     * exact wording (including the limit and plan name) and it must not be
     * replaced by generic text.
     */
    public static function failureWithMessage(string $category, string $customerMessage, ?string $correlationId = null): array
    {
        $envelope = self::failure($category, $correlationId);

        $message = trim($customerMessage);
        if ($message !== '') {
            $envelope['build_error'] = $message;
        }

        return $envelope;
    }

    /** The success half of the same contract, so both sides are minted in one place. */
    public static function success(array $payload = []): array
    {
        return array_merge([
            'type'           => 'complete',
            'build_outcome'  => 'website_created',
            'build_error'    => null,
            'error_category' => null,
        ], $payload);
    }

    /** True when a payload claims completion. Used by tests and consumers. */
    public static function isSuccessful(array $payload): bool
    {
        return ($payload['type'] ?? null) === 'complete'
            && ($payload['build_outcome'] ?? null) !== 'error'
            && empty($payload['build_error']);
    }

    private static function classify(Throwable $e, string $fallback): string
    {
        $m = strtolower($e->getMessage());

        return match (true) {
            $e instanceof \Illuminate\Database\QueryException          => self::PERSISTENCE_FAILED,
            $e instanceof \Illuminate\Http\Client\ConnectionException  => self::PROVIDER_UNAVAILABLE,
            str_contains($m, 'timed out') || str_contains($m, 'timeout')
                || str_contains($m, 'curl error 28')                   => self::TIMEOUT,
            str_contains($m, 'connection') && str_contains($m, 'refused') => self::PROVIDER_UNAVAILABLE,
            // A TypeError/ValueError inside rendering is our own bug, not the
            // model's — this is precisely the frozen P1-8 signature.
            $e instanceof \TypeError || $e instanceof \ValueError      => self::TEMPLATE_RENDER_FAILED,
            $e instanceof \JsonException || str_contains($m, 'json')   => self::INVALID_GENERATION,
            $e instanceof \InvalidArgumentException                    => self::VALIDATION_FAILED,
            default                                                    => $fallback,
        };
    }

    private static function correlationId(): string
    {
        return 'bld_' . Str::lower(Str::random(16));
    }
}

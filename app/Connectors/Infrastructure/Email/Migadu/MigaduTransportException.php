<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use RuntimeException;
use Throwable;

/**
 * INFRA888 · E5 — the adapter's internal failure type.
 *
 * NEVER LEAVES THE ADAPTER. Every public connector method catches this and
 * translates it into a ProviderResult, because the engine must not learn to
 * catch provider-shaped exceptions — that is precisely the coupling the
 * connector seam exists to prevent.
 *
 * It carries no credential. The message is built by the classifier from status
 * and code only; a raw provider body is attached separately and is stripped
 * before anything is persisted or logged.
 */
final class MigaduTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $diagnostics = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** A request that never reached the provider — no mutation can have occurred. */
    public static function unreachable(string $summary, ?Throwable $previous = null): self
    {
        return new self($summary, MigaduErrorClassifier::KIND_UNREACHABLE, null, null, [], $previous);
    }

    /**
     * The request may or may not have been applied. This is the dangerous one:
     * it must never be auto-retried on a mutating call.
     */
    public static function indeterminate(string $summary, ?Throwable $previous = null): self
    {
        return new self($summary, MigaduErrorClassifier::KIND_INDETERMINATE, null, null, [], $previous);
    }

    public static function malformed(string $summary): self
    {
        return new self($summary, MigaduErrorClassifier::KIND_MALFORMED);
    }

    public function isAmbiguous(): bool
    {
        return $this->kind === MigaduErrorClassifier::KIND_INDETERMINATE;
    }
}

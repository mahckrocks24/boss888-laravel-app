<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — a batch of projection requests for ONE user instruction.
 *
 * Carries its transaction boundary (atomic / best-effort), an idempotency key
 * for safe replay of the whole batch, and a correlation id. Holds enough
 * metadata for a future grouped-undo layer (Phase 5) — but implements no history.
 */
final class ProjectionBatch
{
    public const SCHEMA_VERSION = 1;

    /** @param ProjectionRequest[] $requests */
    public function __construct(
        public readonly string $batchId,
        public readonly string $documentId,
        public readonly array  $requests,
        public readonly ProjectionTransactionBoundary $boundary,
        public readonly ?string $idempotencyKey = null,
        public readonly string  $correlationId = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'batch_id'        => $this->batchId,
            'document_id'     => $this->documentId,
            'requests'        => array_map(fn (ProjectionRequest $r) => $r->toArray(), $this->requests),
            'boundary'        => $this->boundary->toArray(),
            'idempotency_key' => $this->idempotencyKey,
            'correlation_id'  => $this->correlationId,
        ];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(
            batchId: ArrayGuard::str($a, 'batch_id'),
            documentId: ArrayGuard::str($a, 'document_id'),
            requests: array_map(
                fn ($r) => ProjectionRequest::fromArray(is_array($r) ? $r : []),
                ArrayGuard::arr($a, 'requests')
            ),
            boundary: ProjectionTransactionBoundary::fromArray(ArrayGuard::arr($a, 'boundary')),
            idempotencyKey: ArrayGuard::strOrNull($a, 'idempotency_key'),
            correlationId: ArrayGuard::str($a, 'correlation_id'),
        );
    }
}

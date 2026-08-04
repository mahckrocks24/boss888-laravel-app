<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — a renderer-neutral projection request.
 *
 * Describes ONE element change to project, carrying everything a renderer needs
 * to apply it safely without blind-writing: the expected document version, a
 * before-snapshot hash for optimistic concurrency, an idempotency key for safe
 * replay, and a correlation id for tracing. It names field paths + desired
 * values — never HTML, never a selector.
 */
final class ProjectionRequest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param string      $operationId
     * @param string      $documentId
     * @param string      $targetId
     * @param string[]    $changedFields         field paths this request sets
     * @param array<string,mixed> $desiredAfterState  path => desired value
     * @param string|null $expectedDocumentVersion  optimistic-concurrency token
     * @param string|null $beforeSnapshotHash    hash of the target's before values (see hashState)
     * @param string      $correlationId
     * @param string|null $idempotencyKey
     * @param string|null $batchId
     * @param array       $projectionMeta        renderer hints (non-authoritative)
     */
    public function __construct(
        public readonly string  $operationId,
        public readonly string  $documentId,
        public readonly string  $targetId,
        public readonly array   $changedFields,
        public readonly array   $desiredAfterState,
        public readonly ?string $expectedDocumentVersion = null,
        public readonly ?string $beforeSnapshotHash = null,
        public readonly string  $correlationId = '',
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $batchId = null,
        public readonly array   $projectionMeta = [],
    ) {
    }

    /** Deterministic hash of a field-value map (order-independent). */
    public static function hashState(array $fieldValues): string
    {
        ksort($fieldValues);

        return substr(hash('sha256', (string) json_encode($fieldValues)), 0, 16);
    }

    public function toArray(): array
    {
        return [
            'schema_version'            => self::SCHEMA_VERSION,
            'operation_id'              => $this->operationId,
            'document_id'               => $this->documentId,
            'target_id'                 => $this->targetId,
            'changed_fields'            => $this->changedFields,
            'desired_after_state'       => $this->desiredAfterState,
            'expected_document_version' => $this->expectedDocumentVersion,
            'before_snapshot_hash'      => $this->beforeSnapshotHash,
            'correlation_id'            => $this->correlationId,
            'idempotency_key'           => $this->idempotencyKey,
            'batch_id'                  => $this->batchId,
            'projection_meta'           => $this->projectionMeta,
        ];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(
            operationId: ArrayGuard::str($a, 'operation_id'),
            documentId: ArrayGuard::str($a, 'document_id'),
            targetId: ArrayGuard::str($a, 'target_id'),
            changedFields: ArrayGuard::strList($a, 'changed_fields'),
            desiredAfterState: ArrayGuard::arr($a, 'desired_after_state'),
            expectedDocumentVersion: ArrayGuard::strOrNull($a, 'expected_document_version'),
            beforeSnapshotHash: ArrayGuard::strOrNull($a, 'before_snapshot_hash'),
            correlationId: ArrayGuard::str($a, 'correlation_id'),
            idempotencyKey: ArrayGuard::strOrNull($a, 'idempotency_key'),
            batchId: ArrayGuard::strOrNull($a, 'batch_id'),
            projectionMeta: ArrayGuard::arr($a, 'projection_meta'),
        );
    }
}

<?php

namespace App\Engines\Studio\Runtime;

/**
 * STUDIO888 · Runtime — an immutable snapshot of runtime state.
 *
 * A read-only value object describing the session at a moment: active document
 * + version, current selection, viewport, active transaction, whether a renderer
 * is attached, renderer capabilities, and metadata. Each StudioRuntime::state()
 * call returns a NEW snapshot; later runtime changes never mutate an earlier one.
 */
final class RuntimeState
{
    /**
     * @param string[] $selectedIds
     * @param array|null $capabilities projected renderer capability array, or null
     */
    public function __construct(
        public readonly ?string $documentId,
        public readonly ?string $documentVersion,
        public readonly array   $selectedIds,
        public readonly Viewport $viewport,
        public readonly ?string $transactionId,
        public readonly bool    $rendererAttached,
        public readonly ?array  $capabilities,
        public readonly array   $metadata,
    ) {
    }

    public function toArray(): array
    {
        return [
            'document_id'       => $this->documentId,
            'document_version'  => $this->documentVersion,
            'selected_ids'      => $this->selectedIds,
            'viewport'          => $this->viewport->toArray(),
            'transaction_id'    => $this->transactionId,
            'renderer_attached' => $this->rendererAttached,
            'capabilities'      => $this->capabilities,
            'metadata'          => $this->metadata,
        ];
    }
}

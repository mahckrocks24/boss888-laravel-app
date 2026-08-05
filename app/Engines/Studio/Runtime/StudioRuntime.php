<?php

namespace App\Engines\Studio\Runtime;

use App\Engines\Studio\Bridge\Contracts\SelectionProjectionBridgeInterface;
use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\ProjectionCapability;
use App\Engines\Studio\Projection\StudioDocumentVersion;
use App\Engines\Studio\Runtime\Contracts\StudioRuntimeInterface;
use App\Engines\Studio\Runtime\Events\RuntimeEvent;
use App\Engines\Studio\Runtime\Events\RuntimeEventBus;
use App\Engines\Studio\Runtime\Events\RuntimeEventType;
use App\Engines\Studio\Selection\SelectionResult;
use LogicException;

/**
 * STUDIO888 · Runtime — the default Studio Runtime (session coordinator).
 *
 * Owns editor session state (active document + version, selection, viewport,
 * transaction, renderer, capabilities, metadata, event bus) and coordinates
 * execution through the Selection→Projection bridge and the attached renderer.
 *
 * The runtime OWNS STATE. The engine owns behaviour. The renderer owns drawing.
 * It executes no AI, mutates no HTML, knows no DOM/studio.js/browser/iframe/
 * Canvas, and references no concrete renderer — only interfaces.
 */
final class StudioRuntime implements StudioRuntimeInterface
{
    private ?string $documentId = null;
    private ?SemanticGraphInterface $graph = null;
    private ?StudioDocumentVersion $version = null;
    private ?SelectionResult $selection = null;
    private Viewport $viewport;
    private ?StudioProjectionAdapterInterface $renderer = null;
    private ?ProjectionCapability $capabilities = null;
    private ?RuntimeTransaction $transaction = null;
    private array $metadata = [];
    private int $transactionCounter = 0;

    /** @var RuntimeTransaction[] */
    private array $committed = [];

    public function __construct(
        private readonly SelectionProjectionBridgeInterface $bridge,
        private readonly RuntimeEventBus $events = new RuntimeEventBus(),
        private readonly ExecutionContext $context = new ExecutionContext(),
    ) {
        $this->viewport = new Viewport();
    }

    // --- document / renderer lifecycle ---

    public function loadDocument(string $documentId, SemanticGraphInterface $graph, ?StudioDocumentVersion $version = null): void
    {
        $this->documentId = $documentId;
        $this->graph = $graph;
        $this->version = $version;
        $this->emit(RuntimeEventType::DOCUMENT_LOADED, ['document_id' => $documentId, 'version' => $version?->token]);
    }

    public function attachRenderer(StudioProjectionAdapterInterface $renderer): void
    {
        $this->renderer = $renderer;
        $this->capabilities = $renderer->capability();
        if ($this->version === null) {
            $this->version = $renderer->currentVersion();
        }
        $this->emit(RuntimeEventType::RENDERER_ATTACHED, ['capabilities' => $this->capabilities->toArray()]);
    }

    public function detachRenderer(): void
    {
        $this->emit(RuntimeEventType::RENDERER_DETACHED, []);
        $this->renderer = null;
        $this->capabilities = null;
    }

    public function currentRenderer(): ?StudioProjectionAdapterInterface
    {
        return $this->renderer;
    }

    public function capabilities(): ?ProjectionCapability
    {
        return $this->capabilities;
    }

    // --- selection / viewport ---

    public function setSelection(SelectionResult $selection): void
    {
        $this->selection = $selection;
        $this->emit(RuntimeEventType::SELECTION_CHANGED, ['selected_ids' => $selection->elementIds(), 'status' => $selection->status]);
    }

    public function currentSelection(): ?SelectionResult
    {
        return $this->selection;
    }

    public function updateViewport(Viewport $viewport): void
    {
        $this->viewport = $viewport;
        $this->emit(RuntimeEventType::VIEWPORT_CHANGED, ['viewport' => $viewport->toArray()]);
    }

    public function viewport(): Viewport
    {
        return $this->viewport;
    }

    // --- transactions ---

    public function beginTransaction(?string $label = null): RuntimeTransaction
    {
        if ($this->transaction !== null && $this->transaction->isOpen()) {
            return $this->transaction;
        }
        $this->transaction = new RuntimeTransaction('txn-' . (++$this->transactionCounter), $label);
        $this->emit(RuntimeEventType::TRANSACTION_STARTED, ['transaction_id' => $this->transaction->id, 'label' => $label]);

        return $this->transaction;
    }

    public function currentTransaction(): ?RuntimeTransaction
    {
        return $this->transaction;
    }

    public function commitTransaction(): ?RuntimeTransaction
    {
        if ($this->transaction === null) {
            return null;
        }
        $txn = $this->transaction;
        $txn->commit();
        $this->committed[] = $txn;
        $this->transaction = null;
        $this->emit(RuntimeEventType::HISTORY_COMMITTED, [
            'transaction_id' => $txn->id, 'operation_count' => $txn->operationCount(), 'applied_count' => $txn->appliedCount(),
        ]);

        return $txn;
    }

    /** @return RuntimeTransaction[] */
    public function committedTransactions(): array
    {
        return $this->committed;
    }

    // --- coordination ---

    public function execute(Operation $operation, ?string $idempotencyKey = null): ExecutionResult
    {
        if ($this->renderer === null) {
            throw new LogicException('StudioRuntime::execute — no renderer attached.');
        }
        if ($this->graph === null) {
            throw new LogicException('StudioRuntime::execute — no document loaded.');
        }
        if ($this->selection === null) {
            throw new LogicException('StudioRuntime::execute — no selection set.');
        }

        $txn = $this->beginTransaction();

        $this->emit(RuntimeEventType::OPERATION_STARTED, [
            'operation' => $operation->type, 'transaction_id' => $txn->id, 'target_hint' => $this->selection->resolvedId(),
        ]);

        $result = $this->bridge->project(
            $this->selection, $this->graph, $operation, $this->renderer, $this->context, $this->version?->token, $idempotencyKey
        );
        $txn->record($result);

        if ($result->isSuccess()) {
            $rendererVersion = $result->meta['renderer_version'] ?? null;
            if (is_string($rendererVersion)) {
                $this->version = StudioDocumentVersion::of($rendererVersion);
            }
            $this->emit(RuntimeEventType::PROJECTION_APPLIED, [
                'operation' => $operation->type, 'target' => $result->targetId,
                'renderer_version' => $rendererVersion, 'result' => $result,
            ]);
        } else {
            $this->emit(RuntimeEventType::PROJECTION_FAILED, [
                'operation' => $operation->type, 'reason' => $result->failureReason, 'result' => $result,
            ]);
        }

        $this->emit(RuntimeEventType::OPERATION_COMPLETED, ['operation' => $operation->type, 'status' => $result->status]);

        return $result;
    }

    // --- state / metadata / events ---

    public function state(): RuntimeState
    {
        return new RuntimeState(
            documentId: $this->documentId,
            documentVersion: $this->version?->token,
            selectedIds: $this->selection?->elementIds() ?? [],
            viewport: $this->viewport,
            transactionId: $this->transaction?->id,
            rendererAttached: $this->renderer !== null,
            capabilities: $this->capabilities?->toArray(),
            metadata: $this->metadata,
        );
    }

    public function events(): RuntimeEventBus
    {
        return $this->events;
    }

    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    private function emit(string $type, array $payload): void
    {
        $this->events->emit(new RuntimeEvent($type, $payload));
    }
}

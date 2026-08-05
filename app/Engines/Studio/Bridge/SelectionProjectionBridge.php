<?php

namespace App\Engines\Studio\Bridge;

use App\Engines\Studio\Bridge\Contracts\SelectionProjectionBridgeInterface;
use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\Support\ElementPatch;
use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use App\Engines\Studio\Selection\SelectionResult;

/**
 * STUDIO888 · Bridge — default Selection → Projection bridge (dormant, offline).
 *
 * Proves the whole engine end-to-end without touching a renderer:
 * gate the selection, plan against the semantic StudioElement, build a
 * ProjectionRequest, hand it to a Projection ADAPTER, then map the verified
 * ProjectionResult into an ExecutionResult. No HTML/DOM/browser/studio.js/
 * routes/Runtime/persistence/billing/AI anywhere — only interfaces.
 */
final class SelectionProjectionBridge implements SelectionProjectionBridgeInterface
{
    public function project(
        SelectionResult $selection,
        SemanticGraphInterface $graph,
        Operation $operation,
        StudioProjectionAdapterInterface $adapter,
        ExecutionContext $context,
        ?string $baseVersion = null,
        ?string $idempotencyKey = null,
    ): ExecutionResult {
        $gate = $this->gate($selection, $operation, $context);
        if ($gate !== null) {
            return $gate;
        }

        $id = (string) $selection->resolvedId();
        $element = $graph->element($id);
        if ($element === null) {
            return ExecutionResult::rejected($operation->withTarget($id), ExecutionResult::R_TARGET_NOT_FOUND, "Resolved element '{$id}' is not in the graph.", $selection->topConfidence());
        }

        $plan = $this->plan($element, $operation, $selection->topConfidence());
        if (! $plan->isValid()) {
            return ExecutionResult::rejected($operation->withTarget($id), ExecutionResult::R_UNKNOWN_OPERATION, "Bridge cannot plan operation '{$operation->type}'.", $selection->topConfidence());
        }

        $version = $baseVersion ?? $adapter->currentVersion()->token;
        $request = new ProjectionRequest(
            operationId: $operation->opId,
            documentId: 'doc',
            targetId: $id,
            changedFields: $plan->changedFields,
            desiredAfterState: $plan->desiredAfterState,
            expectedDocumentVersion: $version,
            beforeSnapshotHash: ProjectionRequest::hashState($plan->beforeState),
            correlationId: $operation->opId,
            idempotencyKey: $idempotencyKey,
        );

        return $this->map($adapter->project($request), $plan);
    }

    public function projectBatch(
        array $intents,
        SemanticGraphInterface $graph,
        StudioProjectionAdapterInterface $adapter,
        ExecutionContext $context,
        ProjectionTransactionBoundary $boundary,
    ): array {
        // Resolve + plan each intent. A gate/plan failure is a bridge-level rejection.
        $plans = [];       // intentIndex => ExecutionPlan
        $requests = [];    // intentIndex => ProjectionRequest
        $failures = [];    // intentIndex => ExecutionResult

        foreach ($intents as $i => $intent) {
            $sel = $intent['selection'];
            $op = $intent['operation'];
            $key = $intent['key'] ?? null;

            $gate = $this->gate($sel, $op, $context);
            if ($gate !== null) {
                $failures[$i] = $gate;
                continue;
            }
            $id = (string) $sel->resolvedId();
            $el = $graph->element($id);
            if ($el === null) {
                $failures[$i] = ExecutionResult::rejected($op->withTarget($id), ExecutionResult::R_TARGET_NOT_FOUND, "Resolved element '{$id}' is not in the graph.", $sel->topConfidence());
                continue;
            }
            $plan = $this->plan($el, $op, $sel->topConfidence());
            if (! $plan->isValid()) {
                $failures[$i] = ExecutionResult::rejected($op->withTarget($id), ExecutionResult::R_UNKNOWN_OPERATION, "Bridge cannot plan operation '{$op->type}'.", $sel->topConfidence());
                continue;
            }
            $plans[$i] = $plan;
            // Batch requests carry NO expected-version (the batch is one logical
            // transaction; the version advances between ops). Per-element snapshot
            // hashing still guards each target.
            $requests[$i] = new ProjectionRequest(
                operationId: $op->opId, documentId: 'doc', targetId: $id,
                changedFields: $plan->changedFields, desiredAfterState: $plan->desiredAfterState,
                expectedDocumentVersion: null, beforeSnapshotHash: ProjectionRequest::hashState($plan->beforeState),
                correlationId: $op->opId, idempotencyKey: $key,
            );
        }

        // Atomic batch cannot proceed if any intent failed to resolve/plan.
        if ($boundary->atomic && $failures !== []) {
            $results = [];
            foreach ($intents as $i => $intent) {
                $results[$i] = $failures[$i]
                    ?? ExecutionResult::rejected($intent['operation'], ExecutionResult::R_VALIDATION_FAILED, 'Atomic batch aborted: a sibling operation could not be resolved.', null);
            }
            ksort($results);

            return ['results' => array_values($results), 'status' => ExecutionResult::REJECTED];
        }

        // Project the resolvable requests as a batch.
        $gatedIndices = array_keys($requests);
        $batchResult = $adapter->projectBatch(new ProjectionBatch('bridge', 'doc', array_values($requests), $boundary));

        // Re-assemble ExecutionResults in original intent order.
        $mappedByIndex = [];
        foreach ($gatedIndices as $k => $intentIndex) {
            $pr = $batchResult->results[$k] ?? null;
            $mappedByIndex[$intentIndex] = $pr !== null
                ? $this->map($pr, $plans[$intentIndex])
                : ExecutionResult::rejected($intents[$intentIndex]['operation'], ExecutionResult::R_VERIFICATION_FAILED, 'No projection result returned.', null);
        }
        $results = [];
        foreach ($intents as $i => $intent) {
            $results[$i] = $mappedByIndex[$i] ?? $failures[$i];
        }
        ksort($results);

        return ['results' => array_values($results), 'status' => $this->mapStatus($batchResult->status)];
    }

    // --- internals ---

    private function gate(SelectionResult $sel, Operation $op, ExecutionContext $ctx): ?ExecutionResult
    {
        if ($sel->status === SelectionResult::NOT_FOUND) {
            return ExecutionResult::rejected($op, ExecutionResult::R_TARGET_NOT_FOUND, 'No element matched the selection.', 0.0);
        }
        if ($sel->isAmbiguous()) {
            return ExecutionResult::ambiguous($op, count($sel->candidates) . ' candidates matched; not executing.', $sel->topConfidence());
        }
        if ($sel->topConfidence() < $ctx->confidenceThreshold) {
            return ExecutionResult::rejected($op, ExecutionResult::R_LOW_CONFIDENCE, "Confidence {$sel->topConfidence()} below threshold {$ctx->confidenceThreshold}.", $sel->topConfidence());
        }
        if ($sel->resolvedId() === null) {
            return ExecutionResult::rejected($op, ExecutionResult::R_AMBIGUOUS_TARGET, 'Selection did not yield a single target.', $sel->topConfidence());
        }

        return null;
    }

    private function plan(StudioElement $element, Operation $op, float $confidence): ExecutionPlan
    {
        [$path, $desired] = match ($op->type) {
            'replace_text' => ['text', (string) $op->value],
            'append_text'  => ['text', (string) ($element->text ?? '') . (string) $op->value],
            'prepend_text' => ['text', (string) $op->value . (string) ($element->text ?? '')],
            'set_style'    => ['style.' . (string) $op->property, $op->value],
            'hide'         => ['visible', false],
            'show'         => ['visible', true],
            default        => ['', null],
        };

        if ($path === '') {
            return new ExecutionPlan($op->opId, $op->type, $element->id, $op->property, [], [], [], $confidence);
        }

        $before = [$path => ElementPatch::get($element, $path)];

        return new ExecutionPlan($op->opId, $op->type, $element->id, $op->property, [$path], [$path => $desired], $before, $confidence);
    }

    private function map(ProjectionResult $pr, ExecutionPlan $plan): ExecutionResult
    {
        $status = $this->mapStatus($pr->status);
        $isSuccess = $status === ExecutionResult::APPLIED || $status === ExecutionResult::PARTIALLY_APPLIED;
        $primary = $plan->primaryField();
        $normalized = $primary !== null ? ($pr->actualAfterState[$primary] ?? null) : null;

        return new ExecutionResult(
            operationId: $pr->operationId,
            operationType: $plan->operationType,
            targetId: $plan->targetId,
            status: $status,
            beforeState: $plan->beforeState,
            afterState: $pr->actualAfterState,
            normalizedValue: $normalized,
            failureReason: $isSuccess ? null : $pr->errorReason,
            explanation: $pr->explanation,
            reversible: true,
            changedFields: $pr->appliedFields,
            confidence: $plan->confidence,
            meta: [
                'via'               => 'projection',
                'projection_status' => $pr->status,
                'renderer_version'  => $pr->rendererVersion,
                'verified'          => $pr->verification['verified'] ?? false,
                'replay'            => $pr->replay,
            ],
        );
    }

    private function mapStatus(string $projectionStatus): string
    {
        return match ($projectionStatus) {
            ProjectionStatus::APPLIED => ExecutionResult::APPLIED,
            ProjectionStatus::PARTIAL => ExecutionResult::PARTIALLY_APPLIED,
            ProjectionStatus::FAILED  => ExecutionResult::FAILED,
            ProjectionStatus::STALE_VERSION,
            ProjectionStatus::TARGET_MISSING,
            ProjectionStatus::UNSUPPORTED,
            ProjectionStatus::REJECTED => ExecutionResult::REJECTED,
            default                    => ExecutionResult::FAILED,
        };
    }
}

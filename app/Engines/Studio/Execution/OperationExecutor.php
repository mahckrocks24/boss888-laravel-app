<?php

namespace App\Engines\Studio\Execution;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\ExecutionVerifierInterface;
use App\Engines\Studio\Execution\Contracts\OperationExecutorInterface;
use App\Engines\Studio\Execution\Contracts\StudioDocumentAdapterInterface;
use App\Engines\Studio\Execution\Handlers\SetStyleOperationHandler;
use App\Engines\Studio\Execution\Support\ElementPatch;
use App\Engines\Studio\Selection\SelectionResult;
use App\Engines\Studio\Transform\Contracts\CapabilityRegistryInterface;
use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;
use App\Engines\Studio\Transform\Contracts\OperationValidatorInterface;

/**
 * STUDIO888 · Execution — default Operation Executor (dormant core).
 *
 * Enforces capability + validation, runs the resolved handler through the
 * document adapter, VERIFIES the change, and returns a typed ExecutionResult.
 * It reaches Studio Elements only through the adapter — never HTML/DOM. Success
 * is proven by after-state, never by any generated reply.
 */
final class OperationExecutor implements OperationExecutorInterface
{
    public function __construct(
        private readonly OperationRegistryInterface $registry,
        private readonly CapabilityRegistryInterface $capabilities,
        private readonly OperationValidatorInterface $validator,
        private readonly OperationHandlerRegistry $handlers,
        private readonly ExecutionVerifierInterface $verifier,
    ) {
    }

    public function execute(Operation $op, StudioDocumentAdapterInterface $adapter, ExecutionContext $ctx): ExecutionResult
    {
        return $this->executeResolved($op, $adapter, $ctx, null);
    }

    public function executeWithSelection(Operation $op, SelectionResult $selection, StudioDocumentAdapterInterface $adapter, ExecutionContext $ctx): ExecutionResult
    {
        if ($selection->status === SelectionResult::NOT_FOUND) {
            return ExecutionResult::rejected($op, ExecutionResult::R_TARGET_NOT_FOUND, 'No element matched the request.', 0.0);
        }
        if ($selection->isAmbiguous()) {
            $n = count($selection->candidates);
            return ExecutionResult::ambiguous($op, "$n candidates matched; clarification required — not executing.", $selection->topConfidence());
        }

        $confidence = $selection->topConfidence();
        if ($confidence < $ctx->confidenceThreshold) {
            return ExecutionResult::rejected(
                $op, ExecutionResult::R_LOW_CONFIDENCE,
                "Confidence {$confidence} is below threshold {$ctx->confidenceThreshold} — not executing.", $confidence
            );
        }

        $id = $selection->resolvedId();
        if ($id === null) {
            return ExecutionResult::rejected($op, ExecutionResult::R_AMBIGUOUS_TARGET, 'Resolution did not yield a single target.', $confidence);
        }

        return $this->executeResolved($op->withTarget($id), $adapter, $ctx, $confidence);
    }

    private function executeResolved(Operation $op, StudioDocumentAdapterInterface $adapter, ExecutionContext $ctx, ?float $confidence): ExecutionResult
    {
        if ($op->targetId === null || $op->targetId === '') {
            return ExecutionResult::rejected($op, ExecutionResult::R_MISSING_TARGET, 'No target element.', $confidence);
        }

        $before = $adapter->find($op->targetId);
        if ($before === null) {
            return ExecutionResult::rejected($op, ExecutionResult::R_TARGET_NOT_FOUND, "Target '{$op->targetId}' not found.", $confidence);
        }

        if (! $this->registry->has($op->type)) {
            return ExecutionResult::rejected($op, ExecutionResult::R_UNKNOWN_OPERATION, "Operation '{$op->type}' is not registered.", $confidence);
        }
        if (! $this->capabilities->isAvailable($op->type)) {
            return ExecutionResult::rejected($op, ExecutionResult::R_UNAVAILABLE, "Operation '{$op->type}' is not available.", $confidence);
        }

        $def = $this->registry->get($op->type);
        if ($def !== null && $def->supportedTargets !== ['*'] && ! in_array($before->role, $def->supportedTargets, true)) {
            return ExecutionResult::rejected($op, ExecutionResult::R_UNSUPPORTED_TARGET, "Operation '{$op->type}' does not support a '{$before->role}' target.", $confidence);
        }

        if ($before->locked && $op->type !== 'unlock') {
            return ExecutionResult::rejected($op, ExecutionResult::R_LOCKED_TARGET, "Target '{$op->targetId}' is locked.", $confidence);
        }

        $handler = $this->handlers->handlerFor($op->type);
        if ($handler === null) {
            return ExecutionResult::rejected($op, ExecutionResult::R_NO_HANDLER, "No handler for '{$op->type}'.", $confidence);
        }

        // Safe-subset property guard for style edits.
        if ($handler instanceof SetStyleOperationHandler && ! $handler->supportsProperty((string) $op->property)) {
            return ExecutionResult::rejected($op, ExecutionResult::R_UNSUPPORTED_PROPERTY, "Property '{$op->property}' is not in the safe set.", $confidence);
        }

        // Validation + normalization (Phase-1 validator; never trusts raw input).
        // Colour-style ops apply the canonical value; text ops keep the raw
        // validated value so append/prepend whitespace is preserved.
        $applyValue = $op->value;
        if ($handler->requiresValidation()) {
            $batch = ['version' => $this->registry->schemaVersion(), 'operations' => [[
                'op_id' => $op->opId, 'type' => $op->type, 'target' => ['id' => $op->targetId],
                'property' => $op->property, 'value' => $op->value,
            ]]];
            $vr = $this->validator->validate($batch);
            if (! $vr->ok) {
                $reason = $vr->rejected()[0]['failure_reason'] ?? ExecutionResult::R_VALIDATION_FAILED;
                return new ExecutionResult(
                    operationId: $op->opId, operationType: $op->type, targetId: $op->targetId,
                    status: ExecutionResult::REJECTED, failureReason: $reason,
                    explanation: "Validation rejected the operation ({$reason}).", confidence: $confidence,
                );
            }
            $normalized = $vr->accepted()[0]['normalized_value'] ?? $op->value;
            $applyValue = $handler->usesNormalizedValue() ? $normalized : $op->value;
        }

        $opEff = $op->withValue($applyValue);

        // Execute through the adapter, then read back the actual after-state.
        $after = $handler->apply($before, $opEff);
        $written = $adapter->replace($after, $op->type === 'unlock');
        if (! $written) {
            return ExecutionResult::rejected($op, ExecutionResult::R_LOCKED_TARGET, "Write to '{$op->targetId}' was rejected (locked).", $confidence);
        }
        $actualAfter = $adapter->find($op->targetId) ?? $after;

        // Verify: proof of success is the after-state, never a reply.
        $v = $this->verifier->verify($opEff, $handler, $before, $actualAfter);
        $status = $v['status'];
        $changed = $v['changed_fields'];

        $failureReason = null;
        if ($status === ExecutionResult::FAILED) {
            $failureReason = $changed === [] ? ExecutionResult::R_NO_CHANGE : ExecutionResult::R_VERIFICATION_FAILED;
        }

        return new ExecutionResult(
            operationId: $op->opId,
            operationType: $op->type,
            targetId: $op->targetId,
            status: $status,
            beforeState: ElementPatch::snapshot($before),
            afterState: ElementPatch::snapshot($actualAfter),
            normalizedValue: $applyValue,
            failureReason: $failureReason,
            explanation: $this->explain($status, $op, $before, $actualAfter, $changed, $v['explanation']),
            reversible: $handler->reversible(),
            changedFields: $changed,
            confidence: $confidence,
            meta: ['handler' => (new \ReflectionClass($handler))->getShortName(), 'verified' => true],
        );
    }

    private function explain(string $status, Operation $op, StudioElement $before, StudioElement $after, array $changed, string $vExplain): string
    {
        if ($status !== ExecutionResult::APPLIED && $status !== ExecutionResult::PARTIALLY_APPLIED) {
            return "Operation '{$op->type}' did not apply: {$vExplain}.";
        }
        $parts = [];
        foreach ($changed as $path) {
            $from = $this->scalar(ElementPatch::get($before, $path));
            $to = $this->scalar(ElementPatch::get($after, $path));
            $parts[] = "{$path} {$from} → {$to}";
        }

        return "Applied '{$op->type}': " . (($parts === []) ? $vExplain : implode('; ', $parts)) . '.';
    }

    private function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return 'array';
        }

        return (string) ($v ?? 'null');
    }
}

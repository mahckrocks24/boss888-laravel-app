<?php

namespace App\Engines\Studio\Execution;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\ExecutionVerifierInterface;
use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Support\ElementPatch;

/**
 * STUDIO888 · Execution — default verifier.
 *
 * Proof of success comes from state, never from a reply. It compares the actual
 * after-state to the handler's expected changes:
 *   - every expected field matches AND at least one field changed → applied
 *   - some expected fields match                                   → partially_applied
 *   - nothing matches                                             → failed
 *   - everything matches but nothing changed (a no-op)            → failed (no_change)
 */
final class ExecutionVerifier implements ExecutionVerifierInterface
{
    public function verify(Operation $op, OperationHandlerInterface $handler, StudioElement $before, StudioElement $after): array
    {
        $expected = $handler->expectedChanges($before, $op);

        $matched = 0;
        $total = count($expected);
        $changed = [];

        foreach ($expected as $path => $value) {
            $actual = ElementPatch::get($after, $path);
            if ($this->equals($actual, $value)) {
                $matched++;
            }
            if (! $this->equals(ElementPatch::get($before, $path), $actual)) {
                $changed[] = $path;
            }
        }

        if ($matched === 0) {
            return $this->outcome(ExecutionResult::FAILED, $changed, 'expected change did not occur');
        }
        if ($changed === []) {
            // A no-op: the state already matched. Never a fabricated success.
            return $this->outcome(ExecutionResult::FAILED, $changed, 'no change occurred (no-op)');
        }
        if ($matched < $total) {
            return $this->outcome(ExecutionResult::PARTIALLY_APPLIED, $changed, 'some fields applied');
        }

        return $this->outcome(ExecutionResult::APPLIED, $changed, 'verified against after-state');
    }

    private function outcome(string $status, array $changed, string $explanation): array
    {
        return ['status' => $status, 'changed_fields' => array_values($changed), 'explanation' => $explanation];
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if (is_float($a) || is_float($b)) {
            return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 1e-9;
        }

        return $a === $b;
    }
}

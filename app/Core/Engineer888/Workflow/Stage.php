<?php

namespace App\Core\Engineer888\Workflow;

/**
 * One stage of the engineering lifecycle.
 *
 * The quality standard requires every stage to declare its purpose, inputs,
 * outputs, failure modes, verification and recovery. Those are methods rather
 * than documentation so they can be asserted by tests and printed in the audit
 * trail — a stage that cannot say how it fails has not been thought through.
 */
interface Stage
{
    /** Stable identifier, e.g. ANALYZE. */
    public function name(): string;

    /** Why this stage exists. */
    public function purpose(): string;

    /** @return array<int,string> what it needs from the context */
    public function inputs(): array;

    /** @return array<int,string> what it adds to the context */
    public function outputs(): array;

    /** @return array<int,string> how it can go wrong */
    public function failureModes(): array;

    /** How its own output is checked. */
    public function verification(): string;

    /** What to do when it fails. */
    public function recovery(): string;

    public function run(WorkflowContext $context): StageResult;
}

<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use PHPUnit\Framework\TestCase;

/** Pure - executor over an in-memory adapter; no HTML/DOM/DB/Runtime/provider. */
final class OperationExecutorTest extends TestCase
{
    private function runOp(Operation $op): array
    {
        $adapter = ExecutorFixture::adapter();
        $result = ExecutorFixture::executor()->execute($op, $adapter, new ExecutionContext());

        return [$result, $adapter];
    }

    // ---- TEXT ----

    public function test_replace_text(): void
    {
        [$r, $a] = $this->runOp(new Operation('replace_text', 'para', value: 'World'));
        $this->assertSame(ExecutionResult::APPLIED, $r->status);
        $this->assertSame('World', $a->find('para')->text);
        $this->assertSame(['text'], $r->changedFields);
        $this->assertTrue($r->reversible);
    }

    public function test_append_text(): void
    {
        [$r, $a] = $this->runOp(new Operation('append_text', 'para', value: ' World'));
        $this->assertSame(ExecutionResult::APPLIED, $r->status);
        $this->assertSame('Hello World', $a->find('para')->text);
    }

    public function test_prepend_text(): void
    {
        [$r, $a] = $this->runOp(new Operation('prepend_text', 'para', value: 'Say '));
        $this->assertSame('Say Hello', $a->find('para')->text);
    }

    // ---- STYLE ----

    public function test_set_text_color_normalizes_and_applies(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'stat', 'color', 'red'));
        $this->assertSame(ExecutionResult::APPLIED, $r->status);
        $this->assertSame('#ff0000', $r->normalizedValue);
        $this->assertSame('#ff0000', $a->find('stat')->style['color']);
        $this->assertSame(['style.color'], $r->changedFields);
    }

    public function test_background_color(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'headline', 'background-color', '#000'));
        $this->assertSame('#000000', $r->normalizedValue);
        $this->assertSame('#000000', $a->find('headline')->style['background-color']);
    }

    public function test_opacity(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'headline', 'opacity', '0.5'));
        $this->assertSame(ExecutionResult::APPLIED, $r->status);
        $this->assertSame('0.5', $a->find('headline')->style['opacity']);
    }

    public function test_font_size(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'headline', 'font-size', '60px'));
        $this->assertSame('60px', $a->find('headline')->style['font-size']);
    }

    public function test_font_weight(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'headline', 'font-weight', '700'));
        $this->assertSame('700', $a->find('headline')->style['font-weight']);
    }

    public function test_text_align(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'headline', 'text-align', 'center'));
        $this->assertSame('center', $a->find('headline')->style['text-align']);
    }

    // ---- STATE ----

    public function test_hide_and_show(): void
    {
        $adapter = ExecutorFixture::adapter();
        $exec = ExecutorFixture::executor();
        $ctx = new ExecutionContext();

        $hide = $exec->execute(new Operation('hide', 'headline'), $adapter, $ctx);
        $this->assertSame(ExecutionResult::APPLIED, $hide->status);
        $this->assertFalse($adapter->find('headline')->visible);

        $show = $exec->execute(new Operation('show', 'headline'), $adapter, $ctx);
        $this->assertSame(ExecutionResult::APPLIED, $show->status);
        $this->assertTrue($adapter->find('headline')->visible);
    }

    public function test_lock_and_unlock(): void
    {
        $adapter = ExecutorFixture::adapter();
        $exec = ExecutorFixture::executor();
        $ctx = new ExecutionContext();

        $lock = $exec->execute(new Operation('lock', 'headline'), $adapter, $ctx);
        $this->assertSame(ExecutionResult::APPLIED, $lock->status);
        $this->assertTrue($adapter->find('headline')->locked);

        $unlock = $exec->execute(new Operation('unlock', 'headline'), $adapter, $ctx);
        $this->assertSame(ExecutionResult::APPLIED, $unlock->status);
        $this->assertFalse($adapter->find('headline')->locked);
    }

    // ---- INTEGRITY ----

    public function test_immutable_replacement_preserves_untouched_elements(): void
    {
        [$r, $a] = $this->runOp(new Operation('replace_text', 'para', value: 'World'));
        $this->assertSame('98%', $a->find('stat')->text);
        $this->assertSame('Big Sale', $a->find('headline')->text);
        $this->assertSame('#111111', $a->find('headline')->style['color']);
    }

    public function test_before_and_after_state_captured(): void
    {
        [$r] = $this->runOp(new Operation('set_style', 'stat', 'color', 'red'));
        $this->assertSame('#ffd60a', $r->beforeState['style']['color']);
        $this->assertSame('#ff0000', $r->afterState['style']['color']);
    }

    // ---- REJECTIONS ----

    public function test_locked_target_rejected(): void
    {
        [$r, $a] = $this->runOp(new Operation('replace_text', 'locked', value: 'x'));
        $this->assertSame(ExecutionResult::REJECTED, $r->status);
        $this->assertSame(ExecutionResult::R_LOCKED_TARGET, $r->failureReason);
        $this->assertSame('locked', $a->find('locked')->text);
    }

    public function test_unlock_is_permitted_on_locked_target(): void
    {
        [$r, $a] = $this->runOp(new Operation('unlock', 'locked'));
        $this->assertSame(ExecutionResult::APPLIED, $r->status);
        $this->assertFalse($a->find('locked')->locked);
    }

    public function test_unsupported_operation_rejected(): void
    {
        [$r] = $this->runOp(new Operation('delete', 'para'));
        $this->assertSame(ExecutionResult::REJECTED, $r->status);
        $this->assertSame(ExecutionResult::R_UNKNOWN_OPERATION, $r->failureReason);
    }

    public function test_unavailable_capability_rejected(): void
    {
        [$r] = $this->runOp(new Operation('generate_video', 'logo', value: 'a cat'));
        $this->assertSame(ExecutionResult::REJECTED, $r->status);
        $this->assertSame(ExecutionResult::R_UNAVAILABLE, $r->failureReason);
    }

    public function test_invalid_validation_rejected(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'stat', 'color', 'not-a-color'));
        $this->assertSame(ExecutionResult::REJECTED, $r->status);
        $this->assertSame('invalid_color', $r->failureReason);
        $this->assertSame('#ffd60a', $a->find('stat')->style['color']); // untouched
    }

    public function test_unsafe_property_outside_safe_subset_rejected(): void
    {
        [$r] = $this->runOp(new Operation('set_style', 'stat', 'border-radius', '8px'));
        $this->assertSame(ExecutionResult::REJECTED, $r->status);
        $this->assertSame(ExecutionResult::R_UNSUPPORTED_PROPERTY, $r->failureReason);
    }

    public function test_missing_target_rejected(): void
    {
        [$r] = $this->runOp(new Operation('replace_text', null, value: 'x'));
        $this->assertSame(ExecutionResult::R_MISSING_TARGET, $r->failureReason);
    }

    public function test_target_not_found_rejected(): void
    {
        [$r] = $this->runOp(new Operation('replace_text', 'ghost', value: 'x'));
        $this->assertSame(ExecutionResult::R_TARGET_NOT_FOUND, $r->failureReason);
    }

    // ---- NO-OP IS NOT SUCCESS ----

    public function test_no_op_does_not_report_success(): void
    {
        [$r, $a] = $this->runOp(new Operation('set_style', 'stat', 'color', '#ffd60a')); // already this colour
        $this->assertSame(ExecutionResult::FAILED, $r->status);
        $this->assertSame(ExecutionResult::R_NO_CHANGE, $r->failureReason);
        $this->assertFalse($r->isSuccess());
        $this->assertSame([], $r->changedFields);
    }
}

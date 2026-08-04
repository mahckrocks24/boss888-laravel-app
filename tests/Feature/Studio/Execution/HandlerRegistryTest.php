<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Handlers\SetStyleOperationHandler;
use App\Engines\Studio\Execution\Handlers\TextOperationHandler;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\OperationHandlerRegistry;
use PHPUnit\Framework\TestCase;

/** Pure — plugin handler discovery, no central switch. */
final class HandlerRegistryTest extends TestCase
{
    public function test_default_handlers_are_discoverable(): void
    {
        $r = OperationHandlerRegistry::withDefaults();
        foreach (['replace_text', 'append_text', 'prepend_text', 'set_style', 'hide', 'show', 'lock', 'unlock'] as $type) {
            $this->assertTrue($r->has($type), "missing handler for $type");
        }
        $this->assertInstanceOf(SetStyleOperationHandler::class, $r->handlerFor('set_style'));
        $this->assertInstanceOf(TextOperationHandler::class, $r->handlerFor('replace_text'));
    }

    public function test_unknown_type_has_no_handler(): void
    {
        $this->assertNull(OperationHandlerRegistry::withDefaults()->handlerFor('teleport'));
    }

    public function test_new_handler_self_registers_without_editing_a_switch(): void
    {
        $r = new OperationHandlerRegistry();
        $this->assertFalse($r->has('sparkle'));

        $r->register(new class implements OperationHandlerInterface {
            public function supportedTypes(): array { return ['sparkle']; }
            public function reversible(): bool { return true; }
            public function requiresValidation(): bool { return false; }
            public function usesNormalizedValue(): bool { return false; }
            public function changedFieldsFor(Operation $op): array { return ['state']; }
            public function expectedChanges(StudioElement $before, Operation $op): array { return []; }
            public function apply(StudioElement $before, Operation $op): StudioElement { return $before; }
        });

        $this->assertTrue($r->has('sparkle'));
    }
}

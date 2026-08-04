<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Execution\Contracts\ExecutionVerifierInterface;
use App\Engines\Studio\Execution\Contracts\OperationExecutorInterface;
use App\Engines\Studio\Execution\Contracts\StudioDocumentAdapterInterface;
use App\Engines\Studio\Execution\ExecutionVerifier;
use App\Engines\Studio\Execution\InMemoryStudioDocumentAdapter;
use App\Engines\Studio\Execution\OperationExecutor;
use App\Engines\Studio\Execution\OperationHandlerRegistry;
use App\Engines\Studio\Execution\StudioExecutionServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/** Dependency inversion — pure bare container, no app/DB/Runtime boot. */
final class ExecutionBindingTest extends TestCase
{
    private function container(): Container
    {
        $c = new Container();
        (new StudioExecutionServiceProvider($c))->register();

        return $c;
    }

    public function test_interfaces_resolve_to_defaults(): void
    {
        $c = $this->container();
        $this->assertInstanceOf(OperationExecutor::class, $c->make(OperationExecutorInterface::class));
        $this->assertInstanceOf(ExecutionVerifier::class, $c->make(ExecutionVerifierInterface::class));
        $this->assertInstanceOf(InMemoryStudioDocumentAdapter::class, $c->make(StudioDocumentAdapterInterface::class));
        $this->assertInstanceOf(OperationHandlerRegistry::class, $c->make(OperationHandlerRegistry::class));
    }

    public function test_concretes_honour_contracts(): void
    {
        $this->assertInstanceOf(ExecutionVerifierInterface::class, new ExecutionVerifier());
        $this->assertInstanceOf(StudioDocumentAdapterInterface::class, new InMemoryStudioDocumentAdapter());
    }

    public function test_executor_binding_is_singleton(): void
    {
        $c = $this->container();
        $this->assertSame($c->make(OperationExecutorInterface::class), $c->make(OperationExecutorInterface::class));
    }
}

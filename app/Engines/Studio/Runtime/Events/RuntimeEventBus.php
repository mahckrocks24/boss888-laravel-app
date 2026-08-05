<?php

namespace App\Engines\Studio\Runtime\Events;

/**
 * STUDIO888 · Runtime — in-process event bus.
 *
 * A pure pub/sub for runtime coordination signals. Listeners are callables
 * invoked synchronously in subscription order. No transport, no queue, no
 * persistence, no browser. Collaboration would later ride ON this model but is
 * NOT implemented here.
 */
final class RuntimeEventBus
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    /** @var array<int, callable> */
    private array $wildcard = [];

    /** Subscribe to one event type. */
    public function on(string $type, callable $listener): void
    {
        $this->listeners[$type][] = $listener;
    }

    /** Subscribe to every event (e.g. a recorder). */
    public function onAny(callable $listener): void
    {
        $this->wildcard[] = $listener;
    }

    public function emit(RuntimeEvent $event): void
    {
        foreach ($this->listeners[$event->type] ?? [] as $listener) {
            $listener($event);
        }
        foreach ($this->wildcard as $listener) {
            $listener($event);
        }
    }
}

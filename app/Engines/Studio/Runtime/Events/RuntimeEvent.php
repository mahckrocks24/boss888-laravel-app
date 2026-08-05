<?php

namespace App\Engines\Studio\Runtime\Events;

/**
 * STUDIO888 · Runtime — an emitted runtime event (immutable).
 */
final class RuntimeEvent
{
    public function __construct(
        public readonly string $type,
        public readonly array  $payload = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}

<?php

namespace App\Engines\Studio\Execution;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\StudioDocumentAdapterInterface;
use App\Engines\Studio\Execution\Support\ElementPatch;

/**
 * STUDIO888 · Execution — in-memory document adapter (for tests + dormant core).
 *
 * Holds StudioElements in memory and replaces them immutably. It performs NO
 * persistence, touches NO renderer, and knows nothing about HTML. A locked
 * element rejects writes unless the write is an unlock.
 */
final class InMemoryStudioDocumentAdapter implements StudioDocumentAdapterInterface
{
    /** @var array<string, StudioElement> */
    private array $byId = [];

    /** @param StudioElement[] $elements */
    public function __construct(array $elements = [])
    {
        foreach ($elements as $e) {
            $this->byId[$e->id] = $e;
        }
    }

    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(string $id): ?StudioElement
    {
        return $this->byId[$id] ?? null;
    }

    public function replace(StudioElement $after, bool $isUnlock = false): bool
    {
        $current = $this->byId[$after->id] ?? null;

        // Reject writes to a locked element unless this write unlocks it.
        if ($current !== null && $current->locked && ! $isUnlock) {
            return false;
        }

        // Immutable replacement: only this id changes; all others are preserved.
        $this->byId[$after->id] = $after;

        return true;
    }

    public function snapshot(): array
    {
        $out = [];
        foreach ($this->byId as $id => $e) {
            $out[$id] = ElementPatch::snapshot($e);
        }

        return $out;
    }
}

<?php

namespace App\Engines\Studio\Document;

use App\Engines\Studio\Document\Contracts\StudioElementRepositoryInterface;

/**
 * STUDIO888 · Studio Document Model — in-memory element source.
 *
 * The Phase-2 default source: a document is just an array of StudioElements
 * plus canvas facts. No HTML, no DB, no renderer. Later phases may add other
 * implementations of the interface; this one keeps everything pure and testable.
 */
final class InMemoryStudioElementRepository implements StudioElementRepositoryInterface
{
    /** @var array<string, StudioElement> */
    private array $byId = [];

    /**
     * @param StudioElement[] $elements
     * @param array{width?:float,height?:float,fold?:float} $canvas
     */
    public function __construct(
        array $elements = [],
        private array $canvas = ['width' => 1080.0, 'height' => 1080.0, 'fold' => 600.0],
    ) {
        foreach ($elements as $e) {
            $this->byId[$e->id] = $e;
        }
        $this->canvas = [
            'width'  => (float) ($canvas['width'] ?? 1080.0),
            'height' => (float) ($canvas['height'] ?? 1080.0),
            'fold'   => (float) ($canvas['fold'] ?? 600.0),
        ];
    }

    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(string $id): ?StudioElement
    {
        return $this->byId[$id] ?? null;
    }

    public function canvas(): array
    {
        return $this->canvas;
    }
}

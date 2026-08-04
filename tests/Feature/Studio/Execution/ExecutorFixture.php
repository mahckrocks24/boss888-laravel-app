<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Document\Bbox;
use App\Engines\Studio\Document\SemanticGraph;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\ExecutionVerifier;
use App\Engines\Studio\Execution\ExecutorOperationCatalog;
use App\Engines\Studio\Execution\InMemoryStudioDocumentAdapter;
use App\Engines\Studio\Execution\OperationExecutor;
use App\Engines\Studio\Execution\OperationHandlerRegistry;
use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\SelectionEngine;
use App\Engines\Studio\Transform\CapabilityRegistry;
use App\Engines\Studio\Transform\ColorNormalizer;
use App\Engines\Studio\Transform\OperationRegistry;
use App\Engines\Studio\Transform\OperationValidator;

/** Pure data + wiring for Phase-3A tests. No DB/Runtime/provider/browser. */
final class ExecutorFixture
{
    public const CANVAS = ['width' => 1080.0, 'height' => 1080.0, 'fold' => 600.0];

    /** @return StudioElement[] */
    public static function elements(): array
    {
        return [
            new StudioElement(id: 'stat', role: 'stat', text: '98%', semanticTags: ['metric'], visualTags: ['yellow'],
                bbox: new Bbox(60, 200, 180, 120), style: ['color' => '#ffd60a', 'font_size' => 72],
                capabilities: ['set_text', 'set_style']),
            new StudioElement(id: 'headline', role: 'headline', text: 'Big Sale',
                bbox: new Bbox(60, 80, 400, 90), style: ['color' => '#111111', 'font_size' => 48],
                capabilities: ['set_text', 'set_style']),
            new StudioElement(id: 'para', role: 'body', text: 'Hello',
                bbox: new Bbox(60, 700, 600, 40), capabilities: ['set_text']),
            new StudioElement(id: 'locked', role: 'body', text: 'locked', locked: true,
                bbox: new Bbox(60, 760, 600, 40), capabilities: ['set_text']),
            new StudioElement(id: 'logo', role: 'logo', bbox: new Bbox(20, 20, 80, 80),
                style: ['media_type' => 'image']),
        ];
    }

    public static function registry(): OperationRegistry
    {
        $r = new OperationRegistry(true);
        ExecutorOperationCatalog::registerInto($r);

        return $r;
    }

    public static function executor(): OperationExecutor
    {
        $r = self::registry();

        return new OperationExecutor(
            $r,
            new CapabilityRegistry($r),
            new OperationValidator($r, new ColorNormalizer()),
            OperationHandlerRegistry::withDefaults(),
            new ExecutionVerifier(),
        );
    }

    public static function adapter(): InMemoryStudioDocumentAdapter
    {
        return new InMemoryStudioDocumentAdapter(self::elements());
    }

    public static function graph(): SemanticGraph
    {
        return new SemanticGraph(self::elements(), self::CANVAS);
    }

    public static function engine(): SelectionEngine
    {
        return new SelectionEngine(new GraphElementResolver());
    }
}

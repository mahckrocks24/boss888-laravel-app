<?php

namespace Tests\Feature\Studio\Runtime;

use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Document\Bbox;
use App\Engines\Studio\Document\SemanticGraph;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Runtime\Events\RuntimeEventBus;
use App\Engines\Studio\Runtime\StudioRuntime;
use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\SelectionEngine;
use App\Engines\Studio\Selection\SelectionQuery;
use App\Engines\Studio\Selection\SelectionResult;

/** Pure fixture for Phase-5A. Semantic elements agree with the HTML document. */
final class RuntimeFixture
{
    public const CANVAS = ['width' => 1080.0, 'height' => 1080.0, 'fold' => 600.0];

    public static function elements(): array
    {
        return [
            new StudioElement(id: 'stat', role: 'stat', text: '98%', semanticTags: ['metric'], visualTags: ['yellow'],
                bbox: new Bbox(60, 200, 180, 120), style: ['color' => '#ffd60a'], capabilities: ['set_text', 'set_style']),
            new StudioElement(id: 'headline', role: 'headline', text: 'Big Sale', semanticTags: ['title'],
                bbox: new Bbox(60, 80, 400, 90), style: ['color' => '#111111'], capabilities: ['set_text', 'set_style']),
        ];
    }

    public static function rawHtml(): string
    {
        return "<!doctype html><html><body>\n"
            . "<span data-field=\"stat\" style=\"color:#ffd60a\">98%</span>\n"
            . "<h1 data-field=\"headline\" style=\"color:#111111\">Big Sale</h1>\n"
            . "</body></html>";
    }

    public static function graph(): SemanticGraph
    {
        return new SemanticGraph(self::elements(), self::CANVAS);
    }

    public static function adapter(): HtmlProjectionAdapter
    {
        return HtmlProjectionAdapter::forRawHtml(self::rawHtml());
    }

    public static function engine(): SelectionEngine
    {
        return new SelectionEngine(new GraphElementResolver());
    }

    public static function selectHeadline(SemanticGraph $graph): SelectionResult
    {
        return self::engine()->select(new SelectionQuery(role: 'headline'), $graph);
    }

    public static function selectYellowNumber(SemanticGraph $graph): SelectionResult
    {
        return self::engine()->select(new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']), $graph);
    }

    public static function runtime(): StudioRuntime
    {
        return new StudioRuntime(new SelectionProjectionBridge(), new RuntimeEventBus());
    }
}

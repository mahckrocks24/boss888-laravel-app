<?php

namespace Tests\Feature\Studio\Bridge;

use App\Engines\Studio\Document\Bbox;
use App\Engines\Studio\Document\SemanticGraph;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\SelectionEngine;
use App\Engines\Studio\Selection\SelectionQuery;
use App\Engines\Studio\Selection\SelectionResult;

/**
 * Pure fixture for Phase-4D. The semantic StudioElements and the raw HTML
 * document describe the SAME design with agreeing field values, so the bridge's
 * before-snapshot (from the element) matches the adapter's (from the HTML).
 */
final class BridgeFixture
{
    public const CANVAS = ['width' => 1080.0, 'height' => 1080.0, 'fold' => 600.0];

    /** @param string|null $headlineText override to simulate model/renderer drift */
    public static function elements(?string $headlineText = 'Big Sale'): array
    {
        return [
            new StudioElement(id: 'stat', role: 'stat', text: '98%', semanticTags: ['metric'], visualTags: ['yellow'],
                bbox: new Bbox(60, 200, 180, 120), style: ['color' => '#ffd60a', 'font-size' => '72px'],
                capabilities: ['set_text', 'set_style']),
            new StudioElement(id: 'cta_label', role: 'cta', text: 'Buy now', visualTags: ['yellow'],
                bbox: new Bbox(60, 360, 160, 48), style: ['color' => '#ffd60a'], capabilities: ['set_text', 'set_style']),
            new StudioElement(id: 'headline', role: 'headline', text: $headlineText, semanticTags: ['title'],
                bbox: new Bbox(60, 80, 400, 90), style: ['color' => '#111111'], capabilities: ['set_text', 'set_style']),
            new StudioElement(id: 'wrapper', role: 'image', bbox: new Bbox(600, 50, 300, 300),
                style: ['media_type' => 'image']),
        ];
    }

    public static function rawHtml(): string
    {
        return "<!doctype html><html><head><style>:root{--primary:#FFD60A}</style></head><body>\n"
            . "<span data-field=\"stat\" style=\"color:#ffd60a;font-size:72px\">98%</span>\n"
            . "<span data-field=\"cta_label\" style=\"color:#ffd60a\">Buy now</span>\n"
            . "<h1 data-field=\"headline\" style=\"color:#111111\">Big Sale</h1>\n"
            . "<div data-field=\"wrapper\"><img src=\"x.png\"/></div>\n"
            . "</body></html>";
    }

    public static function graph(?string $headlineText = 'Big Sale'): SemanticGraph
    {
        return new SemanticGraph(self::elements($headlineText), self::CANVAS);
    }

    public static function rawAdapter(): HtmlProjectionAdapter
    {
        return HtmlProjectionAdapter::forRawHtml(self::rawHtml());
    }

    public static function engine(): SelectionEngine
    {
        return new SelectionEngine(new GraphElementResolver());
    }

    public static function selectYellowNumber(): SelectionResult
    {
        return self::engine()->select(new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']), self::graph());
    }

    public static function selectRole(string $role, ?string $headlineText = 'Big Sale'): SelectionResult
    {
        return self::engine()->select(new SelectionQuery(role: $role), self::graph($headlineText));
    }

    public static function selectAllYellow(): SelectionResult
    {
        return self::engine()->select(new SelectionQuery(visualTags: ['yellow']), self::graph());
    }

    public static function selectNotFound(): SelectionResult
    {
        return self::engine()->select(new SelectionQuery(role: 'video'), self::graph());
    }

    // ---- structured ----

    public static function structuredElements(): array
    {
        return [
            new StudioElement(id: 'headline', role: 'headline', text: 'Big Sale', semanticTags: ['title'],
                bbox: new Bbox(60, 80, 400, 90), capabilities: ['set_text']),
            new StudioElement(id: 'sub', role: 'body', text: 'Today', bbox: new Bbox(60, 200, 400, 40), capabilities: ['set_text']),
        ];
    }

    public static function structuredGraph(): SemanticGraph
    {
        return new SemanticGraph(self::structuredElements(), self::CANVAS);
    }

    public static function structuredAdapter(): HtmlProjectionAdapter
    {
        return HtmlProjectionAdapter::forStructured([
            'template_slug' => 'promo',
            'fields'        => ['headline' => 'Big Sale', 'sub' => 'Today'],
            'meta'          => ['rev' => 1],
        ]);
    }
}

<?php

namespace Tests\Feature\Studio\Selection;

use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\SelectionEngine;
use App\Engines\Studio\Selection\SelectionQuery;
use App\Engines\Studio\Selection\SelectionResult;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Studio\Document\DocumentFixture;

/** Pure — resolution over the Semantic Graph; no HTML, no mutation. */
final class SelectionEngineTest extends TestCase
{
    private function engine(): SelectionEngine
    {
        return new SelectionEngine(new GraphElementResolver());
    }

    public function test_single_match_resolves(): void
    {
        $r = $this->engine()->select(new SelectionQuery(role: 'logo'), DocumentFixture::graph());
        $this->assertTrue($r->isResolved());
        $this->assertSame('logo', $r->resolvedId());
    }

    public function test_multiple_candidates_are_ambiguous_not_guessed(): void
    {
        $r = $this->engine()->select(new SelectionQuery(semanticTags: ['title']), DocumentFixture::graph());
        $this->assertTrue($r->isAmbiguous());
        $this->assertTrue($r->clarificationRequired);
        $this->assertCount(2, $r->candidates);
        $this->assertLessThan(0.6, $r->topConfidence());
        $this->assertEqualsCanonicalizing(['headline', 'sub_head'], $r->elementIds());
    }

    public function test_confidence_scoring_reflects_specificity(): void
    {
        $r = $this->engine()->select(
            new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']),
            DocumentFixture::graph()
        );
        $this->assertTrue($r->isResolved());
        $this->assertSame('stat', $r->resolvedId());
        $this->assertEqualsWithDelta(0.84, $r->topConfidence(), 0.001);
    }

    public function test_visual_tag_group_lookup(): void
    {
        $r = $this->engine()->select(
            new SelectionQuery(visualTags: ['yellow'], expectMultiple: true),
            DocumentFixture::graph()
        );
        $this->assertTrue($r->isResolved());
        $this->assertFalse($r->clarificationRequired);
        $this->assertEqualsCanonicalizing(['stat', 'cta'], $r->elementIds());
    }

    public function test_semantic_tag_lookup(): void
    {
        $r = $this->engine()->select(new SelectionQuery(semanticTags: ['metric']), DocumentFixture::graph());
        $this->assertSame('stat', $r->resolvedId());
    }

    public function test_largest_heading_superlative(): void
    {
        $r = $this->engine()->select(
            new SelectionQuery(role: 'headline', superlative: 'largest'),
            DocumentFixture::graph()
        );
        $this->assertSame('headline', $r->resolvedId());
        $this->assertGreaterThanOrEqual(0.92, $r->topConfidence());
        $this->assertStringContainsString('font_size', $r->top()->reason);
    }

    public function test_cta_resolves(): void
    {
        $r = $this->engine()->select(new SelectionQuery(role: 'cta'), DocumentFixture::graph());
        $this->assertSame('cta', $r->resolvedId());
    }

    public function test_everything_above_the_fold_is_a_group(): void
    {
        $r = $this->engine()->select(
            new SelectionQuery(region: 'above_fold', expectMultiple: true),
            DocumentFixture::graph()
        );
        $this->assertTrue($r->isResolved());
        $this->assertFalse($r->clarificationRequired);
        $ids = $r->elementIds();
        $this->assertContains('headline', $ids);
        $this->assertContains('stat', $ids);
        $this->assertNotContains('para1', $ids);
        $this->assertNotContains('sub_head', $ids);
    }

    public function test_ordinal_second_paragraph(): void
    {
        $r = $this->engine()->select(new SelectionQuery(role: 'body', ordinal: 2), DocumentFixture::graph());
        $this->assertSame('para2', $r->resolvedId());
    }

    public function test_hidden_filter(): void
    {
        $r = $this->engine()->select(new SelectionQuery(role: 'label', visible: false), DocumentFixture::graph());
        $this->assertSame('hidden_el', $r->resolvedId());
    }

    public function test_locked_filter(): void
    {
        $r = $this->engine()->select(new SelectionQuery(locked: true), DocumentFixture::graph());
        $this->assertSame('locked_el', $r->resolvedId());
    }

    public function test_not_found(): void
    {
        $r = $this->engine()->select(new SelectionQuery(role: 'video'), DocumentFixture::graph());
        $this->assertSame(SelectionResult::NOT_FOUND, $r->status);
        $this->assertSame([], $r->candidates);
    }

    public function test_relative_below_the_image(): void
    {
        $r = $this->engine()->select(
            new SelectionQuery(visualTags: ['small'], relativeTo: ['relation' => 'below', 'role' => 'hero']),
            DocumentFixture::graph()
        );
        $this->assertSame('small_note', $r->resolvedId());
    }
}

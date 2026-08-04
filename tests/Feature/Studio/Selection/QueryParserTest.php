<?php

namespace Tests\Feature\Studio\Selection;

use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\QueryParser;
use App\Engines\Studio\Selection\SelectionEngine;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Studio\Document\DocumentFixture;

/** Pure — deterministic phrase → query; no Runtime, no provider. */
final class QueryParserTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser();
    }

    public function test_yellow_number(): void
    {
        $q = $this->parser->parse('the yellow number');
        $this->assertContains('yellow', $q->visualTags);
        $this->assertContains('metric', $q->semanticTags);
    }

    public function test_biggest_heading(): void
    {
        $q = $this->parser->parse('the biggest heading');
        $this->assertSame('headline', $q->role);
        $this->assertSame('largest', $q->superlative);
        $this->assertNotContains('big', $q->visualTags); // "biggest" is a superlative, not a size tag
    }

    public function test_everything_above_the_fold(): void
    {
        $q = $this->parser->parse('everything above the fold');
        $this->assertSame('above_fold', $q->region);
        $this->assertTrue($q->expectMultiple);
    }

    public function test_second_paragraph(): void
    {
        $q = $this->parser->parse('the second paragraph');
        $this->assertSame('body', $q->role);
        $this->assertSame(2, $q->ordinal);
    }

    public function test_red_background_and_hero_image(): void
    {
        $bg = $this->parser->parse('the red background');
        $this->assertSame('background', $bg->role);
        $this->assertContains('red', $bg->visualTags);

        $hero = $this->parser->parse('the hero image');
        $this->assertSame('hero', $hero->role);
    }

    /**
     * @dataProvider phraseResolutions
     */
    public function test_phrases_resolve_end_to_end(string $phrase, string $expectedId): void
    {
        $engine = new SelectionEngine(new GraphElementResolver());
        $r = $engine->select($this->parser->parse($phrase), DocumentFixture::graph());
        $this->assertSame($expectedId, $r->resolvedId(), "phrase: $phrase");
    }

    public static function phraseResolutions(): array
    {
        return [
            ['the yellow number', 'stat'],
            ['the button', 'cta'],
            ['the CTA', 'cta'],
            ['the logo', 'logo'],
            ['the biggest heading', 'headline'],
            ['the red background', 'bg'],
            ['the hero image', 'hero_img'],
            ['the second paragraph', 'para2'],
        ];
    }

    public function test_the_title_is_ambiguous(): void
    {
        $engine = new SelectionEngine(new GraphElementResolver());
        $r = $engine->select($this->parser->parse('the title'), DocumentFixture::graph());
        $this->assertTrue($r->isAmbiguous());
        $this->assertTrue($r->clarificationRequired);
    }
}

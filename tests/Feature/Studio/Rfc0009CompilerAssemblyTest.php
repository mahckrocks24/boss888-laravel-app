<?php

namespace Tests\Feature\Studio;

use App\Core\ImageIntelligence\ImagePromptCompiler;
use Tests\TestCase;

/**
 * RFC-0009 P5 + P4 (2026-09-16) — the compiler is a deterministic, well-formed assembly
 * that guards factual grounding without rewriting the customer's intent.
 */
class Rfc0009CompilerAssemblyTest extends TestCase
{
    private function bp(array $o = []): array
    {
        return array_merge([
            'provider_prompt' => 'Create an image featuring Sarah in a modern office. Include the headline \'Level Up with Sarah!\' baked into the image.',
            'subject'         => 'Sarah, the AI growth manager',
            'composition'     => 'a professional portrait',
            'scene'           => 'modern office environment',
            'visual_hierarchy'=> 'Sarah as the focal point',
            'lighting'        => 'bright and professional',
            'mood'            => 'confident',
            'color_palette'   => ['#6C5CE7', '#00E5A8'],
            'brand_application' => 'display LevelUpGrowth\'s branding through colors',
            'negative_constraints' => [],
            'historical_or_factual_constraints' => [],
            'typography_strategy' => ['mode' => 'baked_in', 'headline' => 'Level Up with Sarah!', 'placement' => 'top', 'style' => 'Syne'],
            'dimensions'      => ['width' => 1024, 'height' => 1024],
            'quality'         => 'medium',
            '_context'        => ['has_logo' => false, 'logo_requested' => false, 'exact_text' => []],
        ], $o);
    }

    public function test_parts_ending_in_a_full_stop_never_produce_a_double_stop(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->bp());
        $this->assertStringNotContainsString('..', $out['provider_prompt']);
        $this->assertStringNotContainsString('. .', $out['provider_prompt']);
        $this->assertSame('rfc0009-v1', $out['assembly_version']);
    }

    public function test_part_order_is_the_contract_and_assembly_is_idempotent(): void
    {
        $c = new ImagePromptCompiler();
        $a = $c->compile($this->bp())['provider_prompt'];
        $b = $c->compile($this->bp())['provider_prompt'];
        $this->assertSame($a, $b, 'same blueprint must compile to byte-identical output');
        $order = ['Create an image featuring Sarah', 'Sarah, the AI growth manager', 'Composition:', 'Scene:', 'Visual hierarchy:', 'Lighting:', 'Mood:', 'Colour palette:', 'display LevelUpGrowth'];
        $last = -1;
        foreach ($order as $needle) {
            $pos = strpos($a, $needle);
            $this->assertNotFalse($pos, "missing part: $needle");
            $this->assertGreaterThan($last, $pos, "out of order: $needle");
            $last = $pos;
        }
    }

    public function test_an_invented_logo_is_removed_and_audited_when_no_logo_asset_exists(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->bp([
            'brand_application' => 'display LevelUpGrowth branding through colors and a subtle logo in the corner',
        ]));
        $this->assertStringNotContainsStringIgnoringCase('logo', preg_replace('/Avoid:.*$/', '', $out['provider_prompt']));
        $this->assertContains('logo_clause_removed', $out['flags']);
        $this->assertStringContainsString('Colour palette: #6C5CE7', $out['provider_prompt'], 'brand colours survive the guard');
    }

    public function test_a_customer_requested_logo_is_kept_and_flagged_not_stripped(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->bp([
            'brand_application' => 'place our logo in the corner as the customer asked',
            '_context' => ['has_logo' => false, 'logo_requested' => true, 'exact_text' => []],
        ]));
        $this->assertStringContainsStringIgnoringCase('logo', $out['provider_prompt']);
        $this->assertContains('logo_requested_no_logo_asset', $out['flags']);
        $this->assertNotContains('logo_clause_removed', $out['flags']);
    }

    public function test_a_real_logo_asset_is_never_touched(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->bp([
            'brand_application' => 'the brand logo sits bottom-right',
            '_context' => ['has_logo' => true, 'logo_requested' => false, 'exact_text' => []],
        ]));
        $this->assertStringContainsString('the brand logo sits bottom-right', $out['provider_prompt']);
        $this->assertSame([], $out['flags']);
    }

    public function test_unverified_claims_are_surfaced_not_rewritten(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->bp([
            'historical_or_factual_constraints' => ['UNVERIFIED: 40% revenue growth claim', 'Sarah is the Digital Marketing Manager'],
        ]));
        $this->assertContains('unverified_claims', $out['flags']);
        $this->assertSame(['UNVERIFIED: 40% revenue growth claim'], $out['unverified_claims']);
    }

    public function test_exact_customer_text_must_survive_verbatim_or_be_flagged(): void
    {
        $c = new ImagePromptCompiler();
        $kept = $c->compile($this->bp(['_context' => ['has_logo' => false, 'logo_requested' => false, 'exact_text' => ['Level Up with Sarah!']]]));
        $this->assertSame([], $kept['exact_text_missing']);
        $this->assertNotContains('exact_text_missing', $kept['flags']);

        $lost = $c->compile($this->bp(['_context' => ['has_logo' => false, 'logo_requested' => false, 'exact_text' => ['Grow Faster Today']]]));
        $this->assertSame(['Grow Faster Today'], $lost['exact_text_missing']);
        $this->assertContains('exact_text_missing', $lost['flags']);

        // separate_overlay: the copy lives in the overlay, which counts as preserved (rendering is Studio's, not the provider's)
        $ov = $c->compile($this->bp([
            'typography_strategy' => ['mode' => 'separate_overlay', 'headline' => 'Grow Faster Today', 'supporting_copy' => [], 'placement' => 'top', 'style' => ''],
            '_context' => ['has_logo' => false, 'logo_requested' => false, 'exact_text' => ['Grow Faster Today']],
        ]));
        $this->assertSame([], $ov['exact_text_missing']);
        $this->assertStringContainsString('NO text', $ov['provider_prompt']);
    }
}

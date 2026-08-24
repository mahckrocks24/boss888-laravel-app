<?php

namespace Tests\Feature\Chat\Contract;

use Tests\Feature\Chat\Support\ConformanceSuite;
use Tests\TestCase;

/**
 * P2-B — proof for the corrected clause F-06 detector.
 *
 * A conformance case may only be changed with evidence that (a) the thing it
 * flagged was not the defect, and (b) the replacement still catches the real
 * defect. This test is that evidence, and it is why the correction is reported
 * separately from any production improvement.
 *
 * THE FALSE POSITIVE
 *   Old rule:
 *     /(keyword|fallback)\w*\s*(response|reply|answer)|(response|reply|answer)\w*\s*(keyword|fallback)/i
 *   Production match, app/Engines/SEO/Services/SeoAssistantService.php:2064:
 *     private function extractKeywordFromReply(string $reply): string
 *   "Keyword" + \w*("From") + "Reply" satisfies the alternation. It matched an
 *   IDENTIFIER, and that identifier describes parsing a keyword OUT OF a genuine
 *   model reply — the opposite of inventing one. There is no fallbackReply,
 *   cannedReply or offline path anywhere in that service.
 */
class FabricationDetectorTest extends TestCase
{
    /** The exact code CR-03 removed from POST /api/studio/chat. */
    private const REMOVED_STUDIO_FALLBACK = <<<'PHP'
            if (!$ok) {
                $lower = mb_strtolower($message);
                if (strpos($lower, 'luxury') !== false) {
                    $actions[] = ['type'=>'apply_palette','vars'=>['--primary'=>'#C9943A']];
                    $reply = 'Applied a luxury gold-on-black palette.';
                } elseif (strpos($lower, 'color') !== false) {
                    $palettes = [['--primary'=>'#FF8C00'],['--primary'=>'#B983FF']];
                    $actions[] = ['type'=>'apply_palette','vars'=>$palettes[array_rand($palettes)]];
                    $reply = 'Swapped to a fresh palette — let me know if you want to adjust.';
                } else {
                    $reply = 'AI is offline right now, so I cannot rewrite copy yet.';
                }
            }
PHP;

    /** @test */
    public function the_removed_studio_fallback_would_still_be_detected(): void
    {
        // If reintroducing CR-03 did not fail, the corrected rule would be worthless.
        $hit = ConformanceSuite::detectFabrication(self::REMOVED_STUDIO_FALLBACK);

        $this->assertNotNull(
            $hit,
            'REGRESSION GUARD FAILED: the corrected F-06 detector no longer catches the exact '
            . 'fallback CR-03 removed. The correction would be hiding the defect it replaced.'
        );
    }

    /** @test */
    public function a_random_palette_choice_is_detected(): void
    {
        $this->assertNotNull(ConformanceSuite::detectFabrication(
            '$actions[] = [\'type\'=>\'apply_palette\',\'vars\'=>$palettes[array_rand($palettes)]];'
        ), 'a design action chosen at random must be detected');
    }

    /** @test */
    public function a_literal_reply_in_a_provider_failure_branch_is_detected(): void
    {
        $this->assertNotNull(ConformanceSuite::detectFabrication(<<<'PHP'
            if (!$ok) {
                $reply = 'Here is a helpful summary of your campaign performance.';
            }
PHP), 'a hard-coded reply inside a provider-failure branch must be detected');
    }

    /** @test */
    public function a_keyword_matched_canned_reply_is_detected(): void
    {
        $this->assertNotNull(ConformanceSuite::detectFabrication(
            'if (strpos($lower, "pricing") !== false) { $reply = "Our pricing starts at ninety nine dollars."; }'
        ), 'a keyword-matched hard-coded reply must be detected');
    }

    /** @test */
    public function extract_keyword_from_reply_is_not_flagged(): void
    {
        // The exact production line that caused the false positive.
        $hit = ConformanceSuite::detectFabrication(
            'private function extractKeywordFromReply(string $reply): string'
        );

        $this->assertNull($hit,
            'extractKeywordFromReply() parses a keyword out of a genuine model reply; flagging it is the false positive being corrected');
    }

    /** @test */
    public function the_real_seo_service_source_is_clean(): void
    {
        $path = base_path('app/Engines/SEO/Services/SeoAssistantService.php');
        $this->assertFileExists($path);

        $hit = ConformanceSuite::detectFabrication((string) file_get_contents($path));

        $this->assertNull($hit,
            'SeoAssistantService is reported as fabricating replies; evidence: ' . $hit);
    }

    /** @test */
    public function answering_from_the_database_is_not_fabrication(): void
    {
        // Grounded routers interpolate a real value. That is anti-hallucination,
        // and the detector must not punish it.
        $this->assertNull(ConformanceSuite::detectFabrication(
            '$routerReply = "You are tracking {$__kw} keywords."; '
        ), 'answering from a database count is grounding, not fabrication');
    }

    /** @test */
    public function the_old_rule_would_have_flagged_the_identifier_but_the_new_one_does_not(): void
    {
        $oldRule = '/(keyword|fallback)\w*\s*(response|reply|answer)|(response|reply|answer)\w*\s*(keyword|fallback)/i';
        $identifier = 'private function extractKeywordFromReply(string $reply): string';

        // Documents the defect precisely, so the correction is auditable.
        $this->assertSame(1, preg_match($oldRule, $identifier),
            'the old rule is expected to match the identifier — that is the false positive');
        $this->assertNull(ConformanceSuite::detectFabrication($identifier),
            'the corrected rule must not match it');
    }
}

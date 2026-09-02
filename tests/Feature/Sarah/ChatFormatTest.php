<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ChatFormat;
use Tests\TestCase;

/**
 * DEC-0030 — ChatFormat pinned from the real one-line reply DeepSeek produced (scratch ws 999993, 2026-09-02):
 * "Three priorities this week, in order: 1. Follow up... 2. Publish... 3. Decide..." — zero newlines.
 * The transform adds a person's line breaks without changing any words.
 */
class ChatFormatTest extends TestCase
{
    /** words are preserved exactly (only whitespace changes) */
    private function assertSameWords(string $a, string $b): void
    {
        $norm = fn ($s) => trim(preg_replace('/\s+/u', ' ', $s));
        $this->assertSame($norm($a), $norm($b), 'ChatFormat must not change any words, only whitespace');
    }

    public function test_the_real_one_line_reply_gets_a_list_on_its_own_lines(): void
    {
        $in = 'Three priorities this week, in order: 1. Follow up with Priya Fable-Lead. Her tasting was 1 September. '
            . '2. Publish what is finished. Approve the pending publish. 3. Decide on the 6 blocked tasks. They sit on the critical path.';
        $out = ChatFormat::humanize($in);

        $this->assertStringContainsString("\n1. Follow up", $out);
        $this->assertStringContainsString("\n2. Publish", $out);
        $this->assertStringContainsString("\n3. Decide", $out);
        $this->assertStringContainsString("in order:\n\n1.", $out, 'a blank line separates the lead-in from the list');
        $this->assertSameWords($in, $out);
    }

    public function test_bullets_go_on_their_own_lines(): void
    {
        $in = "Here is where things stand: - Content is behind. - SEO is healthy. - Leads need a chase.";
        $out = ChatFormat::humanize($in);
        $this->assertSame(3, substr_count($out, "\n- "));
        $this->assertSameWords($in, $out);
    }

    public function test_decimals_and_money_are_not_split(): void
    {
        $in = "The plan costs \$1.50 per page and lifts CTR 3.9 points, so 2 pages is a small spend for the gain.";
        $out = ChatFormat::humanize($in);
        $this->assertStringNotContainsString("\n1.", $out);
        $this->assertStringNotContainsString("\n2 pages", $out);
        $this->assertSameWords($in, $out);
    }

    public function test_a_long_prose_paragraph_is_broken_into_short_ones(): void
    {
        $in = "Rankings dipped this week and the cause is thin content on three pages. "
            . "The fix is to expand each to a full answer rather than a stub. "
            . "Priya can take the first two today and the third tomorrow. "
            . "None of this needs new keywords, only depth on what already ranks. "
            . "I would leave the homepage alone since it is converting fine.";
        $out = ChatFormat::humanize($in);
        $this->assertStringContainsString("\n\n", $out, 'a 5-sentence wall becomes multiple paragraphs');
        $this->assertGreaterThanOrEqual(2, substr_count($out, "\n\n"));
        $this->assertSameWords($in, $out);
    }

    public function test_short_replies_are_left_alone(): void
    {
        foreach (['You have 4 articles.', 'Your business is Harbour Dental Studio.', 'Hi Chef, what can I help with?'] as $s) {
            $this->assertSame($s, ChatFormat::humanize($s), "short reply untouched: {$s}");
        }
    }

    public function test_it_is_idempotent(): void
    {
        $in = 'Three priorities: 1. Follow up with the lead now. 2. Publish the finished draft today. 3. Clear the blocked tasks.';
        $once = ChatFormat::humanize($in);
        $this->assertSame($once, ChatFormat::humanize($once), 'running it twice changes nothing');
    }

    public function test_code_fences_are_untouched(): void
    {
        $in = "Here is the snippet:\n```\nfor (i=1. x) { a. b } 1. two\n```\nThat is it.";
        $out = ChatFormat::humanize($in);
        $this->assertStringContainsString("for (i=1. x) { a. b } 1. two", $out, 'fence content is verbatim');
    }
}

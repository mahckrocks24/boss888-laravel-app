<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\SpendPolicy;
use Tests\TestCase;

/**
 * RISK-0204 (Owner 2026-09-25, "read my convo with her just now. so frustrating"): "okay go", "publish it" and
 * "i am talking about fb page. share it asshole" were classified as 'statement — no work was requested' and the
 * Facebook posts were refused four times. A go-signal and a plain imperative are commissions.
 */
class ImperativeCommissionTest extends TestCase
{
    private function turn(string $m): array { return app(SpendPolicy::class)->assessTurn($m); }

    public function test_the_owners_exact_turns_are_commissions(): void
    {
        foreach (['okay go', 'go', 'Go.', 'yes go', 'ok, go now', 'sure, do both', 'post it', 'share it', 'i am talking about fb page. share it asshole', 'ship it please', 'run them'] as $m) {
            $t = $this->turn($m);
            $this->assertTrue($t['authorized'], "'{$m}' must authorise the proposed work: " . json_encode($t));
        }
        foreach (['publish it', 'share both articles on the facebook page', 'post the private chef article to Facebook with that caption', 'promote the new article on social', 'repost yesterday\'s article'] as $m) {
            $t = $this->turn($m);
            $this->assertTrue($t['authorized'], "'{$m}' is a work order: " . json_encode($t));
            $this->assertSame('directive', $t['classification'], $m);
        }
    }

    public function test_questions_and_statements_still_do_not_spend(): void
    {
        foreach (["what's the status of the post?", 'did you share it?', 'is the post live?', 'how did the facebook post do?'] as $m) {
            $t = $this->turn($m);
            $this->assertFalse($t['authorized'], "'{$m}' is a question: " . json_encode($t));
        }
        foreach (['Nora owns outreach', 'the post looked good', 'let me know once published', 'that was a long response don\'t you think?', 'I have not approved that'] as $m) {
            $t = $this->turn($m);
            $this->assertFalse($t['authorized'], "'{$m}' must not spend: " . json_encode($t));
        }
        $this->assertSame('question', $this->turn('the post looked good, what did it cost?')['classification']);
    }

    public function test_a_noun_use_of_post_or_share_is_not_a_command(): void
    {
        $this->assertFalse($this->turn('the post from last week got 40 likes')['authorized']);
        $this->assertFalse($this->turn('a share of the traffic comes from facebook')['authorized']);
    }
}

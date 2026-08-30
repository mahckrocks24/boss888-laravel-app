<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\SpendPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6-g (RISK-0105 CLARIFY loop, 2026-08-30): the owner's one-line answer to Sarah's "which website?" completes the
 * work they already commissioned. Without a pending question the same words stay a statement, and the pending
 * question is consumed by the answer.
 */
class ClarifyAnswerCommissionTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'clar-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'Clar', 'slug' => 'clar-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert([
            ['workspace_id' => $ws, 'name' => 'Fable QA Bakery', 'subdomain' => 'clar-a-' . uniqid(), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws, 'name' => 'Fable QA Cafe Two', 'subdomain' => 'clar-b-' . uniqid(), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
        ]);
        return $ws;
    }

    public function test_answer_naming_one_website_inherits_the_commission_and_consumes_the_question(): void
    {
        $ws = $this->ws();
        $key = SpendPolicy::pendingClarifyKey($ws);
        $sp = app(SpendPolicy::class);

        // No question pending: a bare site name is a statement (unchanged behaviour).
        Cache::forget($key);
        $plain = $sp->assessTurnInConversation('Fable QA Cafe Two please.', $ws);
        $this->assertFalse((bool) $plain['authorized']);
        $this->assertSame('statement', $plain['classification']);

        // Sarah asked which website; the answer completes the commissioned edit.
        Cache::put($key, ['action' => 'ai_builder_action', 'asked_at' => time()], now()->addMinutes(15));
        $ans = $sp->assessTurnInConversation('Fable QA Cafe Two please.', $ws);
        $this->assertTrue((bool) $ans['authorized']);
        $this->assertSame('directive', $ans['classification']);
        $this->assertTrue((bool) ($ans['clarify_answer'] ?? false));
        $this->assertNull(Cache::get($key), 'the pending question is consumed by the answer');

        // The same words again, with nothing pending, authorise nothing.
        $again = $sp->assessTurnInConversation('Fable QA Cafe Two please.', $ws);
        $this->assertFalse((bool) $again['authorized']);
    }

    public function test_answer_that_names_no_site_or_both_sites_does_not_inherit(): void
    {
        $ws = $this->ws();
        $key = SpendPolicy::pendingClarifyKey($ws);
        $sp = app(SpendPolicy::class);

        Cache::put($key, ['action' => 'ai_builder_action', 'asked_at' => time()], now()->addMinutes(15));
        $none = $sp->assessTurnInConversation('Hmm, not sure.', $ws);
        $this->assertFalse((bool) $none['authorized']);
        $this->assertNotNull(Cache::get($key), 'an answer that resolves nothing leaves the question pending');

        $both = $sp->assessTurnInConversation('Fable QA Bakery and Fable QA Cafe Two are both mine.', $ws);
        $this->assertFalse((bool) $both['authorized']);
        $this->assertNotNull(Cache::get($key));
    }
}

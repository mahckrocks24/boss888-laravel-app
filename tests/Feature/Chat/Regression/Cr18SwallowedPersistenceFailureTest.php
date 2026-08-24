<?php

namespace Tests\Feature\Chat\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CR-18 — the persist-before-meter insert swallowed its own failure.
 *
 * EXACT AUDIT DEFINITION (CHAT-RISK-REGISTER.md):
 *   "CR-18 | P3 | Failed message insert is silently swallowed |
 *    `catch (\Throwable $e) {}` in the F6 fix | A persistence failure produces
 *    no log and no signal | log it, P1 | OPEN"
 *
 * VERIFIED IN LIVE SOURCE 2026-07-26 at routes/api.php, immediately after the
 * "INCIDENT FIX 2026-07-26 — PERSIST BEFORE METERING" marker:
 *
 *   } catch (\Throwable $e) { /* table may not exist yet *\/ }
 *
 * The 2026-07-26 fix made the platform stop discarding a user's message when a
 * request was refused. This catch means that if that very insert fails, the
 * message is lost exactly as before AND nothing anywhere records it. Clause
 * O-04 prohibits it: a persistence failure must be logged with its correlation.
 *
 * The fix is to log, not to rethrow — rethrowing would turn a degraded write
 * into a hard 500 for the customer, which is worse than the defect.
 */
class Cr18SwallowedPersistenceFailureTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function the_persist_before_meter_insert_does_not_swallow_its_failure(): void
    {
        $src = \Tests\Support\RouteSource::all();

        $marker = 'PERSIST BEFORE METERING';
        $pos = strpos($src, $marker);
        $this->assertNotFalse($pos,
            'the persist-before-meter block is gone; CR-18 evidence no longer matches the source and this test must be re-derived before it is trusted');

        // Inspect only the block the audit identified.
        $block = substr($src, $pos, 1800);

        $this->assertDoesNotMatchRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$\w+\s*\)\s*\{\s*(\/\*.*?\*\/|\/\/[^\n]*)?\s*\}/s',
            $block,
            'the message-persistence insert is wrapped in an empty catch: a lost message produces no log, no metric and no signal anywhere (clause O-04)'
        );
    }

    /** @test */
    public function the_persistence_failure_path_logs_with_context(): void
    {
        $src = \Tests\Support\RouteSource::all();
        $pos = strpos($src, 'PERSIST BEFORE METERING');
        $this->assertNotFalse($pos, 'persist-before-meter block not found');
        $block = substr($src, $pos, 1800);

        $this->assertMatchesRegularExpression(
            '/Log::(error|critical|warning)\s*\(/',
            $block,
            'the persistence failure path does not log; clause O-04 requires a persistence failure to be recorded'
        );

        $this->assertMatchesRegularExpression(
            '/workspace_id|agent_slug|correlation/',
            $block,
            'the persistence failure log carries no identifying context, so the lost message cannot be traced (clause O-02/O-04)'
        );
    }

    /** @test */
    public function the_user_message_is_still_persisted_before_metering(): void
    {
        // Behavioural guard: the CR-18 fix must not disturb the P-02 behaviour
        // it sits inside. This is the direct regression test for the original
        // "my message gets deleted" incident.
        // meterChat() batches: it charges on every tenth chat and returns
        // sufficient=true for the nine in between. Zeroing the balance alone
        // therefore proves nothing — the counter must also be about to roll over.
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);
        DB::table('workspaces')->where('id', $this->testWorkspace->id)->update(['chat_meter' => 9]);

        $marker = 'cr18-persist-before-meter-' . uniqid();
        $resp = $this->withHeaders($this->authHeaders())
            ->postJson('/api/agents/sarah/messages', ['content' => $marker, 'from' => 'User']);

        $this->assertSame(402, $resp->getStatusCode(),
            'expected a credit refusal with a zero balance');

        $stored = DB::table('agent_messages')
            ->where('workspace_id', $this->testWorkspace->id)
            ->where('content', $marker)
            ->count();

        $this->assertSame(1, $stored,
            "the user's message was discarded when the request was refused — this is the exact 2026-07-26 incident (clause P-02)");
    }

    /** @test */
    public function a_refused_message_is_recoverable_on_reload(): void
    {
        // meterChat() batches: it charges on every tenth chat and returns
        // sufficient=true for the nine in between. Zeroing the balance alone
        // therefore proves nothing — the counter must also be about to roll over.
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);
        DB::table('workspaces')->where('id', $this->testWorkspace->id)->update(['chat_meter' => 9]);

        $marker = 'cr18-reload-' . uniqid();
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/agents/sarah/messages', ['content' => $marker, 'from' => 'User']);

        // A reload is a fresh history fetch. The customer must see what they typed.
        $history = $this->withHeaders($this->authHeaders())
            ->getJson('/api/agents/sarah/messages')->json();

        $this->assertStringContainsString($marker, json_encode($history) ?: '',
            'a refused message does not survive a reload; the customer sees their words vanish (clause P-02/P-04)');
    }
}

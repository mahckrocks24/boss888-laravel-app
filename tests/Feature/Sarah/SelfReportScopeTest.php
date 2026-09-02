<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\SelfReportGuard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2 #3 self-report-as-answer (2026-09-02): SelfReportGuard's denial-rewrite REPLACES the reply with the
 * ledger summary. That must happen ONLY when the user asked for task status — on a normal action/strategy
 * request a degraded "nothing changed" reply must keep its answer, never be swapped for unrelated workspace
 * task stats. (A real ledger entry is created so the guard is genuinely active, not a no-op.)
 */
class SelfReportScopeTest extends TestCase
{
    private int $ws = 0;
    private int $taskId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = (int) (DB::table('workspaces')->value('id') ?? 0);
        if ($this->ws > 0) {
            $this->taskId = (int) DB::table('tasks')->insertGetId([
                'workspace_id' => $this->ws,
                'engine'       => 'write',
                'action'       => 'write_article',
                'status'       => 'completed',
                'source'       => 'agent',
                'payload_json' => json_encode(['created_via' => 'sarah_chat']),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->taskId) { DB::table('tasks')->where('id', $this->taskId)->delete(); }
        parent::tearDown();
    }

    public function test_action_request_answer_is_not_replaced_by_a_summary(): void
    {
        if ($this->ws === 0) { $this->markTestSkipped('no workspace in test DB'); }
        $r = app(SelfReportGuard::class)->validate('Nothing has changed.', $this->ws, null, 'Change my homepage headline to Fresh Bakes.');
        $this->assertFalse($r['rewritten'], 'a non-status request must not have its answer rewritten into a task summary');
        $this->assertStringNotContainsString('To be exact', $r['reply']);
    }

    public function test_strategy_request_answer_is_not_replaced(): void
    {
        if ($this->ws === 0) { $this->markTestSkipped('no workspace in test DB'); }
        $r = app(SelfReportGuard::class)->validate('No changes were made.', $this->ws, null, 'What should I focus on this week?');
        $this->assertFalse($r['rewritten']);
    }

    public function test_status_question_still_gets_the_correction(): void
    {
        if ($this->ws === 0) { $this->markTestSkipped('no workspace in test DB'); }
        // The guard remains active for its real purpose: a genuine "what did you change" question.
        $r = app(SelfReportGuard::class)->validate('Nothing has changed.', $this->ws, null, 'What did you change today?');
        $this->assertTrue($r['rewritten'], 'a status question should still correct a false denial with the ledger');
        $this->assertStringContainsString('To be exact', $r['reply']);
    }
}

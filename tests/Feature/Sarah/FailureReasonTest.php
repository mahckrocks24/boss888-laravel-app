<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ActionLedger;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** REASON-1 (2026-08-31). 217 of 599 failed tasks carried an empty `error_text` while the real reason sat in
 *  `progress_message` — 108 entitlement refusals ("AI features require AI Lite plan or above") and 101 from the
 *  RISK-0041 parent sweep. Sarah's ledger read only `error_text`, so she saw failures with no cause and filled the
 *  gap by inventing one. A failure must state why, or say plainly that no reason was recorded. */
class FailureReasonTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'fr-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'FR', 'slug' => 'fr-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    private function failedTask(int $ws, ?string $errorText, ?string $progress): int
    {
        return (int) DB::table('tasks')->insertGetId([
            'workspace_id' => $ws, 'engine' => 'write', 'action' => 'write_article', 'status' => 'failed',
            'requires_approval' => 0, 'approval_status' => 'approved', 'credit_cost' => 0,
            'payload_json' => json_encode(['title' => 'X']), 'source' => 'agent',
            'error_text' => $errorText, 'progress_message' => $progress,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reasonFor(int $ws, int $taskId): ?string
    {
        $ledger = app(ActionLedger::class)->forWorkspace($ws);
        $flat = json_encode($ledger);
        $this->assertIsString($flat);
        foreach (['failed', 'done', 'pending', 'items', 'recent'] as $k) { /* shape-agnostic */ }
        preg_match_all('/"id":' . $taskId . ',.*?"error":("[^"]*"|null)/s', $flat, $m);
        return $m[1][0] ?? null;
    }

    public function test_an_entitlement_refusal_is_reported_instead_of_a_blank(): void
    {
        $ws = $this->ws();
        $id = $this->failedTask($ws, null, 'AI features require AI Lite plan or above');

        $reason = $this->reasonFor($ws, $id);
        $this->assertNotNull($reason, 'the failed task must appear in the ledger');
        $this->assertStringContainsString('AI Lite', (string) $reason, 'the reason exists in progress_message and must be surfaced');
    }

    public function test_an_explicit_error_still_wins(): void
    {
        $ws = $this->ws();
        $id = $this->failedTask($ws, 'runtime_deadline', 'some progress note');

        $this->assertStringContainsString('runtime_deadline', (string) $this->reasonFor($ws, $id));
    }

    public function test_a_failure_with_nothing_recorded_says_so_rather_than_showing_blank(): void
    {
        $ws = $this->ws();
        $id = $this->failedTask($ws, null, null);

        $this->assertStringContainsString('no reason was recorded', (string) $this->reasonFor($ws, $id));
    }
}

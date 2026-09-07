<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\AgentMeetingEngine;
use App\Core\Orchestration\ProactiveStrategyEngine;
use App\Core\Sarah888\SessionLedgerFacts as F;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RISK-0143 (2026-09-07, DEC-0042): Sarah's read-back of a strategy session and its cost comes from the ledger and the
 *  meeting/proposal rows, in that order; the plan tasks a session produced can never make her deny the completed
 *  session or its charge; a pending session is never called done; a genuine conflict is RECONCILING, not a guess. */
class SessionLedgerFactsTest extends TestCase
{
    private function tenant(int $balance = 50): array
    {
        if (! DB::table('agents')->where('slug', 'sarah')->exists()) {
            DB::table('agents')->insert(['slug' => 'sarah', 'name' => 'Sarah', 'role' => 'Digital Marketing Manager', 'created_at' => now(), 'updated_at' => now()]);
        }
        $u = (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'r143-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'R143 Bakery', 'slug' => 'r143-' . uniqid(), 'created_by' => $u, 'business_name' => 'R143 Bakery', 'industry' => 'Bakery', 'location' => 'Manchester', 'onboarded' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insertOrIgnore(['user_id' => $u, 'workspace_id' => $ws, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => $ws, 'balance' => $balance, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return [$u, $ws];
    }

    private function proposal(int $ws, int $credits = 8): int
    {
        return (int) DB::table('strategy_proposals')->insertGetId(['workspace_id' => $ws, 'type' => 'initial_strategy', 'title' => 'Initial Marketing Strategy Session', 'description' => 'Strategy meeting with your AI marketing team', 'status' => 'pending_approval', 'cost_breakdown_json' => json_encode([['agent' => 'sarah', 'credits' => $credits]]), 'total_credits' => $credits, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Approve (reserve 8, start the meeting) and, when asked, complete it the way the engine does (commit once). */
    private function strategySession(int $ws, int $u, bool $complete): array
    {
        $pid = $this->proposal($ws, 8);
        $res = app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid);
        $this->assertTrue((bool) ($res['success'] ?? false), json_encode($res));
        $mid = (int) $res['meeting_id'];
        if ($complete) {
            $meeting = DB::table('meetings')->where('id', $mid)->first();
            $meta = json_decode($meeting->metadata_json, true);
            $meta['rounds_completed'] = (new \ReflectionClassConstant(AgentMeetingEngine::class, 'MAX_MEETING_ROUNDS'))->getValue();
            DB::table('meetings')->where('id', $mid)->update(['metadata_json' => json_encode($meta)]);
            app(AgentMeetingEngine::class)->advanceMeeting($mid);
        }
        return [$pid, $mid];
    }

    private function planTasks(int $ws, int $mid, int $n, int $costEach): void
    {
        for ($i = 0; $i < $n; $i++) {
            $tid = (int) DB::table('tasks')->insertGetId(['workspace_id' => $ws, 'engine' => 'seo', 'action' => 'deep_audit', 'status' => 'pending', 'requires_approval' => 0, 'credit_cost' => $costEach, 'source' => 'agent', 'payload_json' => json_encode(['from_meeting' => $mid]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('meeting_tasks')->insert(['meeting_id' => $mid, 'task_id' => $tid, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // A. completed session: ledger commit + closed meeting → COMPLETED_CHARGED, exactly once
    public function test_a_completed_session_reads_completed_and_charged_once_from_the_ledger(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, true);
        $s = F::sessions($ws)[0];
        $this->assertSame('COMPLETED_CHARGED', $s['state']); $this->assertSame($pid, $s['proposal_id']); $this->assertSame($mid, $s['meeting_id']);
        $this->assertSame(8, $s['committed']); $this->assertSame(1, $s['commit_count']); $this->assertSame(0, $s['outstanding']); $this->assertNotNull($s['closed_at']); $this->assertNotNull($s['charged_at']);
        $block = F::render($ws, 'Did the strategy session complete, and what has it cost me so far?');
        $this->assertStringContainsString('COMPLETED - meeting #' . $mid, $block);
        $this->assertStringContainsString('8 credits deducted exactly once', $block);
        $this->assertStringContainsString('deducted so far 8 credits in total', $block);
        $this->assertStringContainsString('balance 42 credits, 0 reserved', $block);
        $this->assertSame('', F::render($ws, 'hello there'), 'nothing rendered for a turn that is not about sessions or money');
    }

    // B. pending session: reserved, meeting not completed → IN_PROGRESS, nothing charged
    public function test_a_pending_session_reads_in_progress_with_the_reservation_and_no_charge(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, false);
        $s = F::sessions($ws)[0];
        $this->assertSame('IN_PROGRESS', $s['state']); $this->assertSame(8, $s['reserved']); $this->assertSame(0, $s['committed']); $this->assertSame(8, $s['outstanding']);
        $block = F::render($ws, 'has the session finished and what did it cost?');
        $this->assertStringContainsString('IN PROGRESS, not completed', $block);
        $this->assertStringContainsString('8 credits reserved (held), nothing deducted for it yet', $block);
        $this->assertStringContainsString('deducted so far 0 credits', $block);
        $this->assertStringContainsString('8 reserved (held, not deducted)', $block);
    }

    // C. duplicate completion: a second completion does not charge again; the record still says one charge
    public function test_a_duplicate_completion_leaves_one_session_and_one_charge(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, true);
        app(AgentMeetingEngine::class)->advanceMeeting($mid);
        app(AgentMeetingEngine::class)->advanceMeeting($mid);
        $s = F::sessions($ws)[0];
        $this->assertSame('COMPLETED_CHARGED', $s['state']); $this->assertSame(8, $s['committed']); $this->assertSame(1, $s['commit_count']);
        $this->assertCount(1, F::sessions($ws));
        $this->assertSame(8, F::spend($ws)['charged_total']); $this->assertSame(42, F::spend($ws)['balance']);
    }

    // D. two tenants: no cross-tenant meeting or ledger facts
    public function test_two_tenants_never_share_session_or_ledger_facts(): void
    {
        [$u1, $ws1] = $this->tenant(50); [$p1, $m1] = $this->strategySession($ws1, $u1, true);
        [$u2, $ws2] = $this->tenant(50); [$p2, $m2] = $this->strategySession($ws2, $u2, false);
        $one = F::sessions($ws1); $two = F::sessions($ws2);
        $this->assertCount(1, $one); $this->assertCount(1, $two);
        $this->assertSame($p1, $one[0]['proposal_id']); $this->assertSame($p2, $two[0]['proposal_id']);
        $this->assertSame('COMPLETED_CHARGED', $one[0]['state']); $this->assertSame('IN_PROGRESS', $two[0]['state']);
        $this->assertSame(8, F::spend($ws1)['charged_total']); $this->assertSame(0, F::spend($ws2)['charged_total']);
        $this->assertStringNotContainsString('#' . $p2, F::render($ws1, 'what did the session cost?'));
        $this->assertStringNotContainsString('#' . $p1, F::render($ws2, 'what did the session cost?'));
        [$u3, $ws3] = $this->tenant(50);
        $this->assertSame('', F::render($ws3, 'what did the session cost?'), 'a workspace with no session and no charges renders nothing');
    }

    // E. the plan tasks a completed session produced never erase the completed, charged session
    public function test_pending_plan_tasks_do_not_override_the_completed_and_charged_session(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, true);
        $this->planTasks($ws, $mid, 5, 2);   // five pending tasks worth 10 credits, like the ones EV-0922 saw
        $s = F::sessions($ws)[0];
        $this->assertSame('COMPLETED_CHARGED', $s['state']); $this->assertSame(8, $s['committed']);
        $this->assertSame(5, $s['plan_tasks']); $this->assertSame(5, $s['plan_pending']); $this->assertSame(10, $s['plan_pending_credits']);
        $line = F::sentence($s);
        $this->assertStringContainsString('COMPLETED', $line);
        $this->assertStringContainsString('5 plan tasks (5 still pending, worth 10 credits NOT yet deducted - separate items', $line);
        $this->assertSame(8, F::spend($ws)['charged_total'], 'pending task costs are not spend');
    }

    // the guard: a completed, charged session is never denied
    public function test_guard_corrects_a_reply_that_denies_the_completed_session_or_its_charge(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, true);
        $bad = "Honestly, there's no completed strategy session on record yet for me to summarise — the 8 credits you approved map to five tasks that are still pending and haven't run. Your actual spend so far is 1 credit. The 8 credits won't be charged until the tasks actually execute.";
        $out = F::guard($bad, $ws);
        $this->assertStringContainsString(F::NOTE, $out);
        $this->assertStringContainsString("COMPLETED - meeting #{$mid}", $out);
        $this->assertStringContainsString('8 credits deducted exactly once', $out);
        $this->assertStringContainsString('Spend to date (ledger): 8 credits; balance 42 credits, 0 reserved.', $out);
        $good = "Yes — the strategy session completed and 8 credits were charged for it, once. Your balance is 42 credits.";
        $this->assertSame($good, F::guard($good, $ws), 'a truthful reply is left alone');
        $this->assertSame('Morning! What shall we work on?', F::guard('Morning! What shall we work on?', $ws), 'a reply that is not about sessions or money is left alone');
    }

    // the guard: a running session is never called done or charged
    public function test_guard_corrects_a_reply_that_calls_a_running_session_complete_or_charged(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, false);
        $bad = "The strategy session completed this morning and I charged you 8 credits for it.";
        $out = F::guard($bad, $ws);
        $this->assertStringContainsString(F::NOTE, $out);
        $this->assertStringContainsString('IN PROGRESS, not completed', $out);
        $this->assertStringContainsString('nothing deducted for it yet', $out);
        $good = "The session is still running — 8 credits are reserved for it and nothing has been charged yet.";
        $this->assertSame($good, F::guard($good, $ws));
    }

    // conflict: meeting closed, no ledger commit → RECONCILING, and the guard refuses both resolutions
    public function test_a_genuine_conflict_is_reconciling_not_guessed(): void
    {
        [$u, $ws] = $this->tenant(50);
        $pid = $this->proposal($ws, 8);
        $ref = 'res_test_' . uniqid();
        $mid = (int) DB::table('meetings')->insertGetId(['workspace_id' => $ws, 'title' => 'Strategy', 'type' => 'strategy', 'status' => 'closed', 'total_credits_used' => 8, 'metadata_json' => json_encode(['phase' => 'complete', 'credit_cost' => 8, 'reservation_ref' => $ref]), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('strategy_proposals')->where('id', $pid)->update(['status' => 'executing', 'approved_at' => now(), 'reservation_ref' => $ref, 'meeting_id' => $mid]);
        DB::table('credit_transactions')->insert(['workspace_id' => $ws, 'type' => 'reserve', 'amount' => 8, 'reference_type' => "proposal:$pid", 'reservation_status' => 'pending', 'reservation_reference' => $ref, 'created_at' => now()]);
        $s = F::sessions($ws)[0];
        $this->assertSame('RECONCILING', $s['state']);
        $this->assertStringContainsString('RECONCILING', F::render($ws, 'did the session run and what did it cost?'));
        $this->assertStringContainsString(F::NOTE, F::guard('The session completed and you were charged 8 credits.', $ws));
        $this->assertStringContainsString(F::NOTE, F::guard("There's no completed strategy session on record and nothing has been charged.", $ws));
        $this->assertStringContainsString('being reconciled', F::guard('The session completed and you were charged 8 credits.', $ws));
    }

    public function test_relevance_gate(): void
    {
        $this->assertTrue(F::relevant('What did the team decide in the strategy session, and exactly what has it cost me so far?'));
        $this->assertTrue(F::relevant('what is pending right now?'));
        $this->assertFalse(F::relevant('change the hero headline to Fresh Every Morning'));
    }

    // the guard must not read a negated phrase as a claim: "hasn't completed" on a running session is the truth
    public function test_guard_leaves_a_truthful_negated_answer_about_a_running_session_alone(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, false);
        $truth = "The strategy session is still in progress and hasn't completed yet. It's currently reserved at 8 credits, but nothing has been charged to you yet. So far, you've spent 1 credit for the article task.";
        $this->assertSame($truth, F::guard($truth, $ws));
    }

    // second live run (EV-0923): a correct answer that says the PLAN TASKS are not deducted yet must not be "corrected"
    public function test_guard_does_not_correct_a_true_statement_that_plan_tasks_are_not_yet_deducted(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, true);
        $this->planTasks($ws, $mid, 2, 2);
        $good = "Yes — the strategy session completed. The meeting closed at 17:03 UTC today and the 8 credits were deducted exactly once; nothing further is owed for it. The 4 credits for the two plan tasks it produced are separate and have not been deducted yet. Your total spend is 8 credits.";
        $this->assertSame($good, F::guard($good, $ws));
        $bad = "The session ran, but the 8 credits have not been deducted yet — they only get charged once the tasks run.";
        $this->assertStringContainsString(F::NOTE, F::guard($bad, $ws), 'a denial about the SESSION charge is still corrected');
    }

    // fourth live run (EV-0923): two truthful replies drew a redundant correction — "none of its 8 credits have been deducted"
    // on a running session, and "their 6 credits won't be deducted until you approve" about the plan tasks of a completed one
    public function test_guard_leaves_the_fourth_run_phrasings_alone(): void
    {
        [$u, $ws] = $this->tenant(50); [$pid, $mid] = $this->strategySession($ws, $u, false);
        $b = "Not yet — the strategy session is still in progress (meeting at the opening phase), so none of its 8 credits have been deducted; they're held and will be charged automatically once the meeting completes. You have spent nothing so far. Balance is 50 credits with 8 of those held.";
        $this->assertSame($b, F::guard($b, $ws));
        [$u2, $ws2] = $this->tenant(50); [$pid2, $mid2] = $this->strategySession($ws2, $u2, true);
        $this->planTasks($ws2, $mid2, 5, 1);
        $e = "The strategy session has definitely run and been charged — meeting #{$mid2} closed at 17:26 UTC and its 8 credits were deducted exactly once, so it is not waiting on anything. What's pending in your queue is separate: 5 items awaiting your approval (create lead, schedule social post, build website, write article, deep audit), which came out of that session and their 5 credits won't be deducted until you approve and they run.";
        $this->assertSame($e, F::guard($e, $ws2));
        $stillBad = "The session ran but its 8 credits won't be deducted until the tasks run.";
        $this->assertStringContainsString(F::NOTE, F::guard($stillBad, $ws2));
    }

    // downstream guards (first live run, EV-0923): MeasurementGuard read "session" as a GA metric and cut every sentence
    // carrying a credit figure, a meeting id or a time; ArticleIdClaimGuard read "task #32255", "1 credit" and "balance 41"
    // as foreign article ids. Ledger facts are not analytics metrics and not article numbers.
    public function test_measurement_guard_keeps_ledger_and_session_sentences(): void
    {
        [$u, $ws] = $this->tenant(50);
        $reply = "Yes — the session completed. Meeting #24 closed at 16:42 UTC today and you were charged exactly 8 credits, once. Current balance is 41 credits with 0 reserved. Your organic traffic is up 23% this month.";
        $out = app(\App\Core\Sarah888\MeasurementGuard::class)->sanitize($reply, $ws);
        $this->assertStringContainsString('Meeting #24 closed at 16:42 UTC today and you were charged exactly 8 credits, once.', $out['reply']);
        $this->assertStringContainsString('Current balance is 41 credits with 0 reserved.', $out['reply']);
        $this->assertStringNotContainsString('organic traffic is up 23%', $out['reply'], 'a real analytics fabrication is still stripped');
    }

    public function test_article_id_guard_ignores_task_ids_credit_amounts_and_balances(): void
    {
        [$u, $ws] = $this->tenant(50);
        $reply = "Credit charges (newest first): 1 credit — write_article (task #32255), 16:40 UTC. So far, you've spent 1 credit for the article task. Total charged: 9 credits; balance 41 credits, 0 reserved.";
        $out = app(\App\Core\Sarah888\ArticleIdClaimGuard::class)->validate($reply, $ws);
        $this->assertFalse($out['corrected']); $this->assertSame($reply, $out['reply']);
        $bad = "The available drafts are articles 183, 184 and 185.";
        $this->assertTrue(app(\App\Core\Sarah888\ArticleIdClaimGuard::class)->validate($bad, $ws)['corrected'], 'an invented article number is still corrected');
    }
}

<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\AgentMeetingEngine;
use App\Core\Orchestration\ProactiveStrategyEngine;
use App\Core\Sarah888\AuthorizationBinder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RISK-0142 (a)+(c) (2026-09-07, DEC-0040). Approving the onboarding strategy proposal reserves its credits ONCE,
 *  starts the meeting on that reservation (no second "meeting_strategy" hold), commits exactly once on completion;
 *  duplicate approval, decline, insufficient credits are named no-ops; and Sarah's chat binds a "yes" that names the
 *  session to the proposal so the owner is not sent to the Strategy Room merely to approve. */
class ProposalChargeOnCompletionAndChatApprovalTest extends TestCase
{
    private function tenant(int $balance = 50): array
    {
        if (! DB::table('agents')->where('slug', 'sarah')->exists()) {
            DB::table('agents')->insert(['slug' => 'sarah', 'name' => 'Sarah', 'role' => 'Digital Marketing Manager', 'created_at' => now(), 'updated_at' => now()]);
        }
        $u = (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'p1-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'P1 Bakery', 'slug' => 'p1-' . uniqid(), 'created_by' => $u, 'business_name' => 'P1 Bakery', 'industry' => 'Bakery', 'location' => 'Manchester', 'onboarded' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insertOrIgnore(['user_id' => $u, 'workspace_id' => $ws, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => $ws, 'balance' => $balance, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return [$u, $ws];
    }

    private function proposal(int $ws, int $credits = 8): int
    {
        return (int) DB::table('strategy_proposals')->insertGetId(['workspace_id' => $ws, 'type' => 'initial_strategy', 'title' => 'Initial Marketing Strategy Session', 'description' => 'Strategy meeting with your AI marketing team to create a comprehensive plan for P1 Bakery', 'status' => 'pending_approval', 'cost_breakdown_json' => json_encode([['agent' => 'sarah', 'credits' => $credits]]), 'total_credits' => $credits, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ledger(int $ws): array
    {
        return DB::table('credit_transactions')->where('workspace_id', $ws)->orderBy('id')->get(['type', 'amount', 'reference_type', 'reservation_status', 'reservation_reference'])->map(fn($r) => (array) $r)->all();
    }

    public function test_approval_reserves_once_starts_the_meeting_on_that_reservation_and_commits_once_on_completion(): void
    {
        [$u, $ws] = $this->tenant(50);
        $pid = $this->proposal($ws, 8);
        $res = app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid);
        $this->assertTrue((bool) ($res['success'] ?? false), json_encode($res));
        $meetingId = (int) ($res['meeting_id'] ?? 0); $this->assertGreaterThan(0, $meetingId);

        $rows = $this->ledger($ws);
        $reserves = array_values(array_filter($rows, fn($r) => $r['type'] === 'reserve'));
        $this->assertCount(1, $reserves, 'exactly ONE reservation for the approved proposal');
        $this->assertSame(8, (int) $reserves[0]['amount']);
        $this->assertSame('pending', $reserves[0]['reservation_status'], 'reserved, not charged, at approval');
        $this->assertSame("proposal:$pid", $reserves[0]['reference_type']);
        $this->assertCount(0, array_filter($rows, fn($r) => $r['reference_type'] === 'meeting_strategy'), 'no second meeting_strategy hold');
        $this->assertCount(0, array_filter($rows, fn($r) => $r['type'] === 'commit'), 'nothing charged before the session ran');
        $credit = DB::table('credits')->where('workspace_id', $ws)->first();
        $this->assertSame(50, (int) $credit->balance); $this->assertSame(8, (int) $credit->reserved_balance);

        $meeting = DB::table('meetings')->where('id', $meetingId)->first();
        $meta = json_decode($meeting->metadata_json, true);
        $this->assertSame($reserves[0]['reservation_reference'], $meta['reservation_ref'], 'the meeting carries the proposal reservation');
        $this->assertSame(8, (int) $meta['credit_cost']);
        $this->assertSame('executing', DB::table('strategy_proposals')->where('id', $pid)->value('status'));

        // Completion: force the round limit so advanceMeeting completes without any further agent turn.
        $max = (new \ReflectionClassConstant(AgentMeetingEngine::class, 'MAX_MEETING_ROUNDS'))->getValue();
        $meta['rounds_completed'] = $max;
        DB::table('meetings')->where('id', $meetingId)->update(['metadata_json' => json_encode($meta)]);
        app(AgentMeetingEngine::class)->advanceMeeting($meetingId);
        app(AgentMeetingEngine::class)->advanceMeeting($meetingId);   // a second completion must not charge again

        $rows = $this->ledger($ws);
        $commits = array_values(array_filter($rows, fn($r) => $r['type'] === 'commit'));
        $this->assertCount(1, $commits, 'charged exactly once, on completion');
        $this->assertSame(8, (int) $commits[0]['amount']);
        $credit = DB::table('credits')->where('workspace_id', $ws)->first();
        $this->assertSame(42, (int) $credit->balance); $this->assertSame(0, (int) $credit->reserved_balance);
        $this->assertSame('closed', DB::table('meetings')->where('id', $meetingId)->value('status'));
    }

    public function test_duplicate_approval_is_a_named_no_op(): void
    {
        [$u, $ws] = $this->tenant(50); $pid = $this->proposal($ws, 8);
        $this->assertTrue((bool) app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid)['success']);
        $before = count($this->ledger($ws));
        $again = app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid);
        $this->assertFalse((bool) $again['success']); $this->assertSame('ALREADY_PROCESSED', $again['code']);
        $this->assertCount($before, $this->ledger($ws), 'no new ledger rows on a duplicate approval');
    }

    public function test_decline_charges_nothing_and_cannot_undo_an_approval(): void
    {
        [$u, $ws] = $this->tenant(50); $pid = $this->proposal($ws, 8);
        $d = app(ProactiveStrategyEngine::class)->declineProposal($ws, $pid);
        $this->assertTrue((bool) $d['success']); $this->assertSame('declined', DB::table('strategy_proposals')->where('id', $pid)->value('status'));
        $this->assertCount(0, $this->ledger($ws));
        $this->assertSame('ALREADY_PROCESSED', app(ProactiveStrategyEngine::class)->declineProposal($ws, $pid)['code']);
        $pid2 = $this->proposal($ws, 8);
        app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid2);
        $this->assertSame('ALREADY_PROCESSED', app(ProactiveStrategyEngine::class)->declineProposal($ws, $pid2)['code']);
        $this->assertSame('executing', DB::table('strategy_proposals')->where('id', $pid2)->value('status'));
    }

    public function test_insufficient_credits_refuses_before_any_reservation(): void
    {
        [$u, $ws] = $this->tenant(3); $pid = $this->proposal($ws, 8);
        $res = app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid);
        $this->assertFalse((bool) $res['success']); $this->assertSame('NO_CREDITS', $res['code']);
        $this->assertSame('insufficient_credits', DB::table('strategy_proposals')->where('id', $pid)->value('status'));
        $this->assertCount(0, $this->ledger($ws)); $this->assertCount(0, DB::table('meetings')->where('workspace_id', $ws)->get());
    }

    public function test_chat_binds_a_yes_that_names_the_session_and_not_a_bare_ok(): void
    {
        [$u, $ws] = $this->tenant(50); $pid = $this->proposal($ws, 8);
        $b = app(AuthorizationBinder::class);
        $bare = $b->bind($ws, "ws{$ws}:sarah", 'ok', $u);
        $this->assertNotSame(AuthorizationBinder::AUTHORIZED, $bare['outcome'], 'a bare "ok" must not spend 8 credits');
        $work = $b->bind($ws, "ws{$ws}:sarah", 'Please write a blog article for our website about sourdough, around 800 words, and save it as a draft. Go ahead now, you have my go-ahead.', $u);
        $this->assertNotSame(AuthorizationBinder::AUTHORIZED, $work['outcome'], 'a go-ahead inside a NEW work request must not approve the proposal (regression R4)');
        $this->assertSame('pending_approval', DB::table('strategy_proposals')->where('id', $pid)->value('status'));
        $auth = $b->bind($ws, "ws{$ws}:sarah", 'I authorise it, go ahead and run it.', $u);
        $this->assertNotSame(AuthorizationBinder::AUTHORIZED, $auth['outcome'], 'an authorisation that does not name the session does not bind it');
        $yes = $b->bind($ws, "ws{$ws}:sarah", 'Yes, please go ahead with the strategy session. I approve the 8 credits.', $u);
        $this->assertSame(AuthorizationBinder::AUTHORIZED, $yes['outcome'], json_encode($yes));
        $this->assertSame($pid, (int) $yes['proposal']->id);
        $this->assertSame(8, (int) $yes['proposal']->total_credits);
    }

    public function test_chat_withdrawal_declines_the_pending_proposal_and_an_approved_one_is_no_longer_bindable(): void
    {
        [$u, $ws] = $this->tenant(50); $pid = $this->proposal($ws, 8);
        $b = app(AuthorizationBinder::class);
        $no = $b->bind($ws, "ws{$ws}:sarah", "No, don't run the strategy session.", $u);
        $this->assertSame(AuthorizationBinder::CANCELLED, $no['outcome'], json_encode($no));
        $this->assertSame('declined', DB::table('strategy_proposals')->where('id', $pid)->value('status'));
        $this->assertCount(0, $this->ledger($ws));
        $pid2 = $this->proposal($ws, 8);
        app(ProactiveStrategyEngine::class)->approveProposal($ws, $u, $pid2);
        $again = $b->bind($ws, "ws{$ws}:sarah", 'Yes, go ahead with the strategy session.', $u);
        $this->assertNotSame(AuthorizationBinder::AUTHORIZED, $again['outcome'], 'an executing proposal is not approved twice from chat');
    }
}

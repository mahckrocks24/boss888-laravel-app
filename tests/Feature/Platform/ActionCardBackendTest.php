<?php

namespace Tests\Feature\Platform;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Chat\ActionCardExecutor;
use App\Core\Engineer888\Chat\ActionCardService;
use App\Core\Engineer888\Chat\CardIssuanceService;
use App\Core\Engineer888\Chat\ChatOwner;
use App\Core\Engineer888\Chat\ConversationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The Action Card backend.
 *
 * FIXTURES, NOT PRODUCTION. Every workflow record here is built inside the
 * isolated E3 test database. Nothing touches a real candidate, and no card in
 * production is pressed to satisfy a test.
 *
 * The claims that matter are the refusals. A card that approves the right bytes
 * is ordinary; a card that still approves after the bytes changed is the defect
 * the whole binding mechanism exists to prevent, so most of this file is about
 * making cards fail.
 */
class ActionCardBackendTest extends TestCase
{
    use RefreshDatabase;

    private object $conversation;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->seedCanonical();
        $this->conversation = app(ConversationService::class)->forOwner($this->req());
        $this->projectId = (int) DB::table('engineering_projects')->insertGetId([
            'company' => 'LevelUp Growth', 'key' => 'fixture-project', 'name' => 'Fixture Project',
            'repository_path' => '/tmp/fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── issuance ─────────────────────────────────────────────────────

    public function test_an_awaiting_review_candidate_issues_approve_and_reject_cards(): void
    {
        $this->candidate();

        $cards = $this->issuer()->reconcile($this->req(), $this->conversation);
        $types = collect($cards)->pluck('action_type')->sort()->values()->all();

        $this->assertContains(ActionCardService::APPROVE_CANDIDATE, $types);
        $this->assertContains(ActionCardService::REJECT_CANDIDATE, $types);
    }

    public function test_no_live_candidate_issues_no_card(): void
    {
        $this->assertSame([], $this->issuer()->reconcile($this->req(), $this->conversation));
    }

    public function test_a_superseded_candidate_issues_nothing(): void
    {
        $c = $this->candidate();
        DB::table('engineering_candidates')->where('id', $c->id)->update(['superseded_at' => now()]);

        $this->assertSame([], $this->issuer()->reconcile($this->req(), $this->conversation));
    }

    public function test_a_completed_task_issues_nothing(): void
    {
        $c = $this->candidate();
        DB::table('engineering_tasks')->where('id', $c->task_id)->update(['status' => 'completed']);

        $this->assertSame([], $this->issuer()->reconcile($this->req(), $this->conversation));
    }

    public function test_an_approved_candidate_issues_an_execute_card(): void
    {
        $c = $this->candidate();
        $this->approve($c);

        $types = collect($this->issuer()->reconcile($this->req(), $this->conversation))
            ->pluck('action_type')->all();

        $this->assertContains(ActionCardService::EXECUTE_TASK, $types);
        $this->assertContains(ActionCardService::REVOKE_APPROVAL, $types);
        $this->assertNotContains(ActionCardService::APPROVE_CANDIDATE, $types,
            'a decided candidate must not still offer approval');
    }

    public function test_a_blocked_recovery_issues_a_recovery_card(): void
    {
        $c = $this->candidate();
        DB::table('engineering_recoveries')->insert([
            'task_uuid' => $this->taskUuid($c), 'candidate_uuid' => $c->uuid,
            'fingerprint' => 'recovery-fp', 'status' => 'FAILED_RECOVERY_BLOCKED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $types = collect($this->issuer()->reconcile($this->req(), $this->conversation))
            ->pluck('action_type')->all();

        $this->assertContains(ActionCardService::APPROVE_RECOVERY, $types);
    }

    public function test_a_migration_without_a_passed_rehearsal_issues_no_migration_card(): void
    {
        $c = $this->candidate();
        $this->rehearsal($c, 'FAILED');

        $types = collect($this->issuer()->reconcile($this->req(), $this->conversation))
            ->pluck('action_type')->all();

        $this->assertNotContains(ActionCardService::APPROVE_MIGRATION, $types,
            'a migration must never be approvable without a PASSED rehearsal');
    }

    public function test_a_passed_rehearsal_issues_a_migration_card(): void
    {
        $c = $this->candidate();
        $this->rehearsal($c, 'PASSED');

        $types = collect($this->issuer()->reconcile($this->req(), $this->conversation))
            ->pluck('action_type')->all();

        $this->assertContains(ActionCardService::APPROVE_MIGRATION, $types);
    }

    // ── idempotency and supersession ─────────────────────────────────

    public function test_repeated_reconciliation_never_duplicates_a_card(): void
    {
        $this->candidate();

        $first = $this->issuer()->reconcile($this->req(), $this->conversation);
        for ($i = 0; $i < 5; $i++) {
            $again = $this->issuer()->reconcile($this->req(), $this->conversation);
        }

        $this->assertCount(count($first), $again);
        $this->assertSame(
            collect($first)->pluck('uuid')->sort()->values()->all(),
            collect($again)->pluck('uuid')->sort()->values()->all(),
            'polling must return the same cards, not new ones'
        );
        $this->assertSame(count($first), (int) DB::table('e888_action_cards')
            ->whereNull('revoked_at')->whereNull('consumed_at')->count());
    }

    public function test_changed_evidence_supersedes_rather_than_mutates(): void
    {
        $c = $this->candidate();
        $before = $this->issuer()->reconcile($this->req(), $this->conversation);
        $oldUuid = $before[0]->uuid;
        $oldFingerprint = $before[0]->content_fingerprint;

        // The candidate is revised: same task, different bytes.
        DB::table('engineering_candidates')->where('id', $c->id)
            ->update(['content_fingerprint' => 'fp-REVISED']);

        $after = $this->issuer()->reconcile($this->req(), $this->conversation);

        $old = DB::table('e888_action_cards')->where('uuid', $oldUuid)->first();
        $this->assertNotNull($old->revoked_at, 'the stale card must be revoked');
        $this->assertSame('superseded_by_evidence', $old->consumed_result);
        $this->assertSame($oldFingerprint, $old->content_fingerprint,
            'the old binding must be preserved, never rewritten');

        $this->assertNotContains($oldUuid, collect($after)->pluck('uuid')->all());
    }

    public function test_an_expired_card_is_superseded_and_reissued_while_the_decision_is_still_due(): void
    {
        // The defect this pins: reconciliation filtered only consumed and
        // revoked, so a lapsed card still satisfied its identity and no
        // replacement was issued — while visibleFor() correctly hid it. Live
        // cards became permanently invisible. Observed in production on
        // 2026-08-05: 21 persisted, 0 returned, all expired.
        $this->candidate();
        $first = $this->issuer()->reconcile($this->req(), $this->conversation);
        $this->assertNotEmpty($first);

        DB::table('e888_action_cards')->update(['expires_at' => now()->subHour()]);

        $this->assertSame([], app(ActionCardService::class)
            ->visibleFor($this->req(), $this->conversation),
            'an expired card must not be shown');

        $second = $this->issuer()->reconcile($this->req(), $this->conversation);

        $this->assertCount(count($first), $second, 'the decision is still due, so a fresh card must be issued');
        $this->assertNotEmpty(app(ActionCardService::class)
            ->visibleFor($this->req(), $this->conversation),
            'the reissued card must be visible');

        foreach ($first as $old) {
            $row = DB::table('e888_action_cards')->where('uuid', $old->uuid)->first();
            $this->assertNotNull($row->revoked_at, 'the lapsed card must be retired');
            $this->assertSame('expired', $row->consumed_result);
        }

        $this->assertSame(
            [],
            array_intersect(
                collect($first)->pluck('uuid')->all(),
                collect($second)->pluck('uuid')->all()
            ),
            'reissued cards must be new rows, not the old ones mutated'
        );
    }

    public function test_an_expired_card_is_not_reissued_once_the_decision_is_gone(): void
    {
        $c = $this->candidate();
        $this->issuer()->reconcile($this->req(), $this->conversation);

        DB::table('e888_action_cards')->update(['expires_at' => now()->subHour()]);
        DB::table('engineering_candidates')->where('id', $c->id)->update(['superseded_at' => now()]);

        $this->assertSame([], $this->issuer()->reconcile($this->req(), $this->conversation),
            'expiry must not resurrect a card whose evidence is gone');
    }

    // ── execution ────────────────────────────────────────────────────

    public function test_approving_a_candidate_fails_closed_until_it_is_wired(): void
    {
        // REWRITTEN 2026-08-06. This previously asserted that pressing an
        // approval card marked it consumed — and passed, against a card layer
        // that performed no engineering action at all. That is the assertion
        // that let ACTION_CARD_CONSUMED_WITHOUT_DOMAIN_EFFECT reach a browser.
        //
        // APPROVE_CANDIDATE has no domain wiring yet, so the correct behaviour
        // is to refuse. When it is wired, this test must be replaced by one
        // asserting the approval LEDGER row — never card state alone.
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);

        try {
            app(ActionCardService::class)
                ->consume($this->req(), $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed');
            $this->fail('an unwired action must never report success');
        } catch (HttpException) {
            // expected
        }

        $row = DB::table('e888_action_cards')->where('uuid', $card->uuid)->first();
        $this->assertNull($row->consumed_at, 'a refused card must be released for retry');
        $this->assertSame(ActionCardExecutor::NOT_IMPLEMENTED, $row->consumed_result);
        $this->assertSame(0, DB::table('engineering_candidate_approvals')->where('candidate_id', $c->id)->count(),
            'no decision may be recorded by an unwired action');
    }

    public function test_a_card_pressed_at_the_wrong_endpoint_is_refused(): void
    {
        $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)
            ->consume($this->req(), $card->uuid, ActionCardService::APPROVE_RECOVERY, 'executed');
    }

    public function test_a_card_whose_fingerprint_moved_is_refused(): void
    {
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);

        DB::table('engineering_candidates')->where('id', $c->id)
            ->update(['content_fingerprint' => 'fp-DRIFTED']);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)
            ->consume($this->req(), $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed');
    }

    public function test_a_card_whose_workflow_state_moved_is_refused(): void
    {
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);

        DB::table('engineering_tasks')->where('id', $c->task_id)
            ->update(['current_stage' => 'DEPLOY_VERIFY']);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)
            ->consume($this->req(), $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed');
    }

    public function test_an_expired_card_is_refused(): void
    {
        $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);
        DB::table('e888_action_cards')->where('id', $card->id)->update(['expires_at' => now()->subMinute()]);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume($this->req(), $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed');
    }

    public function test_a_revoked_card_is_refused(): void
    {
        $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);
        DB::table('e888_action_cards')->where('id', $card->id)->update(['revoked_at' => now()]);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume($this->req(), $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed');
    }

    public function test_a_consumed_card_cannot_be_pressed_twice(): void
    {
        // Uses REJECT_CANDIDATE — the action that actually mutates engineering
        // state — so "consumed" means a real decision was taken, not that a
        // no-op returned quietly.
        $this->candidate();
        $card = $this->cardOfType(ActionCardService::REJECT_CANDIDATE);
        $input = ['instruction' => 'replay probe'];

        app(ActionCardService::class)
            ->consume($this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed', $input);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)
            ->consume($this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed', $input);
    }

    public function test_an_unwired_action_cannot_be_pressed_even_once(): void
    {
        // The old version of this test counted successful presses of an action
        // that did nothing, and asserted exactly one "succeeded". Both presses
        // should have failed. Concurrency for the WIRED action is proven by
        // test_two_presses_produce_exactly_one_domain_effect, which counts
        // ledger rows rather than card writes.
        $c = $this->candidate();
        $this->approve($c);
        $card = $this->cardOfType(ActionCardService::EXECUTE_TASK);

        $ok = 0;
        foreach ([1, 2] as $_) {
            try {
                app(ActionCardService::class)
                    ->consume($this->req(), $card->uuid, ActionCardService::EXECUTE_TASK, 'executed');
                $ok++;
            } catch (HttpException) {
                // expected
            }
        }

        $this->assertSame(0, $ok, 'an unwired action must never succeed, at any concurrency');
        $this->assertSame(ActionCardExecutor::NOT_IMPLEMENTED,
            DB::table('e888_action_cards')->where('uuid', $card->uuid)->value('consumed_result'));
    }

    // ── DOMAIN EFFECT — the invariant the 2026-08-05 incident cost us ──
    //
    // NO CARD MAY REPORT SUCCESS UNLESS THE EXPECTED DOMAIN EFFECT EXISTS.
    // Every assertion below checks the ENGINEERING record, not just the card.

    public function test_rejecting_a_candidate_actually_rejects_it(): void
    {
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::REJECT_CANDIDATE);

        $this->assertSame('VALIDATED', DB::table('engineering_candidates')->where('id', $c->id)->value('status'));
        $this->assertSame(0, DB::table('engineering_candidate_approvals')->where('candidate_id', $c->id)->count());

        $consumed = app(ActionCardService::class)->consume(
            $this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed',
            ['instruction' => 'Zero-file candidate; nothing to implement.']
        );

        // A. card lifecycle
        $this->assertNotNull($consumed->consumed_at);
        $this->assertSame('executed', $consumed->consumed_result);

        // B. ENGINEERING DOMAIN EFFECT — the part that was missing
        $approval = DB::table('engineering_candidate_approvals')->where('candidate_id', $c->id)->first();
        $this->assertNotNull($approval, 'a decision record must exist');
        $this->assertSame(\App\Core\Engineer888\Approval\ApprovalState::REJECTED, $approval->state);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame(1, (int) $approval->approver_user_id);
        $this->assertSame('Zero-file candidate; nothing to implement.', $approval->comment);
    }

    public function test_a_rejected_candidate_stops_reissuing_cards(): void
    {
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::REJECT_CANDIDATE);

        app(ActionCardService::class)->consume(
            $this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed',
            ['instruction' => 'not needed']
        );

        // The replacement card is exactly what appeared on 2026-08-05 because
        // the decision was never recorded. Now it must not.
        $after = $this->issuer()->reconcile($this->req(), $this->conversation);
        $types = collect($after)->pluck('action_type')->all();

        $this->assertNotContains(ActionCardService::REJECT_CANDIDATE, $types);
        $this->assertNotContains(ActionCardService::APPROVE_CANDIDATE, $types);
    }

    public function test_a_rejection_without_an_instruction_is_refused_and_changes_nothing(): void
    {
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::REJECT_CANDIDATE);

        try {
            app(ActionCardService::class)->consume(
                $this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed', []
            );
            $this->fail('a rejection with no stated reason must be refused');
        } catch (HttpException) {
            // expected
        }

        $this->assertSame(0, DB::table('engineering_candidate_approvals')->where('candidate_id', $c->id)->count(),
            'no decision may be fabricated');
        $this->assertNull(DB::table('e888_action_cards')->where('uuid', $card->uuid)->value('consumed_at'),
            'a refused card must be released, not left claimed');
        $this->assertSame(ActionCardExecutor::INSTRUCTION_REQUIRED,
            DB::table('e888_action_cards')->where('uuid', $card->uuid)->value('consumed_result'));
    }

    public function test_an_unimplemented_action_fails_closed_and_never_reports_success(): void
    {
        $c = $this->candidate();
        $this->approve($c);
        $card = $this->cardOfType(ActionCardService::EXECUTE_TASK);

        try {
            app(ActionCardService::class)->consume(
                $this->req(), $card->uuid, ActionCardService::EXECUTE_TASK, 'executed', []
            );
            $this->fail('execute_task has no domain wiring and must refuse');
        } catch (HttpException) {
            // expected
        }

        $this->assertSame(ActionCardExecutor::NOT_IMPLEMENTED,
            DB::table('e888_action_cards')->where('uuid', $card->uuid)->value('consumed_result'),
            'an unwired action must say so, never report success');
    }

    public function test_two_presses_produce_exactly_one_domain_effect(): void
    {
        $c = $this->candidate();
        $card = $this->cardOfType(ActionCardService::REJECT_CANDIDATE);

        $ok = 0;
        foreach ([1, 2] as $_) {
            try {
                app(ActionCardService::class)->consume(
                    $this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed',
                    ['instruction' => 'duplicate press probe']
                );
                $ok++;
            } catch (HttpException) {
                // refused
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(1, DB::table('engineering_candidate_approvals')->where('candidate_id', $c->id)->count(),
            'a second press must not produce a second decision');
    }

    // ── authorization ────────────────────────────────────────────────

    public function test_a_foreign_owner_sees_and_presses_nothing(): void
    {
        $this->candidate();
        $card = $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);

        $foreign = $this->req(990016, 'other@levelupgrowth.io');

        $this->assertSame([], app(ActionCardService::class)->visibleFor($foreign, $this->conversation));

        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume($foreign, $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed');
    }

    public function test_a_guessed_card_uuid_is_refused(): void
    {
        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume($this->req(), (string) Str::uuid(), ActionCardService::APPROVE_CANDIDATE, 'executed');
    }

    public function test_no_card_row_is_ever_owned_by_anyone_but_one(): void
    {
        $this->candidate();
        $this->issuer()->reconcile($this->req(), $this->conversation);

        $this->assertSame(0, (int) DB::table('e888_action_cards')->where('owner_user_id', '!=', 1)->count());
        $this->assertSame(0, (int) DB::table('e888_conversations')->where('owner_user_id', '!=', 1)->count());
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function issuer(): CardIssuanceService
    {
        return app(CardIssuanceService::class);
    }

    private function cardOfType(string $type): object
    {
        $cards = $this->issuer()->reconcile($this->req(), $this->conversation);

        foreach ($cards as $c) {
            if ($c->action_type === $type) { return $c; }
        }

        $this->fail("no {$type} card was issued");
    }

    private function candidate(): object
    {
        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => (string) Str::uuid(), 'project_id' => $this->projectId,
            'title' => 'Fixture task', 'description' => 'fixture', 'kind' => 'investigation',
            'status' => 'received', 'current_stage' => 'ANALYZE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) Str::uuid();
        DB::table('engineering_candidates')->insert([
            'uuid' => $uuid, 'task_id' => $taskId, 'project_id' => $this->projectId,
            'provider' => 'fixture', 'model' => 'fixture', 'status' => 'VALIDATED',
            'request_fingerprint' => 'req-fp', 'content_fingerprint' => 'fp-ORIGINAL',
            'payload' => json_encode(['files' => [['path' => 'a.php', 'content' => 'x']]]),
            'file_count' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('engineering_candidates')->where('uuid', $uuid)->first();
    }

    private function approve(object $candidate): void
    {
        DB::table('engineering_candidate_approvals')->insert([
            'candidate_id' => $candidate->id, 'candidate_uuid' => $candidate->uuid,
            'task_id' => $candidate->task_id, 'project_id' => $this->projectId,
            'state' => 'approved', 'fingerprint' => $candidate->content_fingerprint,
            'approver_user_id' => 1, 'approver_name' => 'Mark',
            'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rehearsal(object $candidate, string $status): void
    {
        DB::table('engineering_migration_rehearsals')->insert([
            'rehearsal_uuid' => (string) Str::uuid(),
            'task_uuid' => $this->taskUuid($candidate),
            'candidate_uuid' => $candidate->uuid,
            'migration_path' => 'database/migrations/fixture.php',
            'migration_hash' => 'mh', 'classification' => 'additive',
            'status' => $status, 'database' => 'levelup_e888_test',
            'project_key' => 'fixture-project', 'phpunit_config' => 'phpunit.e888.xml',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function taskUuid(object $candidate): string
    {
        return (string) DB::table('engineering_tasks')->where('id', $candidate->task_id)->value('uuid');
    }

    /**
     * A request carrying a resolved user.
     *
     * ORDER MATTERS, AND IT IS THE OPPOSITE OF THE OBVIOUS ONE.
     * app()->instance('request', $r) fires Laravel's request-rebinding handler,
     * registered by AuthServiceProvider, which OVERWRITES the request's user
     * resolver with one that asks the auth guard. No guard is authenticated in
     * these tests, so that resolver returns null and every owner check fails.
     *
     * So the request is bound FIRST and the resolver installed AFTER, where the
     * rebinding handler can no longer replace it.
     *
     * Proven by bisection on 2026-08-05: User::find(1) returned the row both
     * before and after binding, and User has no global scopes at all — the model
     * was never the problem. $r->user() was null purely because the resolver had
     * been swapped out from under it.
     */
    private function req(int $id = 1, string $email = 'admin@levelupgrowth.io'): Request
    {
        $user = User::find($id);

        $r = Request::create('/api/admin/engineer888/chat/messages', 'GET');
        $r->attributes->set('auth_via', 'jwt_cookie');

        app()->instance('request', $r);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    private function seedCanonical(): void
    {
        $u = new User();
        $u->id = 1; $u->name = 'Mark'; $u->email = Engineer888Access::CANONICAL_EMAIL;
        $u->password = 'irrelevant'; $u->is_platform_admin = true; $u->status = 'active';
        $u->save();

        $o = new User();
        $o->id = 990016; $o->name = 'Other'; $o->email = 'other@levelupgrowth.io';
        $o->password = 'irrelevant'; $o->is_platform_admin = true; $o->status = 'active';
        $o->save();

        DB::table('engineering_access_grants')->where('user_id', 1)->delete();
        DB::table('engineering_access_grants')->insert([
            'user_id' => 1, 'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

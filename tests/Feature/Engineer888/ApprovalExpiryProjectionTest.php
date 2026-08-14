<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Chat\ActionCardService;
use App\Core\Engineer888\Chat\CardIssuanceService;
use App\Core\Engineer888\Decisions\DecisionProjection;
use App\Core\Engineer888\Decisions\DecisionState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AN EXPIRED APPROVAL IS NOT A READY DECISION.
 *
 * ── THE MEASURED DEFECT (2026-08-14) ────────────────────────────────
 *
 * Two Bug Tracker candidates were approved in the browser on 2026-08-13 at
 * 12:21 UTC. ApprovalLedger grants a 12-hour window, so both lapsed at 00:21
 * the following morning while nobody was at the machine. At 05:20 the live
 * projection still offered them as execute decisions, and CardIssuanceService
 * would still have minted execute_task cards for them, while
 * ApprovalLedger::enforce() would have refused the press outright.
 *
 * Nothing unsafe ever happened — the gate held, as it was built to. What was
 * wrong is that three parts of the system each had their own idea of what
 * "approved" meant, and the one Boss could see was the wrong one:
 *
 *   ApprovalLedger::enforce()          state + expires_at   → refuse
 *   DecisionProjection::current()      approved_at NOT NULL → ready to execute
 *   CardIssuanceService::eligible()    approved_at NOT NULL → issue the card
 *
 * ── WHAT THESE TESTS PROTECT ────────────────────────────────────────
 *
 * Not "expiry works" — it already did, in the gate. What is worth proving is
 * that no surface can answer the question independently again. Every assertion
 * below either checks a state against the LEDGER's answer, or checks that two
 * surfaces give the SAME answer. A future change that reintroduces a private
 * copy of the TTL comparison makes one of these red.
 *
 * And the history must survive it. An expired approval is still a real thing a
 * named human did; the row keeps its approver, its statement and its timestamp
 * unchanged. Only the authority moved.
 */
class ApprovalExpiryProjectionTest extends TestCase
{
    use RefreshDatabase;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->seedCanonical();
        $this->projectId = $this->seedProject();
    }

    // ── 1 + 2. the rule itself ──────────────────────────────────────────

    public function test_an_approval_inside_its_window_is_ready_to_execute(): void
    {
        $this->approvedWork('Inside the window', now()->addHours(6));

        $item = $this->executeDecision();

        $this->assertNotNull($item, 'an approved, unexpired, unrun candidate is a decision');
        $this->assertSame(DecisionState::READY_TO_EXECUTE, $item['state']);
        $this->assertTrue($item['executable']);
        $this->assertSame(DecisionProjection::KIND_EXECUTE, $item['kind']);
    }

    public function test_an_approval_past_its_window_is_not_ready_to_execute(): void
    {
        $this->approvedWork('Past the window', now()->subMinutes(1));

        $item = $this->executeDecision();

        $this->assertNotNull($item, 'the work did not stop existing when the window closed');
        $this->assertSame(DecisionState::APPROVAL_EXPIRED, $item['state']);
        $this->assertFalse($item['executable'],
            'the gate would refuse this; the projection must not offer it');
        $this->assertNotSame(DecisionProjection::KIND_EXECUTE, $item['kind'],
            'a surface that only understands "execute" would draw a run button');
    }

    // ── 3. history is not rewritten ─────────────────────────────────────

    public function test_an_expired_approval_keeps_its_recorded_history(): void
    {
        ['approvalId' => $id] = $this->approvedWork('History survives', now()->subHour());

        $before = DB::table('engineering_candidate_approvals')->find($id);

        $item = $this->executeDecision();
        $this->assertSame(DecisionState::APPROVAL_EXPIRED, $item['state']);

        $after = DB::table('engineering_candidate_approvals')->find($id);

        $this->assertSame($before->state, $after->state, 'projecting must not mutate a governance row');
        $this->assertSame($before->approver_user_id, $after->approver_user_id);
        $this->assertSame($before->approver_name, $after->approver_name);
        $this->assertSame($before->statement, $after->statement);
        $this->assertSame($before->approved_at, $after->approved_at);
        $this->assertSame($before->expires_at, $after->expires_at);
        $this->assertSame($before->fingerprint, $after->fingerprint);

        // And the decision still SAYS who approved it and when.
        $this->assertSame($before->approver_name, $item['approved_by']);
        $this->assertNotNull($item['decided_at'], 'the approval genuinely happened');
    }

    // ── 4. no executable action may be surfaced ─────────────────────────

    public function test_an_expired_approval_surfaces_no_executable_card(): void
    {
        $this->approvedWork('No card for a lapsed approval', now()->subMinutes(5));

        $eligible = app(CardIssuanceService::class)->eligible($this->req(), $this->projectId);
        $types = array_column($eligible, 'action_type');

        $this->assertNotContains(ActionCardService::EXECUTE_TASK, $types,
            'a card is an authority; one issued against a refused approval is a button that can only fail');
        $this->assertNotContains(ActionCardService::REVOKE_APPROVAL, $types,
            'withdrawing an approval that can no longer authorise anything is not a decision to offer');
    }

    public function test_a_live_approval_still_surfaces_its_execute_card(): void
    {
        $this->approvedWork('Card for a live approval', now()->addHours(2));

        $types = array_column(
            app(CardIssuanceService::class)->eligible($this->req(), $this->projectId),
            'action_type'
        );

        $this->assertContains(ActionCardService::EXECUTE_TASK, $types,
            'the fix must not have simply switched execute cards off');
    }

    // ── 5. the surfaces agree, because only one of them decides ─────────

    public function test_every_projected_decision_matches_the_ledgers_own_verdict(): void
    {
        $this->approvedWork('Live one', now()->addHours(3));
        $this->approvedWork('Lapsed one', now()->subHours(3));
        $this->pendingWork('Unjudged one');

        $ledger = new ApprovalLedger();
        $checked = 0;

        foreach ((new DecisionProjection())->current($this->ctx(), $this->projectId) as $item) {
            $approval = DB::table('engineering_candidate_approvals')
                ->where('candidate_uuid', $item['candidate_uuid'])->first();

            if ($approval === null || $approval->approved_at === null) {
                $this->assertSame(DecisionState::REVIEW_REQUIRED, $item['state']);
                $this->assertFalse($item['executable']);
                $checked++;
                continue;
            }

            $this->assertSame(
                $ledger->permitsExecutionNow($approval),
                $item['executable'],
                'the projection and the ledger disagree about ' . $item['title']
            );
            $this->assertSame(
                DecisionState::fromAuthorityState($ledger->authorityState($approval)),
                $item['state']
            );
            $checked++;
        }

        $this->assertSame(3, $checked, 'all three decisions must have been examined');
    }

    public function test_the_projection_and_the_execution_gate_refuse_the_same_row(): void
    {
        ['candidateUuid' => $uuid] = $this->approvedWork('One row, two surfaces', now()->subMinute());

        $item = $this->executeDecision();
        $approval = (new ApprovalLedger())->forCandidateUuid($uuid);

        // The presentation says not executable...
        $this->assertFalse($item['executable']);
        // ...and the ledger, asked directly, says the same.
        $this->assertFalse((new ApprovalLedger())->permitsExecutionNow($approval));
        $this->assertSame(ApprovalState::EXPIRED, (new ApprovalLedger())->authorityState($approval));
        $this->assertTrue((new ApprovalLedger())->hasLapsed($approval));
        // ...while the stored state is still the historical one.
        $this->assertSame(ApprovalState::APPROVED, $approval->state);
    }

    // ── 6. an approval with no window follows the existing semantics ────

    public function test_an_approval_with_no_expiry_remains_executable(): void
    {
        // ApprovalLedger::approve() always sets a window, but enforce() guards
        // `expires_at !== null` and Challenge #1's approval 46 carries NULL. A
        // null window has always meant "does not lapse" and must keep meaning it.
        $this->approvedWork('No window at all', null);

        $item = $this->executeDecision();

        $this->assertSame(DecisionState::READY_TO_EXECUTE, $item['state']);
        $this->assertTrue($item['executable']);
        $this->assertFalse((new ApprovalLedger())->hasLapsed(
            DB::table('engineering_candidate_approvals')->latest('id')->first()
        ));
    }

    // ── 7, 8, 9. the other terminal states stay terminal ────────────────

    public function test_a_revoked_approval_is_not_executable(): void
    {
        ['approvalId' => $id] = $this->approvedWork('Revoked', now()->addHours(4));
        DB::table('engineering_candidate_approvals')->where('id', $id)
            ->update(['state' => ApprovalState::REVOKED, 'revoked_at' => now(), 'revoked_by' => 'Mark']);

        $this->assertNull($this->executeDecision(), 'a withdrawn approval is history, not a decision');
        $this->assertNotContains(ActionCardService::EXECUTE_TASK, array_column(
            app(CardIssuanceService::class)->eligible($this->req(), $this->projectId), 'action_type'));
    }

    public function test_a_rejected_candidate_is_not_executable(): void
    {
        ['approvalId' => $id] = $this->approvedWork('Rejected', now()->addHours(4));
        DB::table('engineering_candidate_approvals')->where('id', $id)->update([
            'state' => ApprovalState::REJECTED, 'approved_at' => null, 'decided_at' => now(),
        ]);

        $this->assertNull($this->executeDecision());
    }

    public function test_a_completed_task_is_not_executable(): void
    {
        ['taskId' => $taskId] = $this->approvedWork('Completed', now()->addHours(4));
        DB::table('engineering_tasks')->where('id', $taskId)->update(['status' => 'completed']);

        $this->assertNull($this->executeDecision(), 'the work ran; there is nothing left to decide');
    }

    // ── 10. only the clock moved ────────────────────────────────────────

    public function test_the_transition_needs_no_change_to_the_candidate(): void
    {
        ['approvalId' => $id, 'candidateUuid' => $uuid] =
            $this->approvedWork('Only the clock moved', now()->addMinutes(30));

        $this->assertTrue($this->executeDecision()['executable']);

        $candidateBefore = DB::table('engineering_candidates')->where('uuid', $uuid)->first();

        // Nothing about the work changes. The window closes, and that is all.
        DB::table('engineering_candidate_approvals')->where('id', $id)
            ->update(['expires_at' => now()->subMinute()]);

        $after = $this->executeDecision();

        $this->assertFalse($after['executable']);
        $this->assertSame(DecisionState::APPROVAL_EXPIRED, $after['state']);
        $this->assertEquals(
            $candidateBefore,
            DB::table('engineering_candidates')->where('uuid', $uuid)->first(),
            'the candidate is untouched; only the approval window elapsed'
        );
    }

    // ── 11. the re-approval contract, exactly as the domain defines it ──

    public function test_a_lapsed_approval_cannot_be_re_approved_in_place(): void
    {
        // RECORDING A MEASURED CONSTRAINT, NOT ASSERTING A WISH.
        //
        // ApprovalLedger::approve() refuses any row that is not PENDING, and
        // record() returns the existing row rather than opening a second one
        // for the same candidate. Together those mean an expired approval is
        // TERMINAL for that candidate: the forward path is a new candidate,
        // which gets its own approval row.
        //
        // This test exists so that contract is stated somewhere. If a
        // re-approval capability is ever added, this test is the thing that
        // should be deliberately rewritten — not silently discovered to be
        // failing.
        ['candidateUuid' => $uuid, 'approvalId' => $id] =
            $this->approvedWork('Terminal once lapsed', now()->subMinute());

        $ledger = new ApprovalLedger();
        $row = $ledger->forCandidateUuid($uuid);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not pending|never re-opened/i');

        $ledger->approve($uuid, (string) $row->fingerprint, 1, 'Mark');
    }

    public function test_a_second_approval_row_is_never_opened_for_the_same_candidate(): void
    {
        ['candidateUuid' => $uuid] = $this->approvedWork('One row only', now()->subMinute());

        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_uuid', $uuid)->count());
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /** The one execute-side decision in the projection, or null. */
    private function executeDecision(): ?array
    {
        foreach ((new DecisionProjection())->current($this->ctx(), $this->projectId) as $item) {
            if (str_starts_with($item['work_key'], 'exec:')) { return $item; }
        }

        return null;
    }

    /**
     * A VALIDATED candidate whose approval was granted and given this window.
     *
     * Inserted directly rather than driven through the reasoning engine: the
     * subject here is the approval window, and a fixture that has to produce
     * real candidate bytes to move a timestamp would be testing the provider.
     *
     * @return array{taskId:int,candidateUuid:string,approvalId:int}
     */
    private function approvedWork(string $title, ?\DateTimeInterface $expiresAt): array
    {
        ['taskId' => $taskId, 'candidateId' => $cid, 'candidateUuid' => $uuid] = $this->candidate($title);

        $id = DB::table('engineering_candidate_approvals')->insertGetId([
            'candidate_id'    => $cid,
            'candidate_uuid'  => $uuid,
            'task_id'         => $taskId,
            'project_id'      => $this->projectId,
            'state'           => ApprovalState::APPROVED,
            'fingerprint'     => hash('sha256', $uuid),
            'binding'         => json_encode(['version' => 'e888-approval-v1', 'candidate_uuid' => $uuid]),
            'approved_paths'  => json_encode(['app/Owned/A.php']),
            'approved_hashes' => json_encode(['app/Owned/A.php' => hash('sha256', 'a')]),
            'provider'        => 'scripted',
            'model'           => 'fixture',
            'approver_user_id' => 1,
            'approver_name'   => 'Mark',
            'statement'       => 'I approve candidate ' . $uuid . '.',
            'approved_at'     => now()->subHours(12),
            'expires_at'      => $expiresAt,
            'created_at'      => now()->subHours(12),
            'updated_at'      => now()->subHours(12),
        ]);

        return ['taskId' => $taskId, 'candidateUuid' => $uuid, 'approvalId' => $id];
    }

    /** A VALIDATED candidate with a PENDING approval — awaiting review. */
    private function pendingWork(string $title): void
    {
        ['taskId' => $taskId, 'candidateId' => $cid, 'candidateUuid' => $uuid] = $this->candidate($title);

        DB::table('engineering_candidate_approvals')->insert([
            'candidate_id' => $cid, 'candidate_uuid' => $uuid, 'task_id' => $taskId,
            'project_id' => $this->projectId, 'state' => ApprovalState::PENDING,
            'fingerprint' => hash('sha256', $uuid),
            'binding' => json_encode(['version' => 'e888-approval-v1', 'candidate_uuid' => $uuid]),
            'approved_paths' => json_encode([]), 'approved_hashes' => json_encode([]),
            'provider' => 'scripted', 'model' => 'fixture',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{taskId:int,candidateId:int,candidateUuid:string} */
    private function candidate(string $title): array
    {
        $taskUuid = (string) \Illuminate\Support\Str::uuid();

        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => $taskUuid, 'project_id' => $this->projectId,
            'title' => $title, 'description' => 'Fixture for ' . $title,
            'status' => 'blocked', 'current_stage' => 'REQUEST_APPROVAL',
            'session' => 'expiry-fixture',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) \Illuminate\Support\Str::uuid();

        $cid = DB::table('engineering_candidates')->insertGetId([
            'uuid' => $uuid, 'task_id' => $taskId, 'project_id' => $this->projectId,
            'provider' => 'scripted', 'model' => 'fixture', 'status' => 'VALIDATED',
            'request_fingerprint' => substr(hash('sha1', $uuid), 0, 40),
            'confidence' => 'high', 'file_count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['taskId' => $taskId, 'candidateId' => $cid, 'candidateUuid' => $uuid];
    }

    private function seedProject(): int
    {
        return DB::table('engineering_projects')->insertGetId([
            'company' => 'Fixture Co', 'key' => 'expiry-project', 'name' => 'Expiry Project',
            'repository_path' => sys_get_temp_dir() . '/e888-expiry-' . getmypid(),
            'test_database' => 'levelup_e888_test', 'phpunit_config' => 'phpunit.e888.xml',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ctx(): Engineer888AccessContext
    {
        return Engineer888AccessContext::forHuman(User::find(1), 'jwt', 'jwt');
    }

    private function req(): Request
    {
        $request = Request::create('/api/admin/engineer888/chat', 'POST');
        $request->setUserResolver(fn () => User::find(1));
        $request->attributes->set('auth_via', 'jwt');

        return $request;
    }

    private function seedCanonical(): void
    {
        $user = new User();
        $user->id = 1;
        $user->name = 'Mark';
        $user->email = Engineer888Access::CANONICAL_EMAIL;
        $user->password = 'irrelevant';
        $user->is_platform_admin = true;
        $user->status = 'active';
        $user->save();

        DB::table('engineering_access_grants')->where('user_id', 1)->delete();
        DB::table('engineering_access_grants')->insert([
            'user_id' => 1,
            'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

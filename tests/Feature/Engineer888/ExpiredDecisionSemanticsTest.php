<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Decisions\DecisionPresentationResolver;
use App\Core\Engineer888\Decisions\DecisionProjection;
use App\Core\Engineer888\Decisions\DecisionState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WHAT AN EXPIRED APPROVAL IS ALLOWED TO OFFER, AND WHAT IT MUST NOT.
 *
 * Boss's ruling, 2026-08-14: APPROVAL_EXPIRED is TERMINAL for its candidate.
 * No re-approval capability, no automatic re-run, no regeneration, and the
 * expired row is never mutated. The historical approval and the candidate both
 * stay exactly as they are; execution authority is NONE.
 *
 * So the decision is EXPLAINED, not actioned. The actions it may carry are
 * read-only — view the candidate, view the history — and the words tell him
 * that running the task again is available if he asks for it. Nothing on the
 * surface may say execute, approve again, renew, or extend, because none of
 * those exist in the domain and a control that cannot work is the defect this
 * whole phase is about.
 *
 * ── AND THE THREE QUESTIONS ARE NOT ONE QUESTION ────────────────────
 *
 * "What can I execute?" must exclude it. "What needs my approval?" must
 * exclude it — nobody can approve it, so listing it there offers a decision
 * that cannot be taken. "What needs my attention?" must include it, with the
 * explanation attached.
 */
class ExpiredDecisionSemanticsTest extends TestCase
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

    // ── the action set ──────────────────────────────────────────────────

    public function test_an_expired_decision_offers_only_read_only_actions(): void
    {
        $this->approvedWork('Expired', now()->subHour());

        $item = $this->expiredDecision();

        $this->assertSame(
            [DecisionState::ACTION_VIEW_CANDIDATE, DecisionState::ACTION_VIEW_HISTORY],
            $item['actions']
        );
    }

    public function test_an_expired_decision_never_offers_execute_approve_renew_or_extend(): void
    {
        $this->approvedWork('Nothing pressable', now()->subHour());

        $actions = $this->expiredDecision()['actions'];

        foreach ([DecisionState::ACTION_EXECUTE, DecisionState::ACTION_APPROVE, DecisionState::ACTION_REJECT] as $forbidden) {
            $this->assertNotContains($forbidden, $actions,
                "an expired approval must not offer '{$forbidden}': the domain has no way to honour it");
        }

        // There is deliberately no renew/extend action anywhere in the vocabulary.
        $this->assertNotContains('renew', DecisionState::offerableActions(DecisionState::APPROVAL_EXPIRED));
        $this->assertNotContains('extend', DecisionState::offerableActions(DecisionState::APPROVAL_EXPIRED));
    }

    public function test_a_live_decision_still_offers_its_real_actions(): void
    {
        $this->approvedWork('Runnable', now()->addHours(3));

        $item = $this->firstExec();

        $this->assertContains(DecisionState::ACTION_EXECUTE, $item['actions']);
        $this->assertSame(DecisionState::READY_TO_EXECUTE, $item['state']);
    }

    public function test_a_review_decision_offers_approve_and_reject(): void
    {
        $this->pendingWork('Unjudged');

        $item = (new DecisionProjection())->awaitingApproval($this->ctx(), $this->projectId)[0];

        $this->assertContains(DecisionState::ACTION_APPROVE, $item['actions']);
        $this->assertContains(DecisionState::ACTION_REJECT, $item['actions']);
        $this->assertNotContains(DecisionState::ACTION_EXECUTE, $item['actions']);
    }

    // ── the words ───────────────────────────────────────────────────────

    public function test_an_expired_decision_explains_itself_in_bosss_language(): void
    {
        $this->approvedWork('Explained', now()->subHour());

        $explanation = (string) $this->expiredDecision()['explanation'];

        $this->assertStringContainsString('expired before this candidate was executed', $explanation);
        $this->assertStringContainsString('Nothing was changed', $explanation);
        $this->assertStringContainsString('can no longer be executed', $explanation);
        $this->assertStringContainsString('run the task again', $explanation,
            'he must be told the one route that does exist');
    }

    // ── the three questions ─────────────────────────────────────────────

    public function test_what_can_i_execute_excludes_an_expired_approval(): void
    {
        $this->approvedWork('Lapsed', now()->subHour());
        $this->approvedWork('Live', now()->addHour());

        $executable = (new DecisionProjection())->executable($this->ctx(), $this->projectId);

        $this->assertCount(1, $executable);
        $this->assertSame('Live', $executable[0]['title']);
        $this->assertTrue($executable[0]['executable']);
    }

    public function test_what_needs_my_approval_excludes_an_expired_approval(): void
    {
        $this->approvedWork('Lapsed', now()->subHour());
        $this->pendingWork('Genuinely awaiting judgement');

        $awaiting = (new DecisionProjection())->awaitingApproval($this->ctx(), $this->projectId);

        $this->assertCount(1, $awaiting);
        $this->assertSame('Genuinely awaiting judgement', $awaiting[0]['title'],
            'nobody can approve a lapsed row, so offering it under "needs approval" offers nothing');
    }

    public function test_what_needs_my_attention_includes_an_expired_approval(): void
    {
        $this->approvedWork('Lapsed', now()->subHour());
        $this->pendingWork('Unjudged');

        $attention = (new DecisionProjection())->needingAttention($this->ctx(), $this->projectId);
        $states = array_column($attention, 'state');

        $this->assertCount(2, $attention);
        $this->assertContains(DecisionState::APPROVAL_EXPIRED, $states,
            'the work did not stop existing when the window closed');
        $this->assertContains(DecisionState::REVIEW_REQUIRED, $states);
    }

    // ── the conversational route into those three ───────────────────────

    /** @dataProvider phrasings */
    public function test_the_hint_selects_the_slice_the_words_meant(string $hint, array $expectedTitles): void
    {
        $this->approvedWork('Lapsed', now()->subHour());
        $this->approvedWork('Live', now()->addHour());
        $this->pendingWork('Unjudged');

        $refs = app(DecisionPresentationResolver::class)
            ->referencesFor($this->ctx(), 'SHOW_DECISIONS', $hint, $this->projectId);

        $hydrated = app(DecisionPresentationResolver::class)
            ->hydrate($this->ctx(), $refs, $this->projectId);

        $titles = array_map(fn ($h) => $h['decision']['title'], $hydrated);
        sort($titles);
        sort($expectedTitles);

        $this->assertSame($expectedTitles, $titles, "wrong slice for: {$hint}");
    }

    public static function phrasings(): array
    {
        return [
            'execute'          => ['what can I execute', ['Live']],
            'run it'           => ['which of these can I run', ['Live']],
            'ship'             => ['anything I can ship', ['Live']],
            'approval'         => ['what needs my approval', ['Unjudged']],
            'sign off'         => ['what do I need to sign off', ['Unjudged']],
            'attention'        => ['what needs my attention', ['Lapsed', 'Live', 'Unjudged']],
            'unclear phrasing' => ['show me those', ['Lapsed', 'Live', 'Unjudged']],
        ];
    }

    // ── the rendered card ───────────────────────────────────────────────

    public function test_the_rendered_expired_card_exposes_no_pressable_card(): void
    {
        $this->approvedWork('Rendered', now()->subHour());

        $refs = app(DecisionPresentationResolver::class)
            ->referencesFor($this->ctx(), 'SHOW_DECISION', 'Rendered', $this->projectId);
        $decision = app(DecisionPresentationResolver::class)
            ->hydrate($this->ctx(), $refs, $this->projectId)[0]['decision'];

        $this->assertSame(DecisionState::APPROVAL_EXPIRED, $decision['state']);
        $this->assertFalse($decision['executable']);
        $this->assertNull($decision['execute_card'], 'no button that the gate would refuse');
        $this->assertNull($decision['review_card']);
        $this->assertNull($decision['reject_card']);
        $this->assertNotNull($decision['explanation']);
        $this->assertFalse($decision['stale'],
            'a read-only state has no card by design; calling that stale reports a fault that is not one');

        // The history it carries is real and is not rewritten.
        $this->assertSame('Mark', $decision['approved_by']);
        $this->assertNotNull($decision['expires_at']);
    }

    public function test_a_stale_execute_card_is_not_handed_to_the_page(): void
    {
        // Belt and braces: CardIssuanceService no longer mints one, but if a
        // card issued before the window closed is still sitting open in the
        // table, the resolver must not expose it either. Two independent gates.
        ['candidateUuid' => $uuid, 'taskId' => $taskId] = $this->approvedWork('Stale card', now()->subHour());

        $conversationId = DB::table('e888_conversations')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'owner_user_id' => 1,
            'active_project_id' => $this->projectId, 'title' => 'fixture',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('e888_action_cards')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $conversationId, 'owner_user_id' => 1,
            'issued_to_user_id' => 1,
            'action_type' => 'execute_task',
            'task_uuid' => DB::table('engineering_tasks')->where('id', $taskId)->value('uuid'),
            'candidate_uuid' => $uuid,
            'expires_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $refs = app(DecisionPresentationResolver::class)
            ->referencesFor($this->ctx(), 'SHOW_DECISION', 'Stale card', $this->projectId);
        $decision = app(DecisionPresentationResolver::class)
            ->hydrate($this->ctx(), $refs, $this->projectId)[0]['decision'];

        $this->assertNull($decision['execute_card'],
            'the live card exists, and the state still refuses to hand it over');
    }

    // ── nothing is spent, nothing is mutated ────────────────────────────

    public function test_projecting_an_expired_decision_starts_no_work_and_writes_nothing(): void
    {
        ['approvalId' => $id, 'taskId' => $taskId] = $this->approvedWork('Nothing happens', now()->subHour());

        $approvalBefore = DB::table('engineering_candidate_approvals')->find($id);
        $taskBefore     = DB::table('engineering_tasks')->find($taskId);
        $candidates     = DB::table('engineering_candidates')->count();
        $approvals      = DB::table('engineering_candidate_approvals')->count();

        // Everything a surface would do with it.
        $proj = new DecisionProjection();
        $proj->current($this->ctx(), $this->projectId);
        $proj->executable($this->ctx(), $this->projectId);
        $proj->awaitingApproval($this->ctx(), $this->projectId);
        $proj->needingAttention($this->ctx(), $this->projectId);
        $refs = app(DecisionPresentationResolver::class)
            ->referencesFor($this->ctx(), 'SHOW_DECISIONS', 'what needs my attention', $this->projectId);
        app(DecisionPresentationResolver::class)->hydrate($this->ctx(), $refs, $this->projectId);

        $this->assertEquals($approvalBefore, DB::table('engineering_candidate_approvals')->find($id),
            'the expired approval must not be mutated by being looked at');
        $this->assertEquals($taskBefore, DB::table('engineering_tasks')->find($taskId),
            'no task is re-queued and no workflow starts');
        $this->assertSame($candidates, DB::table('engineering_candidates')->count(),
            'nothing is regenerated, so no provider spend');
        $this->assertSame($approvals, DB::table('engineering_candidate_approvals')->count(),
            'no second approval row is ever opened');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function expiredDecision(): array
    {
        $items = (new DecisionProjection())->inStates(
            $this->ctx(), [DecisionState::APPROVAL_EXPIRED], $this->projectId
        );
        $this->assertNotEmpty($items, 'expected an expired decision');

        return $items[0];
    }

    private function firstExec(): array
    {
        $items = (new DecisionProjection())->executable($this->ctx(), $this->projectId);
        $this->assertNotEmpty($items, 'expected an executable decision');

        return $items[0];
    }

    /** @return array{taskId:int,candidateUuid:string,approvalId:int} */
    private function approvedWork(string $title, ?\DateTimeInterface $expiresAt): array
    {
        ['taskId' => $taskId, 'candidateId' => $cid, 'candidateUuid' => $uuid] = $this->candidate($title);

        $id = DB::table('engineering_candidate_approvals')->insertGetId([
            'candidate_id' => $cid, 'candidate_uuid' => $uuid, 'task_id' => $taskId,
            'project_id' => $this->projectId, 'state' => ApprovalState::APPROVED,
            'fingerprint' => hash('sha256', $uuid),
            'binding' => json_encode(['version' => 'e888-approval-v1', 'candidate_uuid' => $uuid]),
            'approved_paths' => json_encode(['app/Owned/A.php']),
            'approved_hashes' => json_encode(['app/Owned/A.php' => hash('sha256', 'a')]),
            'provider' => 'scripted', 'model' => 'fixture',
            'approver_user_id' => 1, 'approver_name' => 'Mark',
            'statement' => 'I approve candidate ' . $uuid . '.',
            'approved_at' => now()->subHours(12), 'expires_at' => $expiresAt,
            'created_at' => now()->subHours(12), 'updated_at' => now()->subHours(12),
        ]);

        return ['taskId' => $taskId, 'candidateUuid' => $uuid, 'approvalId' => $id];
    }

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
        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $this->projectId,
            'title' => $title, 'description' => 'Fixture for ' . $title,
            'status' => 'blocked', 'current_stage' => 'REQUEST_APPROVAL',
            'session' => 'expiry-semantics', 'created_at' => now(), 'updated_at' => now(),
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
            'company' => 'Fixture Co', 'key' => 'expiry-semantics', 'name' => 'Expiry Semantics',
            'repository_path' => sys_get_temp_dir() . '/e888-expiry-sem-' . getmypid(),
            'test_database' => 'levelup_e888_test', 'phpunit_config' => 'phpunit.e888.xml',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ctx(): Engineer888AccessContext
    {
        return Engineer888AccessContext::forHuman(User::find(1), 'jwt', 'jwt');
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
            'user_id' => 1, 'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Decisions\DecisionPresentationResolver;
use App\Core\Engineer888\Decisions\DecisionProjection;
use App\Core\Engineer888\Decisions\DecisionState;
use App\Http\Controllers\Api\Admin\Engineer888DecisionsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * THE DECISIONS SURFACE, AND THE ONE NUMBER.
 *
 * ── WHY THIS PAGE HAD TO READ THE PROJECTION AND NOTHING ELSE ───────
 *
 * Four surfaces once answered "what is waiting on me" separately and gave four
 * answers: 52 live cards, a header saying 25, a per-task view saying 24, and
 * the model telling Boss "30". DecisionProjection exists so there is one
 * answer. A Decisions page that ran its own query would have been the fifth
 * opinion, on the page whose entire job is to be authoritative.
 *
 * So the assertions here are mostly about AGREEMENT: the page's count is the
 * projection's count, the page's items are the projection's items, and the
 * cards it exposes are the ones the resolver would expose in the conversation.
 *
 * ── AND THE HEADER MUST OPEN WHAT IT COUNTED ────────────────────────
 *
 * The badge read the projection; the click read the client-side card grouping
 * and opened DECISIONS[0]. With three decisions waiting it could open one of
 * them and offer no route to the other two, and where the first decision was
 * ready-to-execute or expired it carried no approve card and the click did
 * nothing at all. Both now go to the same place.
 */
class DecisionsSurfaceTest extends TestCase
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

    // ── the surface is wired, and gated ─────────────────────────────────

    public function test_the_decisions_route_exists_and_names_its_own_capability(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/admin/engineer888/decisions'
        );

        $this->assertNotNull($route, 'the canonical Decisions surface must have an endpoint');

        $middleware = $route->gatherMiddleware();

        $this->assertTrue(
            (bool) collect($middleware)->first(fn ($m) => is_string($m) && str_contains($m, 'RequireEngineer888Access')),
            'the route must be gated by the module policy, not by admin alone'
        );
        $this->assertTrue(
            (bool) collect($middleware)->first(fn ($m) => is_string($m) && str_contains($m, 'review_candidate')),
            'it carries candidate identity and live card uuids, so it needs the candidate-reading bar'
        );
        $this->assertTrue(
            (bool) collect($middleware)->first(fn ($m) => is_string($m) && str_contains($m, 'DenyApiKeyAuth')),
            'a machine principal may never read engineering decisions'
        );
    }

    public function test_the_decisions_page_is_registered_behind_the_module_capability(): void
    {
        $pages = config('admin_pages');

        $this->assertArrayHasKey('e888Decisions', $pages);
        $this->assertSame('engineer888/decisions', $pages['e888Decisions']['slug']);
        $this->assertSame('engineer888.discover', $pages['e888Decisions']['capability'],
            'an unauthorised administrator must never receive this slug');
        $this->assertSame('Engineer888', $pages['e888Decisions']['group']);
        $this->assertFileExists(
            resource_path('views/admin/pages/engineer888/decisions.blade.php')
        );
    }

    // ── one source of truth ─────────────────────────────────────────────

    public function test_the_payload_count_is_the_projections_count(): void
    {
        $this->approvedWork('Live', now()->addHour());
        $this->approvedWork('Lapsed', now()->subHour());
        $this->pendingWork('Unjudged');

        $payload = $this->surface();
        $projected = (new DecisionProjection())->count($this->ctx(), $this->projectId);

        $this->assertSame($projected, $payload['count']);
        $this->assertSame($projected, $payload['needs_attention']['total']);
        $this->assertSame(3, $projected);
    }

    public function test_each_decision_lands_in_the_section_its_state_names(): void
    {
        $this->approvedWork('Live', now()->addHour());
        $this->approvedWork('Lapsed', now()->subHour());
        $this->pendingWork('Unjudged');

        $a = $this->surface()['needs_attention'];

        $this->assertSame(['Unjudged'], array_column($a['review_required'], 'title'));
        $this->assertSame(['Live'], array_column($a['ready_to_execute'], 'title'));
        $this->assertSame(['Lapsed'], array_column($a['approval_expired'], 'title'));
    }

    public function test_an_expired_decision_arrives_with_an_explanation_and_no_card(): void
    {
        $this->approvedWork('Lapsed', now()->subHour());

        $item = $this->surface()['needs_attention']['approval_expired'][0];

        $this->assertSame(DecisionState::APPROVAL_EXPIRED, $item['state']);
        $this->assertFalse($item['executable']);
        $this->assertNull($item['execute_card']);
        $this->assertNull($item['review_card']);
        $this->assertNull($item['reject_card']);
        $this->assertStringContainsString('expired before this candidate was executed', (string) $item['explanation']);
        $this->assertSame(
            [DecisionState::ACTION_VIEW_CANDIDATE, DecisionState::ACTION_VIEW_HISTORY],
            $item['actions']
        );
        // The history it carries is real.
        $this->assertSame('Mark', $item['approved_by']);
    }

    // ── the other sections ──────────────────────────────────────────────

    public function test_in_progress_shows_moving_work_and_offers_no_decision(): void
    {
        ['taskId' => $id] = $this->approvedWork('Running now', now()->addHour());
        DB::table('engineering_tasks')->where('id', $id)->update(['status' => 'running']);

        $payload = $this->surface();

        $this->assertSame(['Running now'], array_column($payload['in_progress'], 'title'));
        $this->assertSame('running', $payload['in_progress'][0]['status']);
        $this->assertArrayNotHasKey('execute_card', $payload['in_progress'][0],
            'work already decided is reported, not offered');

        // And it is no longer a decision: the task left the attention set.
        $this->assertSame(0, $payload['needs_attention']['total']);
    }

    public function test_history_holds_decided_work_and_never_the_expired(): void
    {
        ['approvalId' => $rejected] = $this->approvedWork('Refused', now()->addHour());
        DB::table('engineering_candidate_approvals')->where('id', $rejected)->update([
            'state' => ApprovalState::REJECTED, 'approved_at' => null, 'decided_at' => now(),
        ]);

        $this->approvedWork('Lapsed', now()->subHour());

        $payload = $this->surface();
        $historyTitles = array_column($payload['history'], 'title');

        $this->assertContains('Refused', $historyTitles);
        $this->assertNotContains('Lapsed', $historyTitles,
            'an expired approval is still waiting on Boss; "the window closed" is not a decision anybody made');
        $this->assertSame(['Lapsed'], array_column($payload['needs_attention']['approval_expired'], 'title'));
    }

    public function test_a_completed_task_reads_completed_in_history(): void
    {
        ['taskId' => $id] = $this->approvedWork('Shipped', now()->addHour());
        DB::table('engineering_tasks')->where('id', $id)->update(['status' => 'completed']);

        $history = $this->surface()['history'];

        $this->assertSame(['Shipped'], array_column($history, 'title'));
        $this->assertSame(DecisionState::COMPLETED, $history[0]['state']);
    }

    // ── it changes nothing ──────────────────────────────────────────────

    public function test_reading_the_surface_writes_nothing(): void
    {
        $this->approvedWork('Lapsed', now()->subHour());
        $this->approvedWork('Live', now()->addHour());
        $this->pendingWork('Unjudged');

        $before = [
            'approvals'  => DB::table('engineering_candidate_approvals')->get()->toArray(),
            'candidates' => DB::table('engineering_candidates')->count(),
            'tasks'      => DB::table('engineering_tasks')->get()->toArray(),
            'cards'      => DB::table('e888_action_cards')->count(),
        ];

        $this->surface();
        $this->surface();   // and again: reading twice must be identical

        $this->assertEquals($before['approvals'], DB::table('engineering_candidate_approvals')->get()->toArray());
        $this->assertSame($before['candidates'], DB::table('engineering_candidates')->count());
        $this->assertEquals($before['tasks'], DB::table('engineering_tasks')->get()->toArray());
        $this->assertSame($before['cards'], DB::table('e888_action_cards')->count(),
            'the Decisions surface issues no cards; only the workflow may');
    }

    // ── scope ───────────────────────────────────────────────────────────

    public function test_project_all_widens_the_scope_without_changing_the_rule(): void
    {
        $other = DB::table('engineering_projects')->insertGetId([
            'company' => 'Fixture Co', 'key' => 'other-project', 'name' => 'Other Project',
            'repository_path' => sys_get_temp_dir() . '/e888-other-' . getmypid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->pendingWork('Mine');
        $this->pendingWork('Theirs', $other);

        $scoped = $this->surface($this->projectId);
        $all    = $this->surface('all');

        $this->assertSame(1, $scoped['count']);
        $this->assertSame(2, $all['count']);
        $this->assertNull($all['scope']['project_id']);
    }

    // ── the header opens what it counted ────────────────────────────────

    public function test_the_chat_header_navigates_to_the_canonical_surface(): void
    {
        $chat = file_get_contents(resource_path('views/admin/pages/engineer888/chat.blade.php'));

        $this->assertStringContainsString("'/admin/engineer888/decisions'", $chat,
            'the badge must open the surface it counted');
        $this->assertStringNotContainsString('var g = DECISIONS[0];', $chat,
            'the old client-side grouping could open one decision and hide the rest');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function surface(int|string|null $project = null): array
    {
        $request = Request::create('/api/admin/engineer888/decisions', 'GET',
            $project === null ? [] : ['project' => (string) $project]);
        $request->setUserResolver(fn () => User::find(1));
        $request->attributes->set('auth_via', 'jwt');

        $response = app(Engineer888DecisionsController::class)->index(
            $request, new DecisionProjection(), new DecisionPresentationResolver()
        );

        return json_decode($response->getContent(), true);
    }

    /** @return array{taskId:int,candidateUuid:string,approvalId:int} */
    private function approvedWork(string $title, ?\DateTimeInterface $expiresAt): array
    {
        ['taskId' => $taskId, 'candidateId' => $cid, 'candidateUuid' => $uuid] = $this->candidate($title, $this->projectId);

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

    private function pendingWork(string $title, ?int $projectId = null): void
    {
        $projectId ??= $this->projectId;
        ['taskId' => $taskId, 'candidateId' => $cid, 'candidateUuid' => $uuid] = $this->candidate($title, $projectId);

        DB::table('engineering_candidate_approvals')->insert([
            'candidate_id' => $cid, 'candidate_uuid' => $uuid, 'task_id' => $taskId,
            'project_id' => $projectId, 'state' => ApprovalState::PENDING,
            'fingerprint' => hash('sha256', $uuid),
            'binding' => json_encode(['version' => 'e888-approval-v1', 'candidate_uuid' => $uuid]),
            'approved_paths' => json_encode([]), 'approved_hashes' => json_encode([]),
            'provider' => 'scripted', 'model' => 'fixture',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{taskId:int,candidateId:int,candidateUuid:string} */
    private function candidate(string $title, int $projectId): array
    {
        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $projectId,
            'title' => $title, 'description' => 'Fixture for ' . $title,
            'status' => 'blocked', 'current_stage' => 'REQUEST_APPROVAL',
            'session' => 'decisions-surface', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) \Illuminate\Support\Str::uuid();

        $cid = DB::table('engineering_candidates')->insertGetId([
            'uuid' => $uuid, 'task_id' => $taskId, 'project_id' => $projectId,
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
            'company' => 'Fixture Co', 'key' => 'decisions-surface', 'name' => 'Decisions Surface',
            'repository_path' => sys_get_temp_dir() . '/e888-dec-' . getmypid(),
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

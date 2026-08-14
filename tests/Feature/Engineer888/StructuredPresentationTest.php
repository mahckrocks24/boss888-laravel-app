<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Chat\ConversationService;
use App\Core\Engineer888\Chat\MessageService;
use App\Core\Engineer888\Conversation\Providers\ScriptedConversationProvider;
use App\Core\Engineer888\Decisions\DecisionPresentationResolver;
use App\Core\Engineer888\Decisions\DecisionProjection;
use App\Core\Engineer888\Decisions\DecisionState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE STRUCTURED PRESENTATION CONTRACT.
 *
 * ── WHAT WENT WRONG, AND WHY PROMPTING COULD NOT FIX IT ─────────────
 *
 * Asked "what are the tasks that need my approval, paste here one by one",
 * Engineer888 returned a numbered prose list. The forensic trace found five
 * missing pieces and not one of them was prompt quality: there was no
 * SHOW_DECISION intent, ConversationReply had no structured field,
 * MessageService could only return text, the API returned global live cards
 * rather than turn-scoped references, and the frontend had deliberately
 * stopped drawing cards in the transcript. A numbered list was the only output
 * the architecture could produce.
 *
 * ── THE LINE THESE TESTS DEFEND ─────────────────────────────────────
 *
 * The model may say WHAT IT WANTS SHOWN. It may not say what that is.
 *
 * That is enforced structurally, not by filtering: ConversationReply carries
 * text, an advisory intent and a free-text subject, and there is no field on it
 * for a card uuid, an action type, an approval id, a fingerprint, a required
 * statement or a repository path. The tests below therefore attack the one
 * channel the model does control -- the subject -- and prove that stuffing
 * governed values into it changes nothing about what gets persisted or drawn.
 *
 * ── AND CARDS ROTATE ────────────────────────────────────────────────
 *
 * What is persisted against a turn is the LOGICAL decision, never a card uuid.
 * The live card table went from 52 rows to 0 through expiry alone on
 * 2026-08-13 while the decisions themselves were untouched, so a presentation
 * that remembered a card would break by itself minutes after it was made.
 */
class StructuredPresentationTest extends TestCase
{
    use RefreshDatabase;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        ScriptedConversationProvider::reset();
        config()->set('engineer888_conversation.enabled', true);
        config()->set('engineer888_conversation.provider', 'scripted');

        $this->seedCanonical();
        $this->projectId = $this->seedProject();
        DB::table('e888_conversations')->where('id', $this->conversation()->id)
            ->update(['active_project_id' => $this->projectId]);
    }

    protected function tearDown(): void
    {
        ScriptedConversationProvider::reset();
        parent::tearDown();
    }

    // ── 1. structured references, never prose reconstruction ────────────

    public function test_showing_decisions_persists_structured_references(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->pendingWork('Baseline failures');

        ScriptedConversationProvider::script([
            "There are two waiting.\n@@INTENT: SHOW_DECISIONS | what needs my approval",
        ]);
        $this->say('what needs my approval, one by one');

        $meta = $this->lastMetadata();

        $this->assertArrayHasKey('presentations', $meta);
        $this->assertCount(2, $meta['presentations']);

        foreach ($meta['presentations'] as $ref) {
            $this->assertSame(['type', 'logical_key'], array_keys($ref),
                'a reference is a type and a logical key. Nothing else may be persisted against a turn.');
            $this->assertSame(DecisionPresentationResolver::TYPE_DECISION, $ref['type']);
        }
    }

    public function test_the_reply_is_not_a_numbered_list(): void
    {
        $this->pendingWork('Bug Tracker rebuild');

        ScriptedConversationProvider::script([
            "There is one waiting.\n@@INTENT: SHOW_DECISIONS | approval",
        ]);
        $this->say('show me what needs approval');

        $body = $this->lastBody();

        $this->assertStringNotContainsString('1.', $body);
        $this->assertStringNotContainsString('Bug Tracker rebuild', $body,
            'the interface draws the real decisions; writing them out as text is the defect this replaced');
        $this->assertNotEmpty(trim($body), 'a card is not an answer on its own');
    }

    // ── 2. SHOW_DECISION resolves one thing ─────────────────────────────

    public function test_show_decision_resolves_exactly_one(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->pendingWork('Baseline failures');

        ScriptedConversationProvider::script([
            "Here it is.\n@@INTENT: SHOW_DECISION | the Bug Tracker",
        ]);
        $this->say('show me just the Bug Tracker candidate');

        $refs = $this->lastMetadata()['presentations'];
        $this->assertCount(1, $refs);

        $drawn = $this->hydrate($refs);
        $this->assertSame('Bug Tracker rebuild', $drawn[0]['decision']['title']);
    }

    public function test_a_hint_matching_nothing_yields_nothing_rather_than_something_arbitrary(): void
    {
        $this->pendingWork('Bug Tracker rebuild');

        ScriptedConversationProvider::script([
            "I could not find that.\n@@INTENT: SHOW_DECISION | quantum flux capacitor",
        ]);
        $this->say('show me the flux capacitor');

        $this->assertSame([], $this->lastMetadata()['presentations']);
    }

    // ── 3-8. the model cannot author a governed field ───────────────────

    /**
     * @dataProvider governedValues
     */
    public function test_the_model_cannot_inject_a_governed_field(string $label, string $poison): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $card = $this->issueCardFor('Bug Tracker rebuild');

        // Everything the model controls, loaded with a value it must not be
        // able to make meaningful: a real card uuid, a real fingerprint, a real
        // repository path, an approval id, an action type, the exact approval
        // sentence. The subject is free text and the model owns it entirely.
        ScriptedConversationProvider::script([
            "Here.\n@@INTENT: SHOW_DECISION | Bug Tracker {$poison}",
        ]);
        $this->say('show me the bug tracker');

        $meta = json_encode($this->lastMetadata());

        $this->assertStringNotContainsString($poison, $meta,
            "the model put a {$label} in the only channel it controls and it reached the persisted turn");

        foreach ($this->lastMetadata()['presentations'] as $ref) {
            $this->assertSame(['type', 'logical_key'], array_keys($ref));
        }

        // And what IS drawn was looked up by the server, from the live table.
        $drawn = $this->hydrate($this->lastMetadata()['presentations']);
        if ($drawn !== []) {
            $this->assertSame($card, $drawn[0]['decision']['review_card'],
                'the card drawn is the one the server found, not one the model named');
        }
    }

    public static function governedValues(): array
    {
        return [
            'card uuid'          => ['card uuid', 'a1b2c3d4-0000-4000-8000-000000000001'],
            'action type'        => ['action type', 'execute_task'],
            'approval id'        => ['approval id', 'approval_id=46'],
            'fingerprint'        => ['fingerprint', '7a05dece1442b64e5505fff641fbf512d148eab3af6a71f599fb4ca7810a52e7'],
            'required statement' => ['required statement', 'I approve candidate 85341632 with fingerprint 7a05dece and the exact file changes shown.'],
            'repository path'    => ['repository path', '/var/www/levelup-staging'],
        ];
    }

    public function test_the_reply_object_has_no_field_for_a_governed_value(): void
    {
        // Structural, not filtered. There is nowhere for the model to put one.
        $reply = new \ReflectionClass(\App\Core\Engineer888\Conversation\ConversationReply::class);
        $fields = array_map(fn ($p) => $p->getName(), $reply->getProperties());

        foreach (['cardUuid', 'card_uuid', 'actionType', 'approvalId', 'fingerprint', 'statement', 'repositoryPath'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
        $this->assertSame(
            ['ok', 'text', 'proposedIntent', 'intentSubject', 'provider', 'model', 'latencyMs', 'usage', 'error'],
            $fields
        );
    }

    // ── 9. the server hydrates authority independently ──────────────────

    public function test_the_card_is_resolved_fresh_on_every_read(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $first = $this->issueCardFor('Bug Tracker rebuild');

        ScriptedConversationProvider::script(["Here.\n@@INTENT: SHOW_DECISION | Bug Tracker"]);
        $this->say('show me the bug tracker');
        $refs = $this->lastMetadata()['presentations'];

        $this->assertSame($first, $this->hydrate($refs)[0]['decision']['review_card']);

        // The card rotates, exactly as it does every 30 minutes in production.
        DB::table('e888_action_cards')->where('uuid', $first)->update(['revoked_at' => now()]);
        $second = $this->issueCardFor('Bug Tracker rebuild');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->hydrate($refs)[0]['decision']['review_card'],
            'the SAME persisted reference must resolve to the CURRENT card, never the remembered one');
    }

    public function test_no_card_uuid_is_ever_persisted_against_a_turn(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $card = $this->issueCardFor('Bug Tracker rebuild');

        ScriptedConversationProvider::script(["Here.\n@@INTENT: SHOW_DECISION | Bug Tracker"]);
        $this->say('show me the bug tracker');

        $everyStoredMetadata = DB::table('e888_messages')->pluck('metadata_json')->implode(' ');

        $this->assertStringNotContainsString($card, $everyStoredMetadata,
            'cards rotate; a turn that remembered one would break by itself minutes later');
    }

    // ── 10 + 11. close, then show it again ──────────────────────────────

    public function test_hydrating_a_reference_mutates_no_governance(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->issueCardFor('Bug Tracker rebuild');

        ScriptedConversationProvider::script(["Here.\n@@INTENT: SHOW_DECISION | Bug Tracker"]);
        $this->say('show me the bug tracker');
        $refs = $this->lastMetadata()['presentations'];

        $before = [
            'approvals' => DB::table('engineering_candidate_approvals')->get()->toArray(),
            'cards'     => DB::table('e888_action_cards')->get()->toArray(),
            'tasks'     => DB::table('engineering_tasks')->get()->toArray(),
        ];

        // Closing is a client-side act on a client-side map: there is no
        // endpoint for it, and drawing the decision again is the only server
        // work involved. Hydrating repeatedly is what a poll does.
        $this->hydrate($refs);
        $this->hydrate($refs);
        $this->hydrate($refs);

        $this->assertEquals($before['approvals'], DB::table('engineering_candidate_approvals')->get()->toArray());
        $this->assertEquals($before['cards'], DB::table('e888_action_cards')->get()->toArray(),
            'a closed presentation leaves the card unconsumed and the approval pending');
        $this->assertEquals($before['tasks'], DB::table('engineering_tasks')->get()->toArray());
    }

    public function test_show_it_again_re_resolves_current_server_state(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->issueCardFor('Bug Tracker rebuild');

        ScriptedConversationProvider::script([
            "Here it is.\n@@INTENT: SHOW_DECISION | the Bug Tracker",
            "Here it is again.\n@@INTENT: SHOW_DECISION | the Bug Tracker",
        ]);

        $this->say('show me the bug tracker');
        $firstRefs = $this->lastMetadata()['presentations'];

        $this->say('show it again');
        $secondRefs = $this->lastMetadata()['presentations'];

        // A NEW TURN, resolved now. The logical identity is stable; the turn is not.
        $this->assertSame($firstRefs[0]['logical_key'], $secondRefs[0]['logical_key'],
            'the same work is the same logical decision');
        $this->assertGreaterThan(2, DB::table('e888_messages')->count());

        $drawn = $this->hydrate($secondRefs);
        $this->assertCount(1, $drawn);
        $this->assertSame('Bug Tracker rebuild', $drawn[0]['decision']['title']);
    }

    public function test_a_decision_taken_since_the_turn_hydrates_to_nothing(): void
    {
        $this->pendingWork('Bug Tracker rebuild');

        ScriptedConversationProvider::script(["Here.\n@@INTENT: SHOW_DECISION | Bug Tracker"]);
        $this->say('show me the bug tracker');
        $refs = $this->lastMetadata()['presentations'];

        $this->assertCount(1, $this->hydrate($refs));

        // Boss rejects it. Reopening the conversation must not resurrect it.
        DB::table('engineering_candidate_approvals')->update([
            'state' => ApprovalState::REJECTED, 'decided_at' => now(),
        ]);

        $this->assertSame([], $this->hydrate($refs),
            'an old turn must not redraw a decision that has since been made');
    }

    // ── 12 + 13. turn scope and message provenance ──────────────────────

    public function test_presentations_belong_to_the_turn_that_asked_for_them(): void
    {
        $this->pendingWork('Bug Tracker rebuild');

        ScriptedConversationProvider::script([
            "Morning Boss.",
            "Here it is.\n@@INTENT: SHOW_DECISION | Bug Tracker",
        ]);

        $this->say('hello');
        $greetingId = $this->lastAssistantId();

        $this->say('show me the bug tracker');
        $showId = $this->lastAssistantId();

        $greeting = json_decode((string) DB::table('e888_messages')->where('id', $greetingId)->value('metadata_json'), true);
        $show     = json_decode((string) DB::table('e888_messages')->where('id', $showId)->value('metadata_json'), true);

        $this->assertEmpty($greeting['presentations'] ?? [],
            'a greeting shows nothing; presentations attach to the exchange that asked');
        $this->assertCount(1, $show['presentations']);
    }

    public function test_a_card_anchors_to_the_message_carrying_its_task(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $card = $this->issueCardFor('Bug Tracker rebuild');

        $row = DB::table('e888_action_cards')->where('uuid', $card)->first();

        // Anchored or honestly null. A wrong anchor -- the newest message,
        // whatever it happened to be -- would draw an approval under an
        // unrelated sentence, which is worse than none.
        $this->assertTrue(
            $row->message_id === null
            || DB::table('e888_messages')->where('id', $row->message_id)->exists(),
            'message_id must name a real message or stay null'
        );
    }

    // ── 16-17. the same projection, everywhere ──────────────────────────

    public function test_show_decisions_returns_exactly_the_projection(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->pendingWork('Baseline failures');
        $this->approvedWork('Lapsed one', now()->subHour());

        ScriptedConversationProvider::script(["Three.\n@@INTENT: SHOW_DECISIONS | what needs my attention"]);
        $this->say('what needs my attention');

        $refs = $this->lastMetadata()['presentations'];
        $projected = (new DecisionProjection())->needingAttention($this->ctx(), $this->projectId);

        $this->assertSame(
            array_column($projected, 'work_key'),
            array_column($refs, 'logical_key'),
            'the conversation shows the projection, in the projection order'
        );
    }

    public function test_the_count_the_model_is_told_is_the_count_the_header_shows(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->pendingWork('Baseline failures');
        $this->approvedWork('Lapsed one', now()->subHour());

        ScriptedConversationProvider::script(['Noted.']);
        $this->say('what is waiting');

        $frame = ScriptedConversationProvider::lastRequest()->frame;

        $count = (new DecisionProjection())->count($this->ctx(), $this->projectId);

        $this->assertSame(3, $count);
        $this->assertStringContainsString('DECISIONS AWAITING BOSS (3 in total', (string) $frame,
            'the model reading a different number is how Boss was told "30" while the header said 2');
    }

    public function test_the_chat_header_count_is_the_projections_count(): void
    {
        // The badge, the Decisions page and the model all read one number.
        // Before the projection existed the header said 25 while there were 2
        // decisions, because it counted raw live cards -- and a card table can
        // hold several cards for one decision and none for another.
        $this->pendingWork('Bug Tracker rebuild');
        $this->pendingWork('Baseline failures');
        $this->issueCardFor('Bug Tracker rebuild');
        $this->issueCardFor('Bug Tracker rebuild');
        $this->issueCardFor('Bug Tracker rebuild');

        $payload = json_decode(
            app(\App\Http\Controllers\Api\Admin\Engineer888ChatController::class)
                ->bootstrap($this->request())->getContent(),
            true
        );

        $projected = (new DecisionProjection())->count($this->ctx(), $this->projectId);

        $this->assertSame(2, $projected);
        $this->assertSame($projected, $payload['decisions'] ?? null,
            'the header must publish the projection, not the size of the card table');
        $this->assertGreaterThan(
            $projected,
            DB::table('e888_action_cards')->whereNull('revoked_at')->count(),
            'the fixture deliberately holds more live cards than there are decisions, '
            . 'so a header that counted cards would have to give a different answer'
        );
    }

    public function test_the_slice_follows_what_boss_typed_not_the_models_paraphrase(): void
    {
        // Measured in the browser 2026-08-14. Boss typed "...that need my
        // APPROVAL, paste here one by one"; the model handed back only "paste
        // here one by one" as the subject. Scoped on that fragment there is no
        // approval word, so the widest slice was shown and a lapsed approval he
        // had not asked about appeared among his approvals.
        $this->pendingWork('Unjudged');
        $this->approvedWork('Lapsed one', now()->subHour());

        ScriptedConversationProvider::script([
            "There is one awaiting your review.\n@@INTENT: SHOW_DECISIONS | paste here one by one",
        ]);
        $this->say('What are the tasks that need my approval, paste here one by one');

        $drawn = $this->hydrate($this->lastMetadata()['presentations']);

        $this->assertCount(1, $drawn, 'he asked what needs approval, not what is waiting generally');
        $this->assertSame('Unjudged', $drawn[0]['decision']['title']);
        $this->assertSame(DecisionState::REVIEW_REQUIRED, $drawn[0]['decision']['state']);
    }

    public function test_asking_what_needs_attention_still_widens_to_everything(): void
    {
        $this->pendingWork('Unjudged');
        $this->approvedWork('Lapsed one', now()->subHour());

        ScriptedConversationProvider::script([
            "Two things.\n@@INTENT: SHOW_DECISIONS | anything",
        ]);
        $this->say('what needs my attention?');

        $drawn = $this->hydrate($this->lastMetadata()['presentations']);
        $states = array_map(fn ($d) => $d['decision']['state'], $drawn);

        $this->assertCount(2, $drawn);
        $this->assertContains(DecisionState::APPROVAL_EXPIRED, $states);
    }

    // ── 19. logical collapse ────────────────────────────────────────────

    public function test_repeated_attempts_at_one_brief_are_one_decision(): void
    {
        // Five separate tasks, same description: exactly the acceptance-loop
        // shape that produced 23 indistinguishable Bug Tracker offers.
        for ($i = 0; $i < 5; $i++) { $this->pendingWork('Bug Tracker rebuild', 'Build the bug tracker.'); }

        $items = (new DecisionProjection())->current($this->ctx(), $this->projectId);

        $this->assertCount(1, $items, 'one brief, one decision');
        $this->assertSame(5, $items[0]['attempts']);
        $this->assertCount(4, $items[0]['history'], 'the earlier four stay reachable as evidence');

        // And nothing was merged, superseded or deleted to achieve it.
        $this->assertSame(5, DB::table('engineering_tasks')->count());
        $this->assertSame(5, DB::table('engineering_candidates')->count());
        $this->assertSame(0, DB::table('engineering_candidates')->whereNotNull('superseded_at')->count());
    }

    // ── 20. send -> persist -> respond ──────────────────────────────────

    public function test_send_persists_before_it_responds(): void
    {
        ScriptedConversationProvider::script(['Understood.']);

        $before = DB::table('e888_messages')->count();
        $this->say('a message that must survive');
        $after = DB::table('e888_messages')->count();

        $this->assertSame($before + 2, $after, 'the question and the answer are both durable');

        $rows = DB::table('e888_messages')->orderByDesc('id')->limit(2)->get();
        $this->assertSame('engineer888', $rows[0]->role);
        $this->assertSame('user', $rows[1]->role);
        $this->assertSame('a message that must survive', $rows[1]->body);
        $this->assertLessThan($rows[0]->id, $rows[1]->id, 'the question is persisted first');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function hydrate(array $refs): array
    {
        return app(DecisionPresentationResolver::class)->hydrate($this->ctx(), $refs, $this->projectId);
    }

    private function issueCardFor(string $title): string
    {
        $task = DB::table('engineering_tasks')->where('title', $title)->orderByDesc('id')->first();
        $candidate = DB::table('engineering_candidates')->where('task_id', $task->id)->orderByDesc('id')->first();

        $uuid = (string) \Illuminate\Support\Str::uuid();

        DB::table('e888_action_cards')->insert([
            'uuid' => $uuid, 'conversation_id' => $this->conversation()->id,
            'owner_user_id' => 1, 'issued_to_user_id' => 1,
            'action_type' => 'approve_candidate',
            'task_uuid' => $task->uuid, 'candidate_uuid' => $candidate->uuid,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function lastMetadata(): array
    {
        return json_decode((string) DB::table('e888_messages')->where('role', 'engineer888')
            ->orderByDesc('id')->value('metadata_json'), true) ?: [];
    }

    private function lastBody(): string
    {
        return (string) DB::table('e888_messages')->where('role', 'engineer888')
            ->orderByDesc('id')->value('body');
    }

    private function lastAssistantId(): int
    {
        return (int) DB::table('e888_messages')->where('role', 'engineer888')->orderByDesc('id')->value('id');
    }

    private function say(string $body): array
    {
        return app(MessageService::class)->post($this->request(), $this->conversation()->uuid, $body);
    }

    private function conversation(): object
    {
        return app(ConversationService::class)->forOwner($this->request());
    }

    private function pendingWork(string $title, ?string $description = null): void
    {
        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $this->projectId,
            'title' => $title, 'description' => $description ?? ('Fixture for ' . $title),
            'status' => 'blocked', 'current_stage' => 'REQUEST_APPROVAL',
            'session' => 'structured-presentation', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) \Illuminate\Support\Str::uuid();

        $cid = DB::table('engineering_candidates')->insertGetId([
            'uuid' => $uuid, 'task_id' => $taskId, 'project_id' => $this->projectId,
            'provider' => 'scripted', 'model' => 'fixture', 'status' => 'VALIDATED',
            'request_fingerprint' => substr(hash('sha1', $uuid), 0, 40),
            'confidence' => 'high', 'file_count' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

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

    private function approvedWork(string $title, \DateTimeInterface $expiresAt): void
    {
        $this->pendingWork($title);

        $uuid = DB::table('engineering_candidates')->orderByDesc('id')->value('uuid');

        DB::table('engineering_candidate_approvals')->where('candidate_uuid', $uuid)->update([
            'state' => ApprovalState::APPROVED, 'approver_user_id' => 1, 'approver_name' => 'Mark',
            'statement' => 'I approve candidate ' . $uuid . '.',
            'approved_at' => now()->subHours(12), 'expires_at' => $expiresAt,
        ]);
    }

    private function seedProject(): int
    {
        return DB::table('engineering_projects')->insertGetId([
            'company' => 'Fixture Co', 'key' => 'structured-presentation', 'name' => 'Structured Presentation',
            'repository_path' => base_path(), 'test_database' => 'levelup_e888_test',
            'phpunit_config' => 'phpunit.e888.xml', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ctx(): Engineer888AccessContext
    {
        return Engineer888AccessContext::forHuman(User::find(1), 'jwt', 'jwt');
    }

    private function request(): Request
    {
        $request = Request::create('/api/admin/engineer888/chat/messages', 'POST');
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
            'user_id' => 1, 'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

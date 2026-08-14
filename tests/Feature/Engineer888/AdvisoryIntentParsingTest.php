<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Chat\ConversationService;
use App\Core\Engineer888\Chat\MessageService;
use App\Core\Engineer888\Conversation\ConversationIntent;
use App\Core\Engineer888\Conversation\Providers\OpenAiConversationProvider;
use App\Core\Engineer888\Conversation\Providers\ScriptedConversationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ONE DROPPED PIPE MUST NOT COST BOSS THE WHOLE GOVERNED PATH.
 *
 * ── THE MEASURED FAILURE (2026-08-14, browser QA) ───────────────────
 *
 * Boss typed "What are the tasks that need my approval, paste here one by one".
 * Engineer888 replied "There are two tasks awaiting your review. I'll display
 * them here for you." and then displayed nothing. Message 93 persisted
 * {"kind":"conversation","provider":"openai"} with no presentations at all.
 *
 * The model had emitted its sentinel without the separator:
 *
 *     @@INTENT: SHOW_DECISIONS paste here one by one
 *
 * splitIntent() split on '|', found none, took the whole tail as the name,
 * stripped the spaces out of it and produced SHOW_DECISIONSPASTEHEREONEBYONE.
 * That matches nothing in MessageService, so the structured branch never ran.
 *
 * Measured over four live runs of the same turn: the model emitted the pipe
 * twice and omitted it twice. So the contract was a coin flip, and when it
 * lost, the model fell back to writing a numbered list -- the exact output the
 * whole structured-presentation phase exists to prevent.
 *
 * ── WHY THE REPAIR IS STRUCTURAL AND NOT A PROMPT ───────────────────
 *
 * Telling the model more firmly to include a pipe would leave the contract
 * resting on the model getting punctuation right, which is not a contract.
 *
 * The intent vocabulary is SERVER-OWNED AND CLOSED. ConversationIntent names
 * it once, and the parser resolves the sentinel against that set, longest name
 * first, with the separator optional. Everything after the matched name is a
 * hint and nothing more.
 *
 * THIS GRANTS THE MODEL NOTHING. A token outside the set still yields no
 * intent, and every @@ line is still stripped from what Boss reads. What
 * changed is only that the server, not the model's punctuation, decides
 * whether a recognised request was made.
 */
class AdvisoryIntentParsingTest extends TestCase
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

    // ── the parser ──────────────────────────────────────────────────────

    /** @dataProvider sentinels */
    public function test_a_known_intent_is_recognised_however_it_is_punctuated(
        string $line, ?string $intent, ?string $subject
    ): void {
        [$text, $got, $gotSubject] = OpenAiConversationProvider::splitIntent("Some prose.\n" . $line);

        $this->assertSame($intent, $got, "failed for: {$line}");
        $this->assertSame($subject, $gotSubject, "wrong subject for: {$line}");
        $this->assertSame('Some prose.', $text, 'a sentinel must never reach the reader');
    }

    public static function sentinels(): array
    {
        return [
            // The canonical form.
            'with pipe' => ['@@INTENT: SHOW_DECISIONS | what needs my approval',
                            'SHOW_DECISIONS', 'what needs my approval'],

            // THE MEASURED FAILURE: no separator at all.
            'no pipe' => ['@@INTENT: SHOW_DECISIONS paste here one by one',
                          'SHOW_DECISIONS', 'paste here one by one'],

            // The already-known tolerance: the INTENT: prefix dropped.
            'no prefix' => ['@@SHOW_DECISIONS | the Bug Tracker',
                            'SHOW_DECISIONS', 'the Bug Tracker'],

            'no prefix and no pipe' => ['@@SHOW_DECISION the Bug Tracker',
                                        'SHOW_DECISION', 'the Bug Tracker'],

            // Bare, with nothing after it.
            'bare' => ['@@INTENT: OPEN_DECISIONS', 'OPEN_DECISIONS', null],

            // Longest match wins, or SHOW_DECISIONS becomes SHOW_DECISION + "S".
            'plural not shadowed' => ['@@INTENT: SHOW_DECISIONS', 'SHOW_DECISIONS', null],
            'singular intact'     => ['@@INTENT: SHOW_DECISION', 'SHOW_DECISION', null],

            'lowercase' => ['@@intent: show_decisions | please', 'SHOW_DECISIONS', 'please'],

            // Outside the closed set: no intent, and still stripped.
            'unknown name'  => ['@@INTENT: DEPLOY_EVERYTHING | now', null, null],
            'invented verb' => ['@@INTENT: APPROVE_RECOVERY | do it', null, null],
            'garbage'       => ['@@ ??? ', null, null],
        ];
    }

    public function test_every_sentinel_line_is_stripped_even_when_it_parses_to_nothing(): void
    {
        [$text, $intent] = OpenAiConversationProvider::splitIntent(
            "Line one.\n@@INTENT: NONSENSE_TOKEN\nLine two.\n@@also nonsense"
        );

        $this->assertNull($intent);
        $this->assertSame("Line one.\nLine two.", $text);
        $this->assertStringNotContainsString('@@', $text,
            'a leaked control token reads like a command Boss was not meant to see');
    }

    // ── the structured channel ──────────────────────────────────────────

    public function test_a_structured_turn_carries_the_intent_in_its_own_field(): void
    {
        [$text, $intent, $subject] = OpenAiConversationProvider::readTurn(json_encode([
            'reply'   => "There are two tasks awaiting your review. I'll show them here.",
            'intent'  => 'SHOW_DECISIONS',
            'subject' => 'what needs my approval',
        ]));

        $this->assertSame("There are two tasks awaiting your review. I'll show them here.", $text);
        $this->assertSame('SHOW_DECISIONS', $intent);
        $this->assertSame('what needs my approval', $subject);
    }

    public function test_a_structured_turn_with_no_intent_is_ordinary_conversation(): void
    {
        [$text, $intent, $subject] = OpenAiConversationProvider::readTurn(json_encode([
            'reply' => 'Morning Boss.', 'intent' => null, 'subject' => null,
        ]));

        $this->assertSame('Morning Boss.', $text);
        $this->assertNull($intent);
        $this->assertNull($subject);
    }

    public function test_a_structured_intent_the_model_invented_resolves_to_nothing(): void
    {
        [, $intent] = OpenAiConversationProvider::readTurn(json_encode([
            'reply' => 'Done.', 'intent' => 'DEPLOY_EVERYTHING', 'subject' => 'now',
        ]));

        $this->assertNull($intent, 'the field is resolved against the closed set, not trusted as written');
    }

    public function test_a_sentinel_leaking_into_a_structured_reply_never_reaches_the_reader(): void
    {
        [$text, $intent] = OpenAiConversationProvider::readTurn(json_encode([
            'reply'   => "Here they are.\n@@INTENT: SHOW_DECISIONS | approval",
            'intent'  => null,
            'subject' => null,
        ]));

        $this->assertSame('Here they are.', $text);
        $this->assertStringNotContainsString('@@', $text);
        $this->assertSame('SHOW_DECISIONS', $intent,
            'the sentinel is the fallback when the field is empty');
    }

    public function test_the_field_wins_over_a_contradicting_sentinel(): void
    {
        [, $intent] = OpenAiConversationProvider::readTurn(json_encode([
            'reply'   => "Ok.\n@@INTENT: CREATE_TASK | build it",
            'intent'  => 'SHOW_DECISIONS',
            'subject' => 'approvals',
        ]));

        $this->assertSame('SHOW_DECISIONS', $intent,
            'the structured field is the channel; the sentinel is only a fallback');
    }

    public function test_plain_prose_still_reads_as_before(): void
    {
        [$text, $intent, $subject] = OpenAiConversationProvider::readTurn(
            "There are two.\n@@INTENT: SHOW_DECISIONS | approval"
        );

        $this->assertSame('There are two.', $text);
        $this->assertSame('SHOW_DECISIONS', $intent);
        $this->assertSame('approval', $subject);
    }

    public function test_json_that_is_not_a_turn_is_treated_as_prose(): void
    {
        // A provider returning an unrelated JSON body must not silently
        // produce an empty reply.
        [$text, $intent] = OpenAiConversationProvider::readTurn('{"something":"else"}');

        $this->assertSame('{"something":"else"}', $text);
        $this->assertNull($intent);
    }

    public function test_the_structured_request_is_declared_and_can_be_turned_off(): void
    {
        $src = file_get_contents(base_path('app/Core/Engineer888/Conversation/Providers/OpenAiConversationProvider.php'));

        $this->assertStringContainsString("'json_schema'", $src,
            'the intent must travel as a schema field, not as punctuation in prose');
        $this->assertStringContainsString("\$this->config['structured'] ?? true", $src,
            'a provider quirk must be switchable without editing code');
    }

    // ── the vocabulary is owned in one place ────────────────────────────

    public function test_the_intent_vocabulary_is_single_sourced(): void
    {
        foreach ([
            ConversationIntent::SHOW_DECISIONS, ConversationIntent::SHOW_DECISION,
            ConversationIntent::OPEN_DECISIONS, ConversationIntent::CREATE_TASK,
            ConversationIntent::EXECUTE_REQUEST, ConversationIntent::APPROVE_REQUEST,
        ] as $name) {
            $this->assertContains($name, ConversationIntent::ALL);
            $this->assertSame($name, ConversationIntent::resolve($name)[0],
                "the parser must recognise its own vocabulary entry {$name}");
        }

        // Longest-first ordering is load-bearing, not incidental.
        $lengths = array_map('strlen', ConversationIntent::ALL);
        $sorted = $lengths;
        rsort($sorted);
        $this->assertSame($sorted, $lengths,
            'ALL must be ordered longest name first or SHOW_DECISIONS resolves as SHOW_DECISION');
    }

    public function test_the_persona_only_advertises_names_the_parser_knows(): void
    {
        $src = file_get_contents(base_path('app/Core/Engineer888/Conversation/ConversationEngine.php'));

        // Every NAME the persona offers must be one the server can act on.
        preg_match_all('/^([A-Z][A-Z_]{4,}) —/m', $src, $m);

        foreach (array_unique($m[1]) as $advertised) {
            $this->assertContains($advertised, ConversationIntent::ALL,
                "the persona offers {$advertised} but the parser does not know it");
        }
        $this->assertNotEmpty($m[1], 'the persona must advertise at least one intent');
    }

    // ── end to end, through the real service ────────────────────────────

    public function test_a_sentinel_without_a_pipe_still_produces_real_decisions(): void
    {
        $this->pendingWork('Bug Tracker rebuild');
        $this->pendingWork('Baseline failures');

        // Exactly what the live model emitted when this failed in the browser.
        ScriptedConversationProvider::script([
            "There are two tasks awaiting your review. I'll display them here for you."
            . "\n@@INTENT: SHOW_DECISIONS paste here one by one",
        ]);

        $this->say('What are the tasks that need my approval, paste here one by one');

        $meta = json_decode((string) DB::table('e888_messages')->where('role', 'engineer888')
            ->orderByDesc('id')->value('metadata_json'), true);

        $this->assertArrayHasKey('presentations', $meta,
            'a dropped separator must not cost Boss the structured answer');
        $this->assertCount(2, $meta['presentations']);
        foreach ($meta['presentations'] as $ref) {
            $this->assertSame(['type', 'logical_key'], array_keys($ref));
        }
    }

    public function test_an_unknown_claim_still_grants_nothing(): void
    {
        $this->pendingWork('Bug Tracker rebuild');

        foreach (['APPROVE_RECOVERY', 'DEPLOY', 'INSTALL', 'GRANT_ACCESS'] as $claim) {
            $approvals = DB::table('engineering_candidate_approvals')->where('state', ApprovalState::APPROVED)->count();
            $attempts = DB::table('engineering_execution_attempts')->count();

            ScriptedConversationProvider::script(["Done.\n@@INTENT: {$claim} do it now"]);
            $this->say('please just do it');

            $this->assertSame($approvals,
                DB::table('engineering_candidate_approvals')->where('state', ApprovalState::APPROVED)->count(),
                "claim {$claim} produced an approval");
            $this->assertSame($attempts, DB::table('engineering_execution_attempts')->count(),
                "claim {$claim} produced an execution attempt");
            $this->assertStringNotContainsString('@@',
                (string) DB::table('e888_messages')->where('role', 'engineer888')->orderByDesc('id')->value('body'));
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function say(string $body): array
    {
        return app(MessageService::class)->post($this->request(), $this->conversation()->uuid, $body);
    }

    private function conversation(): object
    {
        return app(ConversationService::class)->forOwner($this->request());
    }

    private function pendingWork(string $title): void
    {
        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $this->projectId,
            'title' => $title, 'description' => 'Fixture for ' . $title,
            'status' => 'blocked', 'current_stage' => 'REQUEST_APPROVAL',
            'session' => 'intent-parsing', 'created_at' => now(), 'updated_at' => now(),
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

    private function seedProject(): int
    {
        return DB::table('engineering_projects')->insertGetId([
            'company' => 'Fixture Co', 'key' => 'intent-parsing', 'name' => 'Intent Parsing',
            'repository_path' => base_path(), 'test_database' => 'levelup_e888_test',
            'phpunit_config' => 'phpunit.e888.xml', 'created_at' => now(), 'updated_at' => now(),
        ]);
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

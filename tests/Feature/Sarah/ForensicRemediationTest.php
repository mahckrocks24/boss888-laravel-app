<?php

namespace Tests\Feature\Sarah;

use App\Connectors\RuntimeClient;
use App\Core\Orchestration\SarahReadBackService;
use App\Core\Sarah888\ReadToolPromotion;
use App\Core\Sarah888\RouterIntent;
use App\Core\Sarah888\UnfulfilledPromiseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * REPORT-0024 remediation (2026-09-01) — the smallest safe fixes for SF-01, SF-05, SF-02(Laravel half), SF-03,
 * SF-04 and SF-09, each pinned from the transcript or the ledger row that exposed it.
 *
 * Every test here is a control against a measured defect, not an invented example:
 *   - 8,454-token reasoning prompts fell back to gpt-4o-mini 58-81% of the time on the 30s lane   (SF-03)
 *   - "Hi Sarah." cost a classifier model call and was then treated as a strategy request           (SF-04/SF-02)
 *   - five ~256-token read-back calls opened every Chef Red turn                                     (SF-04)
 *   - "I'll fetch the list of pages … Please hold on … I couldn't start this" — nothing ran          (SF-05)
 *   - platform.list_pages exists as a read tool and was refused as an unmapped task                  (SF-05)
 *   - the Runtime read workspace 1's context for every workspace; the id must travel explicitly     (SF-01)
 */
class ForensicRemediationTest extends TestCase
{
    private function runtimeConfigured(): void
    {
        foreach (['RUNTIME_URL' => 'https://runtime.test', 'RUNTIME_SECRET' => 'test-secret'] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
    }

    private function aiRunOk(): array
    {
        return ['success' => true, 'output' => '{"reply":"ok"}', 'parsed' => ['reply' => 'ok'], 'duration_ms' => 5,
                'requested_provider' => 'deepseek', 'actual_provider' => 'deepseek', 'requested_model' => 'deepseek-v4-flash',
                'actual_model' => 'deepseek-v4-flash', 'fallback_used' => false, 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]];
    }

    /*──────────────────────────── SF-03: the lane follows the prompt size */

    public function test_a_large_chat_json_prompt_declares_the_synthesis_workload(): void
    {
        $this->runtimeConfigured();
        Http::fake(['*/ai/run' => Http::response($this->aiRunOk(), 200)]);

        $system = str_repeat('Authoritative workspace facts. ', 700); // ~22k chars ≈ the measured 8k-token prompts
        (new RuntimeClient())->chatJson($system, 'User: what should I focus on? Reply with JSON.', ['workspace_id' => 999993], 1800);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/ai/run') && ($req['workload'] ?? null) === 'synthesis');
    }

    public function test_a_small_chat_json_prompt_keeps_the_interactive_lane(): void
    {
        $this->runtimeConfigured();
        Http::fake(['*/ai/run' => Http::response($this->aiRunOk(), 200)]);

        (new RuntimeClient())->chatJson('Decide STATUS or ANALYSIS. Reply with JSON.', 'MESSAGE: what failed?', ['workspace_id' => 999993], 120);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/ai/run') && !array_key_exists('workload', $req->data()));
    }

    /*──────────────────────────── SF-01: workspace identity is explicit on the assistant contract */

    public function test_the_assistant_request_carries_the_workspace_id_at_the_top_level(): void
    {
        $this->runtimeConfigured();
        Http::fake(['*/internal/assistant' => Http::response(['response' => 'hello'], 200)]);

        (new RuntimeClient())->assistant('Hi Sarah.', ['workspace_id' => 999993, 'business_name' => 'Fable QA Bakery'], 'agent_chat_ws_999993_sarah_v7', 'dmm');

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/internal/assistant')
            && ($req['workspace_id'] ?? null) === 999993
            && (($req['context']['workspace_id'] ?? null) === 999993));
    }

    /*──────────────────────────── SF-04: a greeting is not a model call */

    public function test_a_greeting_is_classified_without_a_model_call(): void
    {
        Http::fake();
        $verdict = app(RouterIntent::class)->classify('Hi Sarah.', 999993);

        $this->assertSame(RouterIntent::STATUS, $verdict['mode']);
        $this->assertSame('deterministic', $verdict['source']);
        Http::assertNothingSent();
    }

    public function test_a_real_question_still_goes_to_the_classifier(): void
    {
        $this->assertFalse(RouterIntent::isTrivialTurn('Hi Sarah, why did the audit fail?'));
        $this->assertFalse(RouterIntent::isTrivialTurn('What should I focus on this week?'));
        $this->assertTrue(RouterIntent::isTrivialTurn('Hi Sarah.'));
        $this->assertTrue(RouterIntent::isTrivialTurn('Thanks!'));
        $this->assertTrue(RouterIntent::isTrivialTurn('Good morning Sarah'));
    }

    /*──────────────────────────── SF-04: read-back on the chat path makes no model call */

    public function test_chat_read_back_renders_completed_tasks_without_a_model_call(): void
    {
        $this->runtimeConfigured();
        Http::fake();
        $ws = $this->workspace();
        $taskId = $this->completedTask($ws, ['data' => ['inserted_count' => 3, 'pages' => 12]]);

        $block = app(SarahReadBackService::class)->renderInsightsBlock($ws, 5, false);

        $this->assertStringContainsString("task #{$taskId}", $block);
        $this->assertStringContainsString('completed seo/fix_orphans', $block);
        $this->assertStringContainsString('inserted count 3', $block);
        Http::assertNothingSent();
        $this->assertNotNull(DB::table('tasks')->where('id', $taskId)->value('sarah_read_at'), 'chat still marks the task read');
    }

    /*──────────────────────────── SF-05: promises nothing backs are removed; truthful text stays */

    public function test_the_transcript_promise_is_removed_when_nothing_ran(): void
    {
        $reply = "I’ll fetch the list of pages for Fable QA Cafe Two now. Please hold on a moment while I get that information for you. "
               . "I couldn't start this: • that action isn't available yet";
        $r = app(UnfulfilledPromiseGuard::class)->validate($reply, false);

        $this->assertTrue($r['corrected']);
        $this->assertStringNotContainsString('fetch the list', $r['reply']);
        $this->assertStringNotContainsString('hold on', $r['reply']);
        $this->assertStringContainsString("I couldn't start this", $r['reply'], 'the truthful refusal survives');
    }

    public function test_a_promise_that_something_backs_is_left_alone(): void
    {
        $reply = "I'll get James started on the audit now. This uses 2 credits.";
        $r = app(UnfulfilledPromiseGuard::class)->validate($reply, true);
        $this->assertFalse($r['corrected']);
        $this->assertSame($reply, $r['reply']);
    }

    public function test_invitations_and_stated_limits_are_not_promises(): void
    {
        $reply = "I can't fetch pages from here yet. Let me know if you want me to queue a site audit instead.";
        $r = app(UnfulfilledPromiseGuard::class)->validate($reply, false);
        $this->assertFalse($r['corrected']);
    }

    /** Run 2, turn 6 (20:11:36 UTC): "I'll proceed with listing the pages …" slipped through — "proceed" was not a work verb. */
    public function test_proceed_with_is_a_promise_too(): void
    {
        $reply = "To optimize our approach for Fable QA Bakery, let's focus on three key actions: 1. List Pages: I'll proceed with listing the pages for the site now.";
        $r = app(UnfulfilledPromiseGuard::class)->validate($reply, false);
        $this->assertTrue($r['corrected']);
        $this->assertStringNotContainsString('proceed with listing', $r['reply']);
    }

    public function test_a_reply_that_was_only_a_promise_becomes_a_truthful_line(): void
    {
        $r = app(UnfulfilledPromiseGuard::class)->validate("One moment while I pull that up.", false);
        $this->assertTrue($r['corrected']);
        $this->assertSame(UnfulfilledPromiseGuard::NOTHING_RAN, $r['reply']);
    }

    /*──────────────────────────── SF-05: a read tool named as a task is a lookup, not work */

    public function test_platform_list_pages_emitted_as_a_task_resolves_to_the_read_tool(): void
    {
        $ids = ['platform.list_pages', 'platform.read_page', 'web.fetch', 'write.write_article', 'builder.update_page'];
        $this->assertSame('platform.list_pages', ReadToolPromotion::toolIdFor('platform', 'list_pages', $ids));
        $this->assertSame('platform.list_pages', ReadToolPromotion::toolIdFor('platform.list_pages', 'list_pages', $ids)); // the Runtime's shape
        $this->assertSame('platform.list_pages', ReadToolPromotion::toolIdFor('', 'platform.list_pages', $ids));
        $this->assertSame('web.fetch', ReadToolPromotion::toolIdFor('platform', 'fetch', $ids));
    }

    public function test_work_actions_are_never_promoted(): void
    {
        $ids = ['platform.list_pages', 'write.write_article', 'builder.update_page', 'platform.generate_funnel_blueprint'];
        $this->assertNull(ReadToolPromotion::toolIdFor('write', 'write_article', $ids));
        $this->assertNull(ReadToolPromotion::toolIdFor('builder', 'update_page', $ids));
        $this->assertNull(ReadToolPromotion::toolIdFor('platform', 'generate_funnel_blueprint', $ids), 'generation is not a read');
        $this->assertNull(ReadToolPromotion::toolIdFor('platform', 'list_unicorns', $ids), 'unknown ids stay unmapped');
    }

    /*──────────────────────────── fixtures */

    private function workspace(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'remed-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'remed-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function completedTask(int $ws, array $result): int
    {
        return (int) DB::table('tasks')->insertGetId([
            'workspace_id' => $ws, 'engine' => 'seo', 'action' => 'fix_orphans',
            'status' => 'completed', 'assigned_agents_json' => json_encode(['james']),
            'result_json' => json_encode($result), 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

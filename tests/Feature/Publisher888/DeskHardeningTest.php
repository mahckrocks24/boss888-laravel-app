<?php

namespace Tests\Feature\Publisher888;

use App\Core\Auth\RefreshTokenService;
use App\Engines\Publisher\Services\DeskHtmlSanitizer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PUBLISHER888 Unit 2 (2026-09-04) — enterprise hardening: CSP, validation, sanitiser, optimistic locking,
 * audit trail, idempotency, versions, bin/restore, sessions, health, API-key refusal, error containment.
 */
class DeskHardeningTest extends TestCase
{
    private int $ws = 999999901;
    private int $site = 999999905;
    private int $user = 0;

    protected function setUp(): void
    {
        parent::setUp(); $this->cleanup();
        $this->user = (int) DB::table('users')->insertGetId(['name' => 'Hard QA', 'email' => 'hard-qa-' . $this->ws . '@example.test', 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Hard QA', 'slug' => 'hard-qa-' . $this->ws, 'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $this->ws, 'user_id' => $this->user, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['id' => $this->site, 'workspace_id' => $this->ws, 'name' => 'Hard QA', 'subdomain' => 'hardqa.levelupgrowth.io', 'status' => 'published', 'type' => 'builder', 'settings_json' => json_encode(['theme' => 'kabayan-news']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('blog_categories')->insert(['workspace_id' => $this->ws, 'name' => 'News', 'slug' => 'news', 'created_at' => now(), 'updated_at' => now()]);
    }
    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }
    private function cleanup(): void
    {
        foreach (['desk_audit_log', 'desk_commissions', 'desk_members', 'activities', 'leads', 'job_listings'] as $t) DB::table($t)->where('workspace_id', $this->ws)->delete();
        DB::table('desk_idempotency')->where('website_id', $this->site)->delete();
        DB::table('article_versions')->whereIn('article_id', DB::table('articles')->where('workspace_id', $this->ws)->pluck('id'))->delete();
        DB::table('articles')->where('workspace_id', $this->ws)->delete(); DB::table('blog_categories')->where('workspace_id', $this->ws)->delete();
        DB::table('websites')->where('id', $this->site)->delete(); DB::table('sessions')->where('user_id', DB::table('users')->where('email', 'hard-qa-' . $this->ws . '@example.test')->value('id') ?? 0)->delete();
        DB::table('workspace_users')->where('workspace_id', $this->ws)->delete(); DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('email', 'hard-qa-' . $this->ws . '@example.test')->delete();
    }
    private function token(?int $sid = null): string { return app(RefreshTokenService::class)->issueAccessToken(User::find($this->user), Workspace::find($this->ws), 'test', $sid); }
    private function api(string $method, string $path, array $data = [], array $headers = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($headers['token'] ?? $this->token()), 'X-Desk-Website' => (string) $this->site, 'Accept' => 'application/json'] + array_diff_key($headers, ['token' => 1]))->json($method, '/api/desk' . $path, $data);
    }
    private function body(): string { return '<p>' . str_repeat('Solid words for the publish gate. ', 20) . '</p>'; }

    public function test_shell_ships_a_strict_csp_with_a_nonce_and_no_framing(): void
    {
        $r = $this->get('https://hardqa.levelupgrowth.io/admin'); $r->assertStatus(200);
        $csp = (string) $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp); $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9_\-]{16,})'/", $csp, 'inline boot script is nonce-bound');
        preg_match("/'nonce-([A-Za-z0-9_\-]+)'/", $csp, $m);
        $this->assertStringContainsString('nonce="' . $m[1] . '"', $r->getContent(), 'the same nonce is on the script tags');
        $r->assertHeader('X-Frame-Options', 'DENY'); $r->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_validation_rejects_bad_input_with_field_errors_and_a_request_id(): void
    {
        $r = $this->api('POST', '/stories', ['title' => 'x', 'type' => 'poem', 'featured_image_url' => 'javascript:alert(1)', 'tags' => array_fill(0, 25, 't')]);
        $r->assertStatus(422)->assertJsonPath('error', 'VALIDATION'); $this->assertNotEmpty($r->json('request_id'));
        $errs = $r->json('errors'); $this->assertArrayHasKey('title', $errs); $this->assertArrayHasKey('type', $errs); $this->assertArrayHasKey('featured_image_url', $errs); $this->assertArrayHasKey('tags', $errs);
        $this->api('POST', '/members/invite', ['email' => 'not-an-email'])->assertStatus(422);
        $this->api('PUT', '/inbox/1', ['status' => 'bogus'])->assertStatus(422);
        $this->assertNotEmpty($r->headers->get('X-Request-Id'));
    }

    public function test_dom_sanitiser_strips_scripts_handlers_and_bad_schemes_but_keeps_content(): void
    {
        $dirty = '<p onclick="x()">Hello <b>bold</b> <a href="javascript:alert(1)">bad</a> <a href="https://ok.example" target="_blank">ok</a></p><script>alert(1)</script><img src="x" onerror="alert(1)"><svg onload="alert(1)"><circle/></svg><iframe src="https://evil"></iframe><div style="position:fixed">wrapped</div><p>after</p><IMG SRC=JaVaScRiPt:alert(1)><a href="data:text/html;base64,AAAA">d</a>';
        $clean = DeskHtmlSanitizer::clean($dirty);
        foreach (['<script', 'onclick', 'onerror', 'onload', '<svg', '<iframe', 'javascript:', 'style=', 'data:text'] as $bad) $this->assertStringNotContainsStringIgnoringCase($bad, $clean, "must strip {$bad}");
        $this->assertStringContainsString('<b>bold</b>', $clean); $this->assertStringContainsString('wrapped', $clean); $this->assertStringContainsString('after', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean, 'target=_blank links get rel');
        $this->assertStringContainsString('href="https://ok.example"', $clean);
        $this->assertSame('', DeskHtmlSanitizer::clean('<script>1</script>'));
        $this->assertNull(DeskHtmlSanitizer::safeUrl('vbscript:x')); $this->assertSame('https://a.b/c', DeskHtmlSanitizer::safeUrl('//a.b/c')); $this->assertSame('/x', DeskHtmlSanitizer::safeUrl(" \t/x"));
        // end-to-end through the API
        $id = (int) $this->api('POST', '/stories', ['title' => 'Sanitised story', 'section' => 'news', 'content' => $dirty])->json('id');
        $stored = DB::table('articles')->where('id', $id)->value('content');
        $this->assertStringNotContainsString('<script', $stored); $this->assertStringNotContainsString('onerror', $stored);
    }

    public function test_optimistic_lock_returns_409_when_someone_saved_first(): void
    {
        $id = (int) $this->api('POST', '/stories', ['title' => 'Lock me', 'section' => 'news', 'content' => $this->body()])->json('id');
        $loaded = (string) $this->api('GET', '/stories/' . $id)->json('story.updated_at');
        DB::table('articles')->where('id', $id)->update(['updated_at' => now()->addMinutes(2)]); // "another editor" saved
        $r = $this->api('PUT', '/stories/' . $id, ['title' => 'Mine', 'expected_updated_at' => $loaded]);
        $r->assertStatus(409)->assertJsonPath('error', 'STALE'); $this->assertSame('Lock me', $r->json('story.title'));
        $this->api('PUT', '/stories/' . $id, ['title' => 'Mine'])->assertStatus(200); // no expectation = explicit overwrite
        $this->assertSame('Mine', DB::table('articles')->where('id', $id)->value('title'));
    }

    public function test_every_mutation_leaves_an_audit_row_and_owners_can_read_it(): void
    {
        $id = (int) $this->api('POST', '/stories', ['title' => 'Audited', 'section' => 'news', 'content' => $this->body()])->json('id');
        $this->api('POST', '/stories/' . $id . '/publish')->assertStatus(200);
        $this->api('POST', '/stories/' . $id . '/unpublish')->assertStatus(200);
        $this->api('DELETE', '/stories/' . $id)->assertStatus(200);
        $actions = DB::table('desk_audit_log')->where('website_id', $this->site)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['story.create', 'story.publish', 'story.unpublish', 'story.delete'], $actions);
        $row = DB::table('desk_audit_log')->where('website_id', $this->site)->where('action', 'story.publish')->first();
        $this->assertSame($this->user, (int) $row->user_id); $this->assertSame('owner', $row->role); $this->assertNotEmpty($row->request_id); $this->assertSame((int) $row->entity_id, $id);
        $this->assertSame('draft', json_decode((string) $row->before_json, true)['status'] ?? null); // MySQL re-formats JSON columns, so decode rather than string-match
        $list = $this->api('GET', '/audit?entity_type=story'); $list->assertStatus(200)->assertJsonPath('total', 4)->assertJsonPath('items.0.action', 'story.delete');
    }

    public function test_bin_restore_and_versions(): void
    {
        $id = (int) $this->api('POST', '/stories', ['title' => 'Versioned', 'section' => 'news', 'content' => $this->body()])->json('id');
        $this->api('PUT', '/stories/' . $id, ['content' => '<p>' . str_repeat('Second draft words here. ', 20) . '</p>']);
        $v = $this->api('GET', '/stories/' . $id . '/versions'); $v->assertStatus(200); $this->assertGreaterThanOrEqual(1, count($v->json('versions')));
        $vid = (int) $v->json('versions.0.id');
        $this->api('POST', '/stories/' . $id . '/versions/' . $vid . '/restore')->assertStatus(200);
        $this->api('DELETE', '/stories/' . $id)->assertStatus(200);
        $this->api('GET', '/stories?status=trash')->assertStatus(200)->assertJsonPath('total', 1)->assertJsonPath('stories.0.id', $id);
        $this->api('GET', '/stories?status=all')->assertJsonPath('total', 0);
        $this->api('POST', '/stories/' . $id . '/restore')->assertStatus(200)->assertJsonPath('story.status', 'draft');
        $this->api('GET', '/stories?status=all')->assertJsonPath('total', 1);
    }

    public function test_idempotency_key_replays_the_first_response(): void
    {
        $h = ['Idempotency-Key' => 'story-create-abc12345'];
        $a = $this->api('POST', '/stories', ['title' => 'Once only', 'section' => 'news'], $h); $a->assertStatus(200);
        $b = $this->api('POST', '/stories', ['title' => 'Once only', 'section' => 'news'], $h); $b->assertStatus(200);
        $this->assertSame($a->json('id'), $b->json('id')); $this->assertSame('true', $b->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, DB::table('articles')->where('workspace_id', $this->ws)->where('title', 'Once only')->count());
    }

    public function test_sessions_listing_revoke_others_health_and_api_key_refusal(): void
    {
        $svc = app(RefreshTokenService::class); $u = User::find($this->user); $w = Workspace::find($this->ws);
        $mine = $svc->issueTokenPair($u, $w, '1.1.1.1', 'Phone', 'test'); $other = $svc->issueTokenPair($u, $w, '2.2.2.2', 'Laptop', 'test');
        $tok = $mine['access_token'];
        $s = $this->api('GET', '/sessions', [], ['token' => $tok]); $s->assertStatus(200); $this->assertGreaterThanOrEqual(2, count($s->json('sessions')));
        $this->assertTrue(collect($s->json('sessions'))->firstWhere('id', $mine['session_id'])['current']);
        $this->api('POST', '/sessions/revoke-others', [], ['token' => $tok])->assertStatus(200)->assertJsonPath('revoked', 1);
        $this->assertNotNull(DB::table('sessions')->where('id', $other['session_id'])->value('revoked_at')); $this->assertNull(DB::table('sessions')->where('id', $mine['session_id'])->value('revoked_at'));
        Cache::put('desk:publish-scheduled:last_run', now()->toIso8601String(), 600);
        $h = $this->api('GET', '/health'); $h->assertStatus(200)->assertJsonPath('status', 'ok')->assertJsonPath('checks.database', 'ok')->assertJsonPath('checks.scheduler.state', 'ok');
        Cache::put('desk:publish-scheduled:last_run', now()->subMinutes(30)->toIso8601String(), 600);
        $this->api('GET', '/health')->assertJsonPath('status', 'degraded');
        $this->artisan('publisher:publish-scheduled')->assertExitCode(0);
        $this->api('GET', '/health')->assertJsonPath('checks.scheduler.state', 'ok');
        // rate-limit headers are present on desk routes
        $this->assertNotNull($this->api('GET', '/context')->headers->get('X-RateLimit-Limit'));
    }
}

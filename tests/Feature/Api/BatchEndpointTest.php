<?php

namespace Tests\Feature\Api;

use App\Core\Auth\RefreshTokenService;
use App\Http\Controllers\Api\BatchController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /api/batch (PERF 2026-09-22): several allow-listed GET reads as sub-requests in one process.
 * The contract under test: every batched item returns the SAME status and the SAME body as the direct call,
 * only allow-listed paths run, the batch is capped, and it is as private as the routes it carries.
 */
class BatchEndpointTest extends TestCase
{
    private int $userId;
    private int $wsId;
    private string $token;

    private const SARAH = ['workspace/status', 'user/last-chat-workspace', 'dashboard/overview', 'approvals?status=pending&per_page=5', 'calendar/events', 'social/accounts', 'seo/gsc/status'];
    private const SHELL = ['approvals/count', 'engines', 'messages/unread-count', 'notifications/unread-count', 'tasks', 'approvals?status=pending&per_page=20', 'workspace/status'];
    private const CRM = ['crm/dashboard', 'crm/pipeline/stages', 'crm/leads', 'crm/contacts', 'crm/modules', 'crm/settings', 'crm/tasks?status=pending', 'crm/appointments?upcoming=1'];

    protected function setUp(): void
    {
        parent::setUp();
        $u = new User();
        $u->name = 'Batch Test';
        $u->email = 'batch-' . uniqid() . '@example.test';
        $u->password = 'Batch-Test-Password-1';
        $u->status = 'active';
        $u->save();
        $this->userId = (int) $u->id;

        $this->wsId = (int) DB::table('workspaces')->insertGetId([
            'name' => 'batch-test', 'slug' => 'batch-test-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $this->userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert(['workspace_id' => $this->wsId, 'user_id' => $this->userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['workspace_id' => $this->wsId, 'name' => 'Batch Site', 'subdomain' => 'batch-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $sessionId = (int) DB::table('sessions')->insertGetId([
            'user_id' => $this->userId, 'workspace_id' => null, 'auth_via' => 'password',
            'refresh_token_hash' => hash('sha256', 'batch-' . uniqid('', true)),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->token = app(RefreshTokenService::class)->issueAccessToken(User::findOrFail($this->userId), null, 'password', $sessionId);
    }

    private function auth()
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token)->withHeader('Accept', 'application/json');
    }

    private function batchUrl(array $paths): string
    {
        return '/api/batch?' . implode('&', array_map(fn ($p) => 'paths[]=' . rawurlencode($p), $paths));
    }

    /** Each batched item equals its direct call — status and body — for Sarah's seven boot reads. */
    public function test_sarah_boot_reads_match_direct_calls(): void
    {
        $this->assertSameAsDirect(self::SARAH);
    }

    /** The shell's per-page pollers and Needs attention's reads equal their direct calls. */
    public function test_shell_pollers_match_direct_calls(): void
    {
        $this->assertSameAsDirect(self::SHELL);
    }

    /** Each batched item equals its direct call for the CRM engine's eight boot reads. */
    public function test_crm_boot_reads_match_direct_calls(): void
    {
        $this->assertSameAsDirect(self::CRM);
    }

    private function assertSameAsDirect(array $paths): void
    {
        // Warm-up: some reads seed workspace defaults on first call (pipeline/stages creates the five default stages),
        // so a first pass settles the fixture and the comparison below is order-independent.
        foreach ($paths as $p) {
            $this->auth()->get('/api/' . $p);
        }
        $direct = [];
        foreach ($paths as $p) {
            $r = $this->auth()->get('/api/' . $p);
            $direct[$p] = ['status' => $r->getStatusCode(), 'body' => json_decode($r->getContent(), true)];
        }

        $batch = $this->auth()->get($this->batchUrl($paths));
        $batch->assertOk();
        $results = $batch->json('results');
        $this->assertIsArray($results);

        foreach ($paths as $p) {
            $this->assertArrayHasKey($p, $results, "batch carries {$p}");
            $this->assertSame($direct[$p]['status'], $results[$p]['status'], "status of {$p}");
            $this->assertEquals($this->stable($direct[$p]['body']), $this->stable($results[$p]['body']), "body of {$p}");
        }
    }

    /** Fields that legitimately differ between two calls (clocks, request ids) are removed before comparing. */
    private function stable($body)
    {
        if (! is_array($body)) {
            return $body;
        }
        $out = [];
        foreach ($body as $k => $v) {
            if (is_string($k) && preg_match('/^(generated_at|server_time|timestamp|request_id|now|as_of|cached_at|fetched_at|time_ago|ago|relative_time|humanized|elapsed)$/', $k)) {
                continue;
            }
            $out[$k] = $this->stable($v);
        }
        return $out;
    }

    public function test_it_requires_a_bearer(): void
    {
        $this->withHeader('Accept', 'application/json')->get($this->batchUrl(['workspace/status', 'dashboard/overview']))->assertStatus(401);
    }

    public function test_only_allow_listed_paths_run(): void
    {
        $r = $this->auth()->get($this->batchUrl(['workspace/status', 'auth/me', '../admin/users']));
        $r->assertOk();
        $this->assertSame(200, $r->json('results.workspace/status.status'));
        $results = $r->json('results');
        $this->assertSame(422, $results['auth/me']['status']);
        $this->assertSame(422, $results['../admin/users']['status']);
    }

    public function test_it_is_capped(): void
    {
        $this->auth()->get($this->batchUrl(array_fill(0, BatchController::MAX + 1, 'workspace/status')))->assertStatus(422);
        $this->auth()->get('/api/batch')->assertStatus(422);
    }

    public function test_the_outer_request_is_restored_after_the_batch(): void
    {
        // A batch must not leave the container's bound request pointing at the last sub-request.
        $this->auth()->get($this->batchUrl(['crm/settings', 'crm/modules']))->assertOk();
        $this->assertStringContainsString('/api/batch', app('request')->getRequestUri());
    }
}

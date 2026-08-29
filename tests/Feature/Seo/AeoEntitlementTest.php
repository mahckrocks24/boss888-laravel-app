<?php

namespace Tests\Feature\Seo;

use App\Engines\Write\Services\WriteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AEO-1/2 (2026-08-29) — AEO is part of every AI plan (ADR-0012: capacity, not capability, from $49 up); the old
 * "$69 WP Bundle" gate is gone. An article with no body is not enriched and not charged.
 */
class AeoEntitlementTest extends TestCase
{
    private const WS = 999999913;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'AEO Owner', 'email' => 'aeo-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'AEO WS', 'slug' => 'aeo-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS, 'user_id' => $this->uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => self::WS, 'balance' => 10, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[9911, 'starter', 19, 'none'], [9912, 'ai-lite', 49, 'full']] as [$id, $slug, $price, $ai]) {
            if (!DB::table('plans')->where('id', $id)->exists()) {
                DB::table('plans')->insert(['id' => $id, 'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'ai_access' => $ai, 'credit_limit' => 100, 'is_public' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['articles', 'subscriptions', 'credits', 'credit_transactions', 'workspace_users'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'aeo-owner@example.test')->delete(); } catch (\Throwable) {}
        try { DB::table('plans')->whereIn('id', [9911, 9912])->delete(); } catch (\Throwable) {}
    }

    private function subscribe(int $planId): void
    {
        DB::table('subscriptions')->where('workspace_id', self::WS)->delete();
        DB::table('subscriptions')->insert(['workspace_id' => self::WS, 'plan_id' => $planId, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function headers(): array
    {
        $user  = \App\Models\User::find($this->uid);
        $token = app(\App\Core\Auth\RefreshTokenService::class)->issueAccessToken($user, \App\Models\Workspace::find(self::WS));
        return ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token];
    }

    private function article(string $content): int
    {
        return (int) DB::table('articles')->insertGetId(['workspace_id' => self::WS, 'title' => 'AEO test', 'slug' => 'aeo-test-' . uniqid(), 'type' => 'blog_article', 'status' => 'draft', 'content' => $content, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @test */
    public function test_starter_is_refused_and_ai_lite_is_admitted_to_aeo(): void
    {
        $id = $this->article('<p>stub</p>');
        $this->subscribe(9911);
        $res = $this->withHeaders($this->headers())->postJson('/api/seo/aeo/enrich', ['article_id' => $id]);
        $res->assertStatus(402);
        $this->assertSame('aeo_plan_required', $res->json('error'));
        $this->assertStringNotContainsString('$69', (string) $res->json('message'));
        $this->assertStringNotContainsString('WP Bundle', (string) $res->json('message'));

        $this->subscribe(9912);
        $res2 = $this->withHeaders($this->headers())->postJson('/api/seo/aeo/enrich', ['article_id' => $id]);
        $this->assertNotSame(402, $res2->status(), 'the $49 AI tier is admitted');
    }

    /** @test */
    public function test_an_article_without_a_body_is_not_enriched_and_not_charged(): void
    {
        $id  = $this->article('<h1>Title only</h1><p>Ten words is not a body worth summarising here.</p>');
        $out = app(WriteService::class)->aeoEnrich(self::WS, ['article_id' => $id]);
        $this->assertFalse($out['enriched']);
        $this->assertSame('article_has_no_body', $out['reason']);
        $this->assertStringContainsString('Nothing was charged', $out['message']);
        $this->assertNull(DB::table('articles')->where('id', $id)->value('aeo_enriched_at'));
    }
}

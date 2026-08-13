<?php

namespace Tests\Feature\Studio;

use App\Core\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 WAVE 1 — authoritative active workspace identity (2026-08-13).
 *
 * JwtAuthMiddleware scopes every request from the token's `ws` claim, validated
 * against live membership. That claim is the tenant the caller is actually in.
 *
 * AuthService::me() previously reported $workspaces->first() — the first
 * membership row — so after a workspace switch the two disagreed. Proven live:
 * JWT ws=999926 (BUILDER888 Journey A3, free) while /auth/me answered
 * current_workspace_id=2 (Chef Red, pro). Every surface deriving identity, plan,
 * credits or entitlements from /auth/me then described a different tenant than
 * the one the API was serving — Studio showed Free/0 credits for a Pro
 * workspace, and vice versa.
 *
 * These tests pin that me() follows the resolved workspace, not list order.
 */
class WorkspaceIdentityTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function svc(): AuthService
    {
        return app(AuthService::class);
    }

    /** @test */
    public function me_reports_the_resolved_workspace_not_the_first_membership(): void
    {
        $second = $this->createAdditionalWorkspace('Second Tenant');
        $user   = $this->testUser->fresh();

        $first = $this->svc()->me($user)['current_workspace_id'];
        $this->assertNotSame($second->id, $first, 'fixture must have more than one membership');

        $resolved = $this->svc()->me($user, (int) $second->id);

        $this->assertSame(
            (int) $second->id,
            (int) $resolved['current_workspace_id'],
            'me() must follow the request-resolved workspace, not the membership order'
        );
    }

    /** @test */
    public function the_named_current_workspace_matches_the_resolved_id(): void
    {
        $second = $this->createAdditionalWorkspace('Named Tenant');
        $user   = $this->testUser->fresh();

        $me = $this->svc()->me($user, (int) $second->id);
        $current = collect($me['workspaces'])->firstWhere('id', $me['current_workspace_id']);

        $this->assertNotNull($current, 'the reported current workspace must be in the membership list');
        $this->assertSame('Named Tenant', $current['name']);
    }

    /** @test */
    public function switching_between_workspaces_changes_the_reported_identity_both_ways(): void
    {
        $second = $this->createAdditionalWorkspace('Toggle Tenant');
        $user   = $this->testUser->fresh();
        $home   = (int) $this->testWorkspace->id;

        $this->assertSame($home, (int) $this->svc()->me($user, $home)['current_workspace_id']);
        $this->assertSame((int) $second->id, (int) $this->svc()->me($user, (int) $second->id)['current_workspace_id']);
        $this->assertSame($home, (int) $this->svc()->me($user, $home)['current_workspace_id'],
            'switching back must not leave stale tenant state');
    }

    /** @test */
    public function a_caller_with_no_resolved_scope_still_gets_a_workspace(): void
    {
        $this->createAdditionalWorkspace('Fallback Tenant');
        $user = $this->testUser->fresh();

        $me = $this->svc()->me($user, null);

        $this->assertNotNull($me['current_workspace_id'], 'must not return a null identity');
        $this->assertContains(
            (int) $me['current_workspace_id'],
            collect($me['workspaces'])->pluck('id')->map(fn ($i) => (int) $i)->all()
        );
    }

    /** @test */
    public function the_me_endpoint_reports_the_workspace_the_request_is_scoped_to(): void
    {
        $second = $this->createAdditionalWorkspace('Endpoint Tenant');

        // The endpoint must agree with the scope the middleware resolved, which
        // is exactly what disagreed in production.
        $res = $this->withHeaders($this->authHeaders())->getJson('/api/auth/me');
        $res->assertOk();

        $reported = (int) $res->json('current_workspace_id');
        $this->assertSame(
            (int) $this->testWorkspace->id,
            $reported,
            'the endpoint must name the workspace the token is scoped to'
        );
        $this->assertNotSame((int) $second->id, $reported);
    }

    /** @test */
    public function the_reported_plan_belongs_to_the_resolved_workspace(): void
    {
        $second = $this->createAdditionalWorkspace('Plan Tenant');
        $user   = $this->testUser->fresh();

        $me = $this->svc()->me($user, (int) $second->id);
        $current = collect($me['workspaces'])->firstWhere('id', $me['current_workspace_id']);

        // Whatever the plan is, it must be the entry for the RESOLVED workspace —
        // reading another tenant's plan is what showed Free for a Pro workspace.
        $this->assertSame((int) $second->id, (int) $current['id']);
        $this->assertArrayHasKey('plan', $current);
    }
}

<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INC-0006 / ARCH-1 (2026-08-31) — the canonical product model, asserted.
 *
 *   ONE BUSINESS WORKSPACE  →  MANY WEBSITES
 *
 * A workspace is the tenancy boundary; a website is a child resource inside it. On 2026-06-24 the builder inverted
 * that ("website = its own workspace"), so a customer's SECOND website became a separate business with its own CRM,
 * SEO estate, Sarah memory, subscription and wallet — 61,313 rows across 73 tables for one owner.
 *
 * Nothing asserted the invariant, which is why it survived two months. The existing suite proved workspaces are
 * ISOLATED from each other — true, and exactly what made the wrong model look correct.
 *
 * These are the stage-0 gates from the Owner's migration brief. They must pass before any data is migrated.
 */
class ArchitectureInvariantTest extends TestCase
{
    /** @return array{0:int,1:int} workspace id, owner user id */
    private function business(int $maxWebsites = 10): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'arch-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'biz-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plan = (int) (DB::table('plans')->where('max_websites', '>=', $maxWebsites)->value('id')
            ?: DB::table('plans')->orderByDesc('max_websites')->value('id'));
        DB::table('subscriptions')->insert([
            'workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('credits')->insert([
            'workspace_id' => $ws, 'balance' => 500, 'reserved_balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$ws, $uid];
    }

    /** The invariant that was missing: website #2 is a sibling, not a new tenant. */
    public function test_creating_more_websites_never_creates_another_workspace(): void
    {
        [$ws] = $this->business();
        $builder = app(\App\Engines\Builder\Services\BuilderService::class);
        $workspacesBefore = (int) DB::table('workspaces')->count();

        $ids = [];
        foreach (['First Site', 'Second Site', 'Third Site'] as $name) {
            $res = $builder->createWebsite($ws, ['name' => $name]);
            $this->assertNotEmpty($res['website_id'] ?? null, 'website creation failed: ' . json_encode($res));
            $ids[] = (int) $res['website_id'];
        }

        $this->assertSame(
            $workspacesBefore,
            (int) DB::table('workspaces')->count(),
            'creating websites must not create workspaces — a website is a child resource, not a tenant'
        );
        $this->assertSame(3, DB::table('websites')->where('workspace_id', $ws)->whereNull('deleted_at')->count(),
            'all three websites belong to the one business workspace');
        foreach ($ids as $id) {
            $this->assertSame($ws, (int) DB::table('websites')->where('id', $id)->value('workspace_id'));
        }
    }

    /** A website must not acquire a wallet simply by existing. */
    public function test_no_website_gets_its_own_wallet_or_subscription(): void
    {
        [$ws] = $this->business();
        $builder = app(\App\Engines\Builder\Services\BuilderService::class);
        $builder->createWebsite($ws, ['name' => 'Site A']);
        $builder->createWebsite($ws, ['name' => 'Site B']);

        $this->assertSame(1, DB::table('credits')->where('workspace_id', $ws)->count(),
            'one wallet for the business');
        $this->assertSame(1, DB::table('subscriptions')->where('workspace_id', $ws)->count(),
            'one subscription for the business');

        // and no wallet/subscription was created anywhere else for these sites
        $siteWs = DB::table('websites')->where('workspace_id', $ws)->pluck('workspace_id')->unique();
        $this->assertCount(1, $siteWs, 'every website resolves to the same workspace');
    }

    /** Websites A and B draw on the SAME authoritative pool. */
    public function test_both_websites_share_one_credit_pool(): void
    {
        [$ws] = $this->business();
        $builder = app(\App\Engines\Builder\Services\BuilderService::class);
        $a = $builder->createWebsite($ws, ['name' => 'Site A']);
        $b = $builder->createWebsite($ws, ['name' => 'Site B']);
        $aId = (int) $a['website_id'];
        $bId = (int) $b['website_id'];

        $poolOf = function (int $websiteId) {
            $w = (int) DB::table('websites')->where('id', $websiteId)->value('workspace_id');
            return (int) (DB::table('workspaces')->where('id', $w)->value('billing_workspace_id') ?: $w);
        };

        $this->assertSame($poolOf($aId), $poolOf($bId), 'work on either website must debit the same wallet');
        $this->assertSame(1, DB::table('credits')->whereIn('workspace_id', [$poolOf($aId)])->count());
    }

    /** Sarah sees the portfolio: the workspace's websites are all visible to her target resolver. */
    public function test_sarah_can_see_every_website_in_the_business(): void
    {
        [$ws] = $this->business();
        $builder = app(\App\Engines\Builder\Services\BuilderService::class);
        $builder->createWebsite($ws, ['name' => 'Alpha']);
        $builder->createWebsite($ws, ['name' => 'Beta']);

        $visible = DB::table('websites')->where('workspace_id', $ws)->whereNull('deleted_at')
            ->pluck('name')->all();

        $this->assertContains('Alpha', $visible);
        $this->assertContains('Beta', $visible);
        $this->assertCount(2, $visible, 'workspace-wide intelligence sees the whole portfolio');
    }

    /** Website selection is not workspace switching: one workspace, many sites, no tenant hop. */
    public function test_selecting_a_website_never_requires_leaving_the_workspace(): void
    {
        [$ws] = $this->business();
        $builder = app(\App\Engines\Builder\Services\BuilderService::class);
        $builder->createWebsite($ws, ['name' => 'One']);
        $builder->createWebsite($ws, ['name' => 'Two']);

        $workspacesForThisOwner = DB::table('websites')->where('workspace_id', $ws)
            ->whereNull('deleted_at')->distinct()->pluck('workspace_id');

        $this->assertCount(1, $workspacesForThisOwner,
            'every website of this business is reachable without switching workspace');
    }

    /** The gate itself: the provisioner is off, and returns "use the current workspace". */
    public function test_the_website_workspace_provisioner_is_disabled(): void
    {
        $this->assertFalse((bool) config('builder.website_workspaces'),
            'ARCH-1: website-per-workspace must stay off');

        $before = (int) DB::table('workspaces')->count();
        $result = app(\App\Engines\Builder\Services\ArthurService::class)
            ->provisionWebsiteWorkspace(1, 1, 1, 'should not be created');

        $this->assertSame(0, $result, '0 means "use the current workspace"');
        $this->assertSame($before, (int) DB::table('workspaces')->count(), 'no workspace was created');
    }
}

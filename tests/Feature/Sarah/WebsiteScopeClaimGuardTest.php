<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\WebsiteScopeClaimGuard;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RISK-0141 (2026-09-07, DEC-0040): Sarah must never claim website scope the created tasks do not carry. */
class WebsiteScopeClaimGuardTest extends TestCase
{
    private function workspaceWithTwoSites(): array
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'O', 'email' => 'scope-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'Launch QA Bakery', 'slug' => 'scope-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $bakery = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => 'Launch QA Bakery', 'status' => 'published', 'type' => 'template', 'subdomain' => 'b' . uniqid() . '.levelupgrowth.io', 'created_at' => now(), 'updated_at' => now()]);
        $cakes = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => 'Launch QA Cakes', 'status' => 'published', 'type' => 'template', 'subdomain' => 'c' . uniqid() . '.levelupgrowth.io', 'created_at' => now(), 'updated_at' => now()]);
        return [$ws, $bakery, $cakes];
    }

    private function task(int $ws, array $payload): int
    {
        return (int) Task::create(['workspace_id' => $ws, 'engine' => 'write', 'action' => 'write_article', 'status' => 'queued', 'requires_approval' => 0, 'approval_status' => null, 'source' => 'agent', 'payload_json' => $payload])->id;
    }

    public function test_an_unbacked_scope_claim_is_rewritten_and_the_binding_gap_is_stated(): void
    {
        [$ws, , ] = $this->workspaceWithTwoSites();
        $t = $this->task($ws, ['title' => 'How to Choose a Wedding Cake']);   // no website_id
        $in = "You've got it — I've queued Priya to write a ~400-word draft of “How to Choose a Wedding Cake”, scoped to the Launch QA Cakes website only. It will be saved as a draft.";
        $out = WebsiteScopeClaimGuard::apply($in, $ws, [$t]);
        $this->assertStringNotContainsString('scoped to the Launch QA Cakes website only', $out);
        $this->assertStringContainsString('not yet attached to Launch QA Cakes', $out);
        $this->assertStringContainsString(WebsiteScopeClaimGuard::NOTE, $out);
    }

    public function test_a_backed_scope_claim_is_left_alone(): void
    {
        [$ws, , $cakes] = $this->workspaceWithTwoSites();
        $t = $this->task($ws, ['title' => 'x', 'website_id' => $cakes]);
        $in = "I've queued Priya to write it for the Launch QA Cakes website only.";
        $this->assertSame($in, WebsiteScopeClaimGuard::apply($in, $ws, [$t]));
    }

    public function test_a_plain_business_mention_is_not_a_scope_claim(): void
    {
        [$ws, , ] = $this->workspaceWithTwoSites();
        $t = $this->task($ws, ['title' => 'x']);
        $in = "I've asked Priya to write an article for Launch QA Bakery about sourdough. This uses 1 credit.";
        $this->assertSame($in, WebsiteScopeClaimGuard::apply($in, $ws, [$t]));
    }

    public function test_no_created_tasks_means_no_change(): void
    {
        [$ws, , ] = $this->workspaceWithTwoSites();
        $in = 'Scoped to the Launch QA Cakes website only.';
        $this->assertSame($in, WebsiteScopeClaimGuard::apply($in, $ws, []));
    }
}

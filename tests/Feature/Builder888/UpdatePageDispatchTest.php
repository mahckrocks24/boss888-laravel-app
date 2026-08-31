<?php

namespace Tests\Feature\Builder888;

use App\Core\TaskSystem\Orchestrator;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CAP-GAP-1 (2026-08-31). `update_page` was capability-mapped, approval-gated ('review') and had a success message
 *  ("Page updated.") but no dispatch handler, so an approved page edit answered "This action isn't supported yet".
 *  Found by mining real task rows for that failure, not by comparing maps — a real task hit it on 2026-08-07. */
class UpdatePageDispatchTest extends TestCase
{
    /** @return array{0:int,1:int} workspace, page */
    private function seedPage(): array
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'up-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'UP', 'slug' => 'up-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $site = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => 'Site', 'subdomain' => 'up-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $page = (int) DB::table('pages')->insertGetId(['website_id' => $site, 'title' => 'Before', 'slug' => 'p-' . uniqid(), 'is_homepage' => 1, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return [$ws, $page];
    }

    private function dispatch(int $ws, array $payload): Task
    {
        $t = Task::create(['workspace_id' => $ws, 'engine' => 'builder', 'action' => 'update_page', 'status' => 'queued',
            'requires_approval' => 0, 'approval_status' => 'approved', 'credit_cost' => 0,
            'payload_json' => $payload, 'source' => 'agent']);
        try { app(Orchestrator::class)->execute($t->fresh()); } catch (\Throwable $e) { /* recorded on the task */ }
        return $t->fresh();
    }

    public function test_an_approved_page_edit_actually_edits_the_page(): void
    {
        [$ws, $page] = $this->seedPage();

        $task = $this->dispatch($ws, ['page_id' => $page, 'title' => 'After']);

        $this->assertSame('completed', $task->status, 'no longer "not supported": ' . (string) $task->error_text);
        $this->assertSame('After', DB::table('pages')->where('id', $page)->value('title'), 'the page is actually changed, not merely reported');
    }

    public function test_a_page_in_another_workspace_is_refused(): void
    {
        [$mine] = $this->seedPage();
        [, $theirPage] = $this->seedPage();

        $task = $this->dispatch($mine, ['page_id' => $theirPage, 'title' => 'Hijacked']);

        $this->assertNotSame('completed', $task->status, 'a cross-tenant page edit must not report success');
        $this->assertSame('Before', DB::table('pages')->where('id', $theirPage)->value('title'), 'another workspace\'s page is untouched');
    }

    public function test_a_missing_page_id_fails_loudly(): void
    {
        [$ws] = $this->seedPage();
        $task = $this->dispatch($ws, ['title' => 'Nowhere']);
        $this->assertNotSame('completed', $task->status);
    }
}

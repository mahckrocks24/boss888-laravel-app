<?php

namespace Tests\Feature\Security;

use App\Core\TaskSystem\Orchestrator;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SEC-1 (2026-08-31). Six Orchestrator dispatches executed a mutation on a bare row id, without the caller's
 *  workspace — so a request naming ANOTHER workspace's post, article or campaign acted on it. `POST
 *  /api/social/posts/{id}/publish` reaches this dispatch directly, which made it a cross-tenant write that could
 *  push one customer's content to another's connected accounts.
 *
 *  Each case asserts the same two things: the foreign row is untouched, and the task does NOT report success. */
class CrossWorkspaceWriteTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'sec-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'SEC', 'slug' => 'sec-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    private function dispatch(int $ws, string $engine, string $action, array $payload): Task
    {
        $t = Task::create(['workspace_id' => $ws, 'engine' => $engine, 'action' => $action, 'status' => 'queued', 'requires_approval' => 0, 'approval_status' => 'approved', 'credit_cost' => 0, 'payload_json' => $payload, 'source' => 'agent']);
        try { app(Orchestrator::class)->execute($t->fresh()); } catch (\Throwable $e) { /* a hard failure is the correct outcome */ }
        return $t->fresh();
    }

    public function test_a_foreign_social_post_cannot_be_scheduled_or_published(): void
    {
        $mine = $this->ws(); $theirs = $this->ws();
        $foreign = (int) DB::table('social_posts')->insertGetId(['workspace_id' => $theirs, 'platform' => 'facebook', 'content' => 'Their announcement', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $t1 = $this->dispatch($mine, 'social', 'social_schedule_post', ['post_id' => $foreign, 'scheduled_at' => now()->addDay()->toDateTimeString()]);
        $this->assertSame('draft', DB::table('social_posts')->where('id', $foreign)->value('status'), 'another workspace\'s post is never scheduled');
        $this->assertNotSame('completed', $t1->status, 'a refused cross-tenant write must not report success');

        $t2 = $this->dispatch($mine, 'social', 'social_publish_post', ['post_id' => $foreign]);
        $this->assertSame('draft', DB::table('social_posts')->where('id', $foreign)->value('status'), 'another workspace\'s post is never published');
        $this->assertNotSame('completed', $t2->status);
    }

    public function test_a_foreign_social_post_cannot_be_deleted(): void
    {
        $mine = $this->ws(); $theirs = $this->ws();
        $foreign = (int) DB::table('social_posts')->insertGetId(['workspace_id' => $theirs, 'platform' => 'facebook', 'content' => 'Keep me', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $t = $this->dispatch($mine, 'social', 'delete_post', ['post_id' => $foreign]);
        $this->assertNull(DB::table('social_posts')->where('id', $foreign)->value('deleted_at'), 'another workspace\'s post is never deleted');
        $this->assertNotSame('completed', $t->status);
    }

    public function test_a_foreign_article_cannot_be_published_or_deleted(): void
    {
        $mine = $this->ws(); $theirs = $this->ws();
        $foreign = (int) DB::table('articles')->insertGetId(['workspace_id' => $theirs, 'title' => 'Their draft', 'slug' => 'their-draft-' . uniqid(), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $t1 = $this->dispatch($mine, 'write', 'publish_article', ['article_id' => $foreign]);
        $this->assertSame('draft', DB::table('articles')->where('id', $foreign)->value('status'), 'another workspace\'s article is never published');
        $this->assertNotSame('completed', $t1->status);

        $t2 = $this->dispatch($mine, 'write', 'delete_article', ['article_id' => $foreign]);
        $this->assertNotNull(DB::table('articles')->where('id', $foreign)->first(), 'another workspace\'s article is never deleted');
        $this->assertNotSame('completed', $t2->status);
    }

    public function test_a_foreign_campaign_cannot_be_scheduled(): void
    {
        $mine = $this->ws(); $theirs = $this->ws();
        $foreign = (int) DB::table('campaigns')->insertGetId(['workspace_id' => $theirs, 'name' => 'Their campaign', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $t = $this->dispatch($mine, 'marketing', 'schedule_campaign', ['campaign_id' => $foreign, 'scheduled_at' => now()->addDay()->toDateTimeString()]);
        $this->assertSame('draft', DB::table('campaigns')->where('id', $foreign)->value('status'), 'another workspace\'s campaign is never scheduled');
        $this->assertNotSame('completed', $t->status);
    }

    public function test_the_owner_of_a_post_can_still_schedule_it(): void
    {
        $mine = $this->ws();
        $post = (int) DB::table('social_posts')->insertGetId(['workspace_id' => $mine, 'platform' => 'facebook', 'content' => 'My announcement', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $t = $this->dispatch($mine, 'social', 'social_schedule_post', ['post_id' => $post, 'scheduled_at' => now()->addDay()->toDateTimeString()]);
        $this->assertSame('completed', $t->status, $t->error_text . ' / ' . $t->progress_message);
        $this->assertSame('scheduled', DB::table('social_posts')->where('id', $post)->value('status'), 'the legitimate path is unchanged');
    }
}

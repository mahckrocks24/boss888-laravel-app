<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\DraftPublishing;
use App\Core\TaskSystem\Orchestrator;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PUBLISH-3 (2026-08-31). The Owner asked for proof that Sarah can actually publish what he asks her to publish,
 *  after she twice answered "the 37 drafts can't be published yet due to unresolved blockages".
 *
 *  This drives the real path end to end: the words the Owner actually typed -> the trigger -> what is ready ->
 *  the tasks that get created -> the Orchestrator running them -> the articles being live in the database. */
class DraftPublishFlowTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'pf-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'PF', 'slug' => 'pf-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['workspace_id' => $ws, 'balance' => 500, 'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    private function draft(int $ws, ?string $image): int
    {
        return (int) DB::table('articles')->insertGetId([
            'workspace_id' => $ws, 'title' => 'Draft ' . uniqid(), 'slug' => 'd-' . uniqid(),
            'content' => 'Body copy.', 'status' => 'draft', 'featured_image_url' => $image,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The exact sentences from the 2026-08-31 conversation, plus the ones that must NOT fire. */
    public function test_the_words_the_owner_actually_used_reach_the_publish_path(): void
    {
        foreach (['Check if you can publish now', 'publish them', 'Publish the 37', 'can you publish?',
                  'publish all of them', 'go live with the drafts', 'publish everything'] as $said) {
            $this->assertTrue(DraftPublishing::asks($said), "should reach publishing: \"{$said}\"");
        }
        foreach (["don't publish yet", 'do not publish them', 'hold off publishing', 'what did you publish last week?'] as $said) {
            $this->assertFalse(DraftPublishing::asks($said), "must NOT publish on: \"{$said}\"");
        }
        $this->assertTrue(DraftPublishing::confirms('Approved.'), 'the owner\'s "Approved." is a yes');
    }

    public function test_scope_separates_what_can_go_live_from_what_needs_an_image(): void
    {
        $ws = $this->ws();
        for ($i = 0; $i < 5; $i++) $this->draft($ws, 'https://img.test/' . $i . '.png');
        $this->draft($ws, '');
        $this->draft($ws, null);

        $scope = app(DraftPublishing::class)->scope($ws);
        $this->assertCount(5, $scope['ready'], 'five drafts already have an image');
        $this->assertCount(2, $scope['missing'], 'two still need one');
        $this->assertStringContainsString('5', app(DraftPublishing::class)->describe($ws, $scope));
    }

    /** The one that matters: does saying yes actually put the articles live? */
    public function test_publishing_actually_makes_the_articles_live(): void
    {
        $ws = $this->ws();
        $ids = [];
        for ($i = 0; $i < 3; $i++) $ids[] = $this->draft($ws, 'https://img.test/' . $i . '.png');

        $svc = app(DraftPublishing::class);
        $scope = $svc->scope($ws);
        $result = $svc->execute($ws, ['ready' => $scope['ready']->pluck('id')->all(), 'missing' => []], null, 'publish them');

        $this->assertSame(3, $result['published_queued'], 'a publish task per ready draft: ' . json_encode($result['errors']));
        $tasks = Task::where('workspace_id', $ws)->where('action', 'publish_article')->get();
        $this->assertCount(3, $tasks);

        foreach ($tasks as $t) {
            app(Orchestrator::class)->execute($t->fresh());
        }

        $live = DB::table('articles')->where('workspace_id', $ws)->where('status', 'published')->count();
        $this->assertSame(3, $live, 'every ready draft is live in the database, not merely queued');
        foreach ($ids as $id) {
            $this->assertSame('published', DB::table('articles')->where('id', $id)->value('status'));
        }
    }
}

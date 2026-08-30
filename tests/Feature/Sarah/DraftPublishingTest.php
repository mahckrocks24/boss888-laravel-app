<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\DraftPublishing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PUBLISH-1 (2026-08-30): "publish the drafts" describes what goes live, then a yes creates owner-confirmed publish tasks. */
class DraftPublishingTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'dp-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'DP', 'slug' => 'dp-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['workspace_id' => $ws, 'name' => 'Fable QA Bakery', 'subdomain' => 'dp-' . uniqid(), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    private function article(int $ws, string $title, ?string $img, string $status = 'draft'): int
    {
        return (int) DB::table('articles')->insertGetId(['workspace_id' => $ws, 'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title) . '-' . uniqid(), 'status' => $status, 'featured_image_url' => $img, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_intent(): void
    {
        $this->assertTrue(DraftPublishing::asks('publish them'));
        $this->assertTrue(DraftPublishing::asks('Publish the drafts please.'));
        $this->assertTrue(DraftPublishing::asks('push all the articles live'));
        $this->assertFalse(DraftPublishing::asks('publish "Reserve Your Weekend Sourdough"'));
        $this->assertFalse(DraftPublishing::asks('how many drafts do I have?'));
        $this->assertTrue(DraftPublishing::confirms('Yes'));
        $this->assertTrue(DraftPublishing::declines('Not yet'));
    }

    public function test_describe_then_yes_queues_ready_drafts_and_leaves_the_rest(): void
    {
        $ws = $this->ws();
        $a = $this->article($ws, 'Lead Capture for Weekend Sourdough', '/storage/x.png');
        $b = $this->article($ws, 'Reserve Your Weekend Sourdough', '/storage/y.png');
        $c = $this->article($ws, 'No Image Yet', null);
        $live = $this->article($ws, 'Already Live', '/storage/z.png', 'published');

        $dp = app(DraftPublishing::class);
        $scope = $dp->scope($ws);
        $this->assertEqualsCanonicalizing([$a, $b], $scope['ready']->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$c], $scope['missing']->pluck('id')->map(fn ($i) => (int) $i)->all());
        $text = $dp->describe($ws, $scope);
        $this->assertStringContainsString('3 drafts', $text);
        $this->assertStringContainsString('2 are ready to go live now', $text);
        $this->assertStringContainsString('on Fable QA Bakery', $text);
        $this->assertStringContainsString('Publishing costs no credits', $text);
        $this->assertStringContainsString('Say **yes**', $text);

        $dp->remember($ws, $scope, 'publish them');
        $res = $dp->execute($ws, $dp->pending($ws), null, 'publish them');
        $this->assertSame(2, $res['published_queued'], json_encode($res['errors']));
        $tasks = DB::table('tasks')->where('workspace_id', $ws)->where('action', 'publish_article')->get(['payload_json', 'status', 'approval_status', 'credit_cost']);
        $this->assertCount(2, $tasks);
        $this->assertEqualsCanonicalizing([$a, $b], $tasks->map(fn ($t) => (int) (json_decode($t->payload_json, true)['article_id'] ?? 0))->all());
        foreach ($tasks as $t) { $this->assertNotSame('pending', $t->approval_status, 'owner-confirmed publishes do not wait for a second approval'); $this->assertSame(0, (int) $t->credit_cost); }
        $this->assertSame('published', DB::table('articles')->where('id', $live)->value('status'));
        $this->assertStringContainsString('publishing 2 articles', $dp->report($res, 1));
        Cache::forget(DraftPublishing::key($ws));
    }
}

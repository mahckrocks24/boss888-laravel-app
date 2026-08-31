<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ExecutiveFrame;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PUBLISH-2 / PUBLISH-2b (2026-08-31). Sarah told the owner that "the 13 drafts missing featured images" and old
 *  failed tasks were blocking publication, when one draft lacked an image and 37 were ready to go live. The guard
 *  log for that turn recorded candidates_examined: 0 — the figure was not read from anything. Publishing readiness
 *  is now two counted facts placed in front of her. */
class PublishReadinessFactsTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'pub-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'PUB', 'slug' => 'pub-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (int) (DB::table('plans')->where('slug', 'like', '%pro%')->value('id') ?: DB::table('plans')->value('id'));
        DB::table('subscriptions')->insert(['workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    private function article(int $ws, string $status, ?string $image, bool $deleted = false): int
    {
        return (int) DB::table('articles')->insertGetId([
            'workspace_id' => $ws, 'title' => 'A ' . uniqid(), 'slug' => 'a-' . uniqid(),
            'status' => $status, 'featured_image_url' => $image,
            'deleted_at' => $deleted ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_frame_states_how_many_drafts_can_go_live_now(): void
    {
        $ws = $this->ws();
        for ($i = 0; $i < 3; $i++) $this->article($ws, 'draft', 'https://img.test/' . $i . '.png');
        $this->article($ws, 'draft', '');                                   // the only real "needs an image"
        $this->article($ws, 'published', null);                             // already live — cannot block anything
        $this->article($ws, 'draft', null, true);                           // soft-deleted — not inventory

        $material = app(ExecutiveFrame::class)->material($ws);
        $text = json_encode($material);

        $this->assertStringContainsString('3 draft(s) have a featured image', $text, 'the publishable count is stated outright');
        $this->assertStringContainsString('1 still need one', $text, 'and so is the only genuine gap');
        $this->assertStringContainsString('do not state any other figure as a blocker', $text);
    }

    public function test_a_published_article_without_an_image_is_not_counted_as_a_draft_gap(): void
    {
        $ws = $this->ws();
        $this->article($ws, 'draft', 'https://img.test/ok.png');
        for ($i = 0; $i < 5; $i++) $this->article($ws, 'published', '');    // the rows that inflated the old count

        $text = json_encode(app(ExecutiveFrame::class)->material($ws));
        $this->assertStringContainsString('1 draft(s) have a featured image', $text);
        $this->assertStringContainsString('0 still need one', $text, 'published articles are live and never a publishing blocker');
    }
}

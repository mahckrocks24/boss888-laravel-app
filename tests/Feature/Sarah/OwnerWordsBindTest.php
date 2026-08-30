<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ParameterProvenance;
use App\Core\Sarah888\ToolIntent;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P2-U2: the article the owner NAMES wins over the id the model carried over. */
class OwnerWordsBindTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'bind-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('workspaces')->insertGetId(['name' => 'Bind', 'slug' => 'bind-' . uniqid(), 'created_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function article(int $ws, string $title, string $status): int
    {
        return (int) DB::table('articles')->insertGetId(['workspace_id' => $ws, 'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title) . '-' . uniqid(), 'status' => $status, 'content' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_named_article_overrides_carried_over_id(): void
    {
        $ws = $this->ws();
        $old = $this->article($ws, 'Weekend Sourdough Special', 'published');
        $new = $this->article($ws, 'Reserve Your Weekend Sourdough', 'draft');

        $intent = new ToolIntent('write.publish_article', $ws, ['article_id' => (string) $old], null, null, null, null, null, null,
            'Please publish the article Reserve Your Weekend Sourdough on the bakery website.');
        $pp = app(ParameterProvenance::class);
        $this->assertSame(['article_id' => (string) $new], $pp->bindFromOwnerWords($intent, ['article_id']));
        $this->assertSame(ParameterProvenance::EXPLICIT, $pp->classify($intent, ['article_id'])['provenance']['article_id']);
    }

    public function test_no_binding_when_message_names_nothing_or_is_ambiguous(): void
    {
        $ws = $this->ws();
        $this->article($ws, 'Autumn Menu Launch', 'draft');
        $this->article($ws, 'Autumn Menu Launch Recap', 'draft');
        $pp = app(ParameterProvenance::class);
        $none = new ToolIntent('write.publish_article', $ws, [], null, null, null, null, null, null, 'Please publish the finished article.');
        $this->assertSame([], $pp->bindFromOwnerWords($none, ['article_id']));
        // two different titles both present → ambiguous → no binding
        $amb = new ToolIntent('write.publish_article', $ws, [], null, null, null, null, null, null, 'Publish Autumn Menu Launch and the Autumn Menu Launch Recap.');
        $this->assertSame([], $pp->bindFromOwnerWords($amb, ['article_id']));
    }
}

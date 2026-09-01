<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ArticleIdClaimGuard;
use App\Core\Sarah888\DraftPublishing;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chef Red, 2026-09-01. The owner asked three times to publish and nothing happened.
 *
 *   "okay. publish 5 right now"   Sarah described the drafts and asked for a yes
 *   "only 5 as i said"            not a yes-word, so she described them again
 *   "the earlist ones written"    not a yes-word, so she described them again
 *
 * Zero tasks were created, and by the third reply she was naming article numbers that were not his — four of
 * them belonging to the platform's own workspace. Two defects, held here.
 */
class PublishRefinementTest extends TestCase
{
    private function workspaceWithDrafts(int $count): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'pub-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'pub-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            DB::table('articles')->insert([
                'workspace_id' => $ws, 'title' => 'Draft number ' . $i, 'slug' => 'd-' . uniqid(),
                'content' => '<p>body</p>', 'status' => 'draft',
                'featured_image_url' => 'https://example.test/i.jpg',
                'created_at' => now()->subDays($count - $i), 'updated_at' => now()->subDays($count - $i),
            ]);
        }

        return $ws;
    }

    /*──────────────────────────────────────── the refinement is an instruction */

    /** @dataProvider refinements */
    public function test_a_refinement_of_a_pending_offer_is_understood(string $said, ?int $limit, ?string $order): void
    {
        $r = DraftPublishing::refines($said);

        $this->assertNotNull($r, "\"{$said}\" is the owner narrowing an offer, not small talk");
        $this->assertSame($limit, $r['limit']);
        $this->assertSame($order, $r['order']);
    }

    public static function refinements(): array
    {
        return [
            'a count'              => ['only 5 as i said', 5, null],
            'an ordering'          => ['the earlist ones written', null, 'earliest'],
            'both'                 => ['just the oldest 3', 3, 'earliest'],
            'newest instead'       => ['the most recent ones', null, 'latest'],
            'imperative and count' => ['okay. publish 5 right now', 5, null],
        ];
    }

    /** A refusal must never be mistaken for a narrowing. */
    public function test_a_decline_is_not_a_refinement(): void
    {
        foreach (['no, leave them', "don't publish yet", 'stop', 'hold off'] as $said) {
            $this->assertNull(DraftPublishing::refines($said), "\"{$said}\" must not publish anything");
        }
    }

    /** Ordinary conversation is not an instruction either. */
    public function test_unrelated_talk_is_not_a_refinement(): void
    {
        foreach (['what is the weather', 'how many do I have', 'thanks'] as $said) {
            $this->assertNull(DraftPublishing::refines($said));
        }
    }

    /** The narrowing takes exactly what was asked for, oldest first. */
    public function test_narrowing_takes_the_earliest_and_only_that_many(): void
    {
        $ws = $this->workspaceWithDrafts(8);
        $dp = app(DraftPublishing::class);
        $scope = $dp->scope($ws);
        $pending = ['ready' => $scope['ready']->pluck('id')->all(), 'missing' => []];

        $this->assertCount(8, $pending['ready']);

        $narrowed = $dp->narrow($ws, $pending, ['limit' => 3, 'order' => 'earliest']);
        $this->assertCount(3, $narrowed['ready']);

        $expected = DB::table('articles')->where('workspace_id', $ws)
            ->orderBy('created_at')->limit(3)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame($expected, $narrowed['ready']);
    }

    /** A narrowing may only ever cut the offer down; it can never add something that was not offered. */
    public function test_narrowing_can_never_widen_the_offer(): void
    {
        $ws = $this->workspaceWithDrafts(3);
        $dp = app(DraftPublishing::class);
        $offered = DB::table('articles')->where('workspace_id', $ws)->limit(2)->pluck('id')
            ->map(fn ($i) => (int) $i)->all();

        $narrowed = $dp->narrow($ws, ['ready' => $offered, 'missing' => []], ['limit' => 99, 'order' => 'earliest']);

        $this->assertCount(2, $narrowed['ready']);
        foreach ($narrowed['ready'] as $id) {
            $this->assertContains($id, $offered);
        }
    }

    /*──────────────────────────────────────── she may only name her own articles */

    /** The reported defect: article numbers offered to the owner that were not his. */
    public function test_article_numbers_from_another_workspace_are_removed(): void
    {
        $mine = $this->workspaceWithDrafts(2);
        $theirs = $this->workspaceWithDrafts(2);
        $foreign = DB::table('articles')->where('workspace_id', $theirs)->pluck('id')->all();

        $reply = 'The available drafts are ' . implode(', ', $foreign) . '. Shall I proceed?';
        $out = app(ArticleIdClaimGuard::class)->validate($reply, $mine);

        $this->assertTrue($out['corrected']);
        foreach ($foreign as $id) {
            $this->assertStringNotContainsString((string) $id, $out['reply'],
                'an article number belonging to another workspace must not reach this owner');
        }
        $this->assertStringContainsString('Shall I proceed?', $out['reply'], 'the rest of the reply survives');
    }

    /** Numbers she genuinely has are left exactly as written. */
    public function test_real_article_numbers_are_untouched(): void
    {
        $ws = $this->workspaceWithDrafts(2);
        $ids = DB::table('articles')->where('workspace_id', $ws)->pluck('id')->all();

        $reply = 'The drafts ready are ' . implode(' and ', $ids) . '.';
        $out = app(ArticleIdClaimGuard::class)->validate($reply, $ws);

        $this->assertFalse($out['corrected']);
        $this->assertSame($reply, $out['reply']);
    }

    /** A count is not an identifier, and must never be rewritten as one. */
    public function test_plain_counts_are_not_treated_as_article_numbers(): void
    {
        $ws = $this->workspaceWithDrafts(3);

        $reply = 'You have 38 drafts. 37 are ready to go live now.';
        $out = app(ArticleIdClaimGuard::class)->validate($reply, $ws);

        $this->assertFalse($out['corrected']);
        $this->assertSame($reply, $out['reply']);
    }
}

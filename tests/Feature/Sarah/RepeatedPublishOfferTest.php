<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ChatActionProposal;
use App\Core\Sarah888\SupersededTurnGuard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chef Red, 2026-09-01. Sarah published 37 articles correctly — 39 tasks, all completed, drafts 38 → 1.
 * The owner said "perfect.." and she replied with "6 things are waiting for your OK", listing articles 186,
 * 300, 352, 354, 661 and 662: every one of them already live.
 *
 * Two separate faults produced that, and both are held here.
 *
 *   Nothing checked whether proposed work still needed doing, so an approval request could be raised
 *   against a finished job and then sit in the owner's queue indefinitely.
 *
 *   Replies from earlier questions were landing underneath newer answers. He asked "Hi Sarah", then "how
 *   are you?", then "Publish now"; the publish router answered instantly and the model's replies to the
 *   first two arrived afterwards, repeating the same list of drafts three times.
 */
class RepeatedPublishOfferTest extends TestCase
{
    private function workspace(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'rep-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'rep-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function article(int $ws, string $status): int
    {
        return (int) DB::table('articles')->insertGetId([
            'workspace_id' => $ws, 'title' => 'Article ' . uniqid(), 'slug' => 'a-' . uniqid(),
            'content' => '<p>body</p>', 'status' => $status,
            'featured_image_url' => 'https://example.test/i.jpg',
            'published_at' => $status === 'published' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(int $articleId): array
    {
        return ['engine' => 'write', 'action' => 'publish_article', 'credit_cost' => 0,
                'payload' => ['article_id' => $articleId]];
    }

    /*──────────────────────────────── work already done is never proposed */

    public function test_publishing_an_already_published_article_is_not_proposed(): void
    {
        $ws = $this->workspace();
        $live = $this->article($ws, 'published');

        $before = DB::table('strategy_proposals')->where('workspace_id', $ws)->count();
        $r = app(ChatActionProposal::class)->propose($ws, $this->payload($live), ['conversation_id' => 'c1']);

        $this->assertSame(0, $r['proposal_id'], 'a finished job must not become a decision for the owner');
        $this->assertSame('already_done', $r['skipped'] ?? null);
        $this->assertSame($before, DB::table('strategy_proposals')->where('workspace_id', $ws)->count());
    }

    /** The guard must not block real work — that would be a worse failure than the bug. */
    public function test_publishing_a_real_draft_is_still_proposed(): void
    {
        $ws = $this->workspace();
        $draft = $this->article($ws, 'draft');

        $r = app(ChatActionProposal::class)->propose($ws, $this->payload($draft), ['conversation_id' => 'c1']);

        $this->assertGreaterThan(0, $r['proposal_id']);
    }

    /** An article belonging to someone else is not this workspace's to publish either. */
    public function test_an_article_from_another_workspace_is_not_proposed(): void
    {
        $mine = $this->workspace();
        $theirs = $this->workspace();
        $foreign = $this->article($theirs, 'draft');

        $r = app(ChatActionProposal::class)->propose($mine, $this->payload($foreign), ['conversation_id' => 'c1']);

        $this->assertSame(0, $r['proposal_id']);
    }

    /** Rows raised before the check existed, or completed while waiting, disappear from the queue. */
    public function test_a_pending_request_whose_work_got_done_stops_being_shown(): void
    {
        $ws = $this->workspace();
        $draft = $this->article($ws, 'draft');
        $proposals = app(ChatActionProposal::class);

        $r = $proposals->propose($ws, $this->payload($draft), ['conversation_id' => 'c1']);
        $this->assertCount(1, $proposals->pending($ws, 'c1'));

        // published by some other route while the request sat waiting
        DB::table('articles')->where('id', $draft)->update(['status' => 'published', 'published_at' => now()]);

        $this->assertCount(0, $proposals->pending($ws, 'c1'),
            'the owner must not be asked to approve something that has already happened');
        $this->assertSame('superseded',
            DB::table('strategy_proposals')->where('id', $r['proposal_id'])->value('status'),
            'and the row is closed with a reason rather than left pending forever');
    }

    /*──────────────────────────────── a reply that has been overtaken */

    public function test_a_reply_is_dropped_when_a_newer_question_was_already_answered(): void
    {
        $ws = $this->workspace();

        $first = $this->userMessage($ws, 'how are you?');
        $second = $this->userMessage($ws, 'Publish now');
        $this->agentFinal($ws, 'Here is what would go live.');   // the router answered the second

        $this->assertTrue(app(SupersededTurnGuard::class)->isSuperseded($ws, $first),
            'a reply to the earlier question would land under an answer the owner has already read');
    }

    /** Being slow is not being obsolete: an unanswered newer message must not discard this reply. */
    public function test_a_reply_survives_when_the_newer_question_has_not_been_answered(): void
    {
        $ws = $this->workspace();

        $first = $this->userMessage($ws, 'how are you?');
        $this->userMessage($ws, 'Publish now');   // asked, not yet answered

        $this->assertFalse(app(SupersededTurnGuard::class)->isSuperseded($ws, $first));
    }

    /** The ordinary case: nothing newer, so nothing is dropped. */
    public function test_the_latest_question_is_never_superseded(): void
    {
        $ws = $this->workspace();
        $only = $this->userMessage($ws, 'Publish now');

        $this->assertFalse(app(SupersededTurnGuard::class)->isSuperseded($ws, $only));
        $this->assertFalse(app(SupersededTurnGuard::class)->isSuperseded($ws, 0));
    }

    private function userMessage(int $ws, string $text): int
    {
        return (int) DB::table('agent_messages')->insertGetId([
            'workspace_id' => $ws, 'agent_slug' => 'sarah', 'sender' => 'Owner',
            'content' => $text, 'role' => 'user',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agentFinal(int $ws, string $text): int
    {
        return (int) DB::table('agent_messages')->insertGetId([
            'workspace_id' => $ws, 'agent_slug' => 'sarah', 'sender' => 'Sarah',
            'content' => $text, 'role' => 'agent',
            'metadata_json' => json_encode(['phase' => 'final']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

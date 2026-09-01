<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\BlockageClaimGuard;
use App\Core\Sarah888\CommitmentLifecycle;
use App\Core\Sarah888\CommitmentStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sarah told the owner there were blockages when there were none, twice, and offered to retry blocked tasks
 * that did not exist. These hold the two halves of why that happened.
 */
class BlockageClaimGuardTest extends TestCase
{
    private function workspace(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'blk-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'blk-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function draft(int $ws, bool $withImage): void
    {
        DB::table('articles')->insert([
            'workspace_id' => $ws, 'title' => 'Draft ' . uniqid(), 'slug' => 'd-' . uniqid(),
            'content' => '<p>body</p>', 'status' => 'draft',
            'featured_image_url' => $withImage ? 'https://example.test/i.jpg' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*───────────────────────────────────────────────────── the guard */

    /** Nothing blocked, nothing failed: the claim is contradicted and must not reach the owner. */
    public function test_a_blockage_claim_is_corrected_when_nothing_is_blocked(): void
    {
        $ws = $this->workspace();
        $this->draft($ws, true);
        $this->draft($ws, false);

        $reply = 'Yes, there are still blockages preventing publication. '
               . 'We need to retry the blocked tasks first. '
               . "I haven't started anything yet; tell me to go ahead.";

        $out = app(BlockageClaimGuard::class)->validate($reply, $ws);

        $this->assertTrue($out['corrected']);
        $this->assertStringNotContainsStringIgnoringCase('blockage', $out['reply']);
        $this->assertStringNotContainsStringIgnoringCase('blocked task', $out['reply']);
        $this->assertStringContainsString('Nothing is blocked', $out['reply']);
        $this->assertStringContainsString('2 drafts', $out['reply'], 'the true position replaces the false one');
        $this->assertStringContainsString('1 still needs a featured image', $out['reply']);
        $this->assertStringContainsString("tell me to go ahead", $out['reply'],
            'the sentences she got right must survive');
    }

    /** The point is truthfulness, not silence: when something IS blocked she keeps her words. */
    public function test_a_genuine_blockage_is_left_alone(): void
    {
        $ws = $this->workspace();
        $this->draft($ws, true);
        DB::table('tasks')->insert([
            'workspace_id' => $ws, 'action' => 'write_article', 'engine' => 'write',
            'status' => 'blocked', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $reply = 'There are blockages preventing publication.';
        $out = app(BlockageClaimGuard::class)->validate($reply, $ws);

        $this->assertFalse($out['corrected']);
        $this->assertSame($reply, $out['reply']);
    }

    /** A recent failure is also a real obstruction, and she may say so. */
    public function test_a_recent_failure_also_counts_as_a_real_obstruction(): void
    {
        $ws = $this->workspace();
        DB::table('tasks')->insert([
            'workspace_id' => $ws, 'action' => 'write_article', 'engine' => 'write',
            'status' => 'failed', 'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);

        $out = app(BlockageClaimGuard::class)->validate('Some tasks are blocked.', $ws);

        $this->assertFalse($out['corrected']);
    }

    /** A reply that never claims a blockage is none of the guard's business. */
    public function test_an_innocent_reply_passes_through_untouched(): void
    {
        $ws = $this->workspace();
        $this->draft($ws, true);

        $reply = 'You have 1 draft ready. Say the word and it goes live.';
        $out = app(BlockageClaimGuard::class)->validate($reply, $ws);

        $this->assertFalse($out['corrected']);
        $this->assertSame($reply, $out['reply']);
    }

    /*───────────────────────────────────────────── the commitment backlog */

    /**
     * The reason she believed it. Commitments had no way to end: claimComplete() and verify() existed and
     * nothing ever called them, so every promise ever made stayed live and was handed to her each turn.
     */
    public function test_a_stale_commitment_expires_instead_of_staying_live_forever(): void
    {
        $ws = $this->workspace();
        $store = app(CommitmentStore::class);

        $old = (int) DB::table('sarah_commitments')->insertGetId([
            'workspace_id' => $ws, 'title' => 'harbour deli fit-out', 'status' => 'active',
            'owner' => 'sarah', 'priority' => 'medium',
            'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40),
        ]);
        $fresh = (int) DB::table('sarah_commitments')->insertGetId([
            'workspace_id' => $ws, 'title' => 'something from yesterday', 'status' => 'active',
            'owner' => 'sarah', 'priority' => 'medium',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $this->assertCount(2, $store->live($ws));

        app(CommitmentLifecycle::class)->sweep($ws, true);

        $this->assertSame('expired', DB::table('sarah_commitments')->where('id', $old)->value('status'),
            'a promise nobody has touched in weeks must stop being reported as live work');
        $this->assertSame('active', DB::table('sarah_commitments')->where('id', $fresh)->value('status'),
            'a recent commitment is still live and must not be swept away');
    }

    /** A promise is only reported as kept when the record can show it. */
    public function test_a_publishing_commitment_completes_only_once_no_drafts_remain(): void
    {
        $ws = $this->workspace();
        $this->draft($ws, true);

        $id = (int) DB::table('sarah_commitments')->insertGetId([
            'workspace_id' => $ws, 'title' => 'Publish the drafts then', 'status' => 'active',
            'owner' => 'sarah', 'priority' => 'high',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        app(CommitmentLifecycle::class)->sweep($ws, true);
        $this->assertSame('active', DB::table('sarah_commitments')->where('id', $id)->value('status'),
            'a draft still exists, so the promise is genuinely outstanding');

        DB::table('articles')->where('workspace_id', $ws)->update(['status' => 'published']);
        app(CommitmentLifecycle::class)->sweep($ws, true);

        $row = DB::table('sarah_commitments')->where('id', $id)->first();
        $this->assertSame('verified', $row->status, 'now the record shows it was kept');
        $this->assertNotEmpty($row->verification_evidence, 'and the evidence is written on the row');
    }
}

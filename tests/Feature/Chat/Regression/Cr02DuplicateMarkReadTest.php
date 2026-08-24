<?php

namespace Tests\Feature\Chat\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CR-02 — a single user action emitted two mark-read calls.
 *
 * Introduced by the 2026-07-26 read-state patch: _msgPageSelect() called
 * _msgMarkRead(slug) on two consecutive lines. Harmless to data, but it doubles
 * the server write for every page-thread open and violates clause RD-07.
 *
 * The behavioural half of this test also pins the read-state semantics the fix
 * must not disturb: marking read must still work, and must remain
 * server-authoritative.
 */
class Cr02DuplicateMarkReadTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function a_single_action_emits_exactly_one_mark_read_call(): void
    {
        $file = base_path('public/app/js/messages-ui.js');
        $this->assertFileExists($file);
        $lines = explode("\n", (string) file_get_contents($file));

        foreach ($lines as $i => $line) {
            if (!preg_match('/_msgMarkRead\s*\(/', $line)) {
                continue;
            }
            $next = $lines[$i + 1] ?? '';
            $this->assertDoesNotMatchRegularExpression(
                '/_msgMarkRead\s*\(/',
                $next,
                'messages-ui.js line ' . ($i + 1) . ' and ' . ($i + 2)
                . ' both call _msgMarkRead for one user action, producing a duplicate server write (clause RD-07)'
            );
        }
    }

    /** @test */
    public function marking_a_thread_read_still_clears_that_agent_unread(): void
    {
        DB::table('agent_messages')->insert([
            'workspace_id' => $this->testWorkspace->id,
            'agent_slug'   => 'sarah',
            'sender'       => 'agent',
            'role'         => 'agent',
            'content'      => 'unread fixture',
            'read_at'      => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $before = $this->withHeaders($this->authHeaders())->getJson('/api/messages/unread-count')->json();
        $this->assertSame(1, (int) ($before['total'] ?? 0));

        $this->withHeaders($this->authHeaders())->postJson('/api/messages/sarah/read')->assertSuccessful();

        $after = $this->withHeaders($this->authHeaders())->getJson('/api/messages/unread-count')->json();
        $this->assertSame(0, (int) ($after['total'] ?? -1),
            'marking a thread read did not clear that agent\'s unread count (clause RD-03)');
    }

    /** @test */
    public function mark_all_read_is_server_authoritative(): void
    {
        foreach (['sarah', 'james'] as $slug) {
            DB::table('agent_messages')->insert([
                'workspace_id' => $this->testWorkspace->id,
                'agent_slug'   => $slug,
                'sender'       => 'agent',
                'role'         => 'agent',
                'content'      => "unread {$slug}",
                'read_at'      => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $this->withHeaders($this->authHeaders())->postJson('/api/messages/read-all')->assertSuccessful();

        $remaining = DB::table('agent_messages')
            ->where('workspace_id', $this->testWorkspace->id)
            ->where('role', 'agent')
            ->whereNull('read_at')
            ->count();

        $this->assertSame(0, $remaining, 'mark-all-read did not clear unread server-side (clause RD-01)');
    }

    /** @test */
    public function mark_all_read_does_not_cross_workspace_boundaries(): void
    {
        DB::table('agent_messages')->insert([
            'workspace_id' => 999998,
            'agent_slug'   => 'sarah',
            'sender'       => 'agent',
            'role'         => 'agent',
            'content'      => 'another tenant',
            'read_at'      => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->withHeaders($this->authHeaders())->postJson('/api/messages/read-all')->assertSuccessful();

        $foreignStillUnread = DB::table('agent_messages')
            ->where('workspace_id', 999998)
            ->whereNull('read_at')
            ->count();

        $this->assertSame(1, $foreignStillUnread,
            'mark-all-read cleared unread messages belonging to another workspace (clause RD-06)');
    }
}

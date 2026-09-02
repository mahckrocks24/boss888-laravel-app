<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\ExecutiveFacts;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-U8-COUNT (2026-09-02): the model invented a "today" completion count for a
 * scope ExecutiveFacts never provided. It must now surface a today-scoped
 * completed count that matches the DB, so the real number is available to ground.
 */
class ExecutiveFactsTodayTest extends TestCase
{
    public function test_today_completed_count_is_present_and_matches_db(): void
    {
        $ws = 999993;
        $facts = app(ExecutiveFacts::class)->all($ws);
        $today = null;
        foreach ($facts as $f) {
            if ($f['name'] === 'tasks_completed' && $f['period'] === ExecutiveFacts::P_TODAY) {
                $today = $f['value'];
            }
        }
        $this->assertNotNull($today, 'a today-scoped tasks_completed fact must be emitted');

        $real = DB::table('tasks')->where('workspace_id', $ws)
            ->where('status', 'completed')
            ->where('created_at', '>', now()->startOfDay())
            ->count();
        $this->assertSame($real, $today, 'today completed count must equal DB');
    }
}

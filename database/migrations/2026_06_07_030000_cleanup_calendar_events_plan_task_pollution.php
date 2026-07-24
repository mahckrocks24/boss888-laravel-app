<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cleanup migration: delete plan_task pollution rows from calendar_events.
 *
 * These rows were created by B11/B12 smoke testing when PlanSchedulerService
 * incorrectly wrote AI plan task events into calendar_events (the user's
 * personal calendar table). B19 Phase 2 fixed the writer to use
 * automation_events; this migration purges the orphan pollution.
 *
 * Idempotent: counts before + after, only deletes plan_task rows.
 */
return new class extends Migration {
    public function up(): void
    {
        $before = DB::table('calendar_events')->count();
        $pollutionCount = DB::table('calendar_events')
            ->where('reference_type', 'plan_task')
            ->count();

        $deleted = DB::table('calendar_events')
            ->where('reference_type', 'plan_task')
            ->delete();

        $after = DB::table('calendar_events')->count();

        Log::info('B19b Phase 3 cleanup completed', [
            'calendar_events_before'    => $before,
            'pollution_rows_targeted'   => $pollutionCount,
            'pollution_rows_deleted'    => $deleted,
            'calendar_events_after'     => $after,
        ]);
    }

    public function down(): void
    {
        // Not reversible — pollution data is irrecoverable and was test data anyway.
        // No-op down() is safer than a fake "restore" that can't actually restore.
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename plan_tasks.calendar_event_id → plan_tasks.automation_event_id.
 *
 * Semantic alignment: B11 originally wrote plan task events into
 * calendar_events. Phase 2 reroutes them to automation_events (the
 * agent-timeline table created in Phase 1). The column name reflects
 * the new target.
 *
 * Safe: no FK constraint on the column (verified during audit).
 * Existing index on the column is preserved through the rename.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plan_tasks', function (Blueprint $t) {
            $t->renameColumn('calendar_event_id', 'automation_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('plan_tasks', function (Blueprint $t) {
            $t->renameColumn('automation_event_id', 'calendar_event_id');
        });
    }
};
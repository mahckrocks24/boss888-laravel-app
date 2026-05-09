<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C — Add the genuinely-missing task tracking columns.
 *
 * Spec deviations vs the original ask:
 *   - tasks.status is ALREADY a 10-value enum that covers the same ground
 *     as the proposed parallel `state` column. Adding a duplicate `state`
 *     was rejected — using `status` directly.
 *   - `started_at`, `completed_at`, `error_text`, `retry_count` already
 *     exist; this migration does not re-add them.
 *
 * Idempotent: each column / index addition checks first so a partially
 * applied previous run can safely re-run.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (!Schema::hasColumn('tasks', 'queued_at'))     $t->timestamp('queued_at')->nullable()->after('updated_at');
            if (!Schema::hasColumn('tasks', 'dispatched_at')) $t->timestamp('dispatched_at')->nullable()->after('queued_at');
            if (!Schema::hasColumn('tasks', 'failed_at'))     $t->timestamp('failed_at')->nullable()->after('dispatched_at');
            if (!Schema::hasColumn('tasks', 'cancelled_at'))  $t->timestamp('cancelled_at')->nullable()->after('failed_at');
            if (!Schema::hasColumn('tasks', 'duration_ms'))   $t->unsignedInteger('duration_ms')->nullable()->after('cancelled_at');
            if (!Schema::hasColumn('tasks', 'max_attempts')) $t->smallInteger('max_attempts')->default(3)->after('retry_count');
            if (!Schema::hasColumn('tasks', 'error_trace'))   $t->text('error_trace')->nullable()->after('error_text');
        });

        // Indexes — only add if missing.
        $existing = collect(DB::select("SHOW INDEX FROM tasks"))->pluck('Key_name')->unique()->all();
        Schema::table('tasks', function (Blueprint $t) use ($existing) {
            // tasks_workspace_id_status_index is already present from an earlier
            // migration — skip. Add a status+updated_at compound for the orphan
            // detector, which scans WHERE status IN (...) AND updated_at < ?.
            if (!in_array('tasks_status_updated_at_index', $existing, true)) {
                $t->index(['status', 'updated_at'], 'tasks_status_updated_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $existing = collect(DB::select("SHOW INDEX FROM tasks"))->pluck('Key_name')->unique()->all();
            if (in_array('tasks_status_updated_at_index', $existing, true)) {
                $t->dropIndex('tasks_status_updated_at_index');
            }

            foreach (['queued_at','dispatched_at','failed_at','cancelled_at','duration_ms','max_attempts','error_trace'] as $col) {
                if (Schema::hasColumn('tasks', $col)) $t->dropColumn($col);
            }
        });
    }
};

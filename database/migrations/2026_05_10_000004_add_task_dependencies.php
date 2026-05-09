<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2F — task dependency graph columns.
 *
 * Spec deviations:
 *   - `plan_id` was renamed `execution_plan_id` because tasks already has
 *     `plan_task_id` (different concept — references a parent task that
 *     represents a plan). Using a dedicated string column for the new
 *     ExecutionPlan UUIDs avoids the collision.
 *   - `unlocks` was dropped — it's the inverse of `depends_on` and
 *     duplicating it just creates write amplification with no read benefit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (!Schema::hasColumn('tasks', 'execution_plan_id')) {
                $t->string('execution_plan_id', 60)->nullable()->after('plan_task_id')->index();
            }
            if (!Schema::hasColumn('tasks', 'depends_on')) {
                $t->json('depends_on')->nullable()->after('execution_plan_id');
            }
            if (!Schema::hasColumn('tasks', 'sequence_order')) {
                $t->unsignedSmallInteger('sequence_order')->default(0)->after('depends_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (Schema::hasColumn('tasks', 'execution_plan_id')) {
                $t->dropIndex(['execution_plan_id']);
                $t->dropColumn('execution_plan_id');
            }
            if (Schema::hasColumn('tasks', 'depends_on'))     $t->dropColumn('depends_on');
            if (Schema::hasColumn('tasks', 'sequence_order')) $t->dropColumn('sequence_order');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen execution_plans.status from varchar(20) to varchar(40).
 *
 * Root cause: SarahOrchestrator::finalizePlan writes 'completed_with_errors'
 * (21 chars) for plans with any failed tasks. Previous column width caused
 * SQLSTATE[22001] — plan never finalized, stuck in 'executing' forever.
 *
 * All existing status values fit easily in the new width. Existing data
 * is preserved by raw ALTER (no truncation).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE execution_plans MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE execution_plans MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'");
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hotfix 2026-06-30 — stop the autonomous orphan-rescue / link pipeline from
 * crashing on 1406 "Data too long" truncations.
 *
 *  - seo_links.source_url / target_url were varchar(255). Real article URLs
 *    (and the malformed long draft-slug URLs the link suggester currently
 *    emits) overflow that, so the insert 1406-fails → orphan rescue applies 0
 *    links → reports "completed" while doing nothing. Widen to 512.
 *  - task_events.message was varchar(500). When a step error is itself long
 *    (e.g. a nested SQL exception), logging the error TRUNCATION-fails, which
 *    masks the real failure and cascades. Promote to TEXT.
 *
 * NOTE (flagged separately): the link suggester is generating malformed
 * target_urls from draft bodies (garbage slugs). Widening stops the crash but
 * the upstream slug bug still needs fixing so inserted links aren't 404s.
 *
 * Idempotent: re-running just re-applies the same column types. No data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_links')) {
            DB::statement("ALTER TABLE `seo_links` MODIFY `source_url` VARCHAR(512) NULL");
            DB::statement("ALTER TABLE `seo_links` MODIFY `target_url` VARCHAR(512) NULL");
        }
        if (Schema::hasTable('task_events') && Schema::hasColumn('task_events', 'message')) {
            DB::statement("ALTER TABLE `task_events` MODIFY `message` TEXT NULL");
        }
    }

    public function down(): void
    {
        // Intentionally NOT narrowing back — shrinking could truncate live data.
        // Down is a no-op (these are widening-only, safe-forward changes).
    }
};

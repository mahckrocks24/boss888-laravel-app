<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 — widen `ad_settings.description` from VARCHAR(255) to TEXT.
 *
 * WHY
 * These descriptions are operator-facing documentation shown next to each
 * switch in the admin console, and several now carry the reasoning that makes a
 * setting safe to change — for example that `modal_close_delay_ms` gates all
 * three dismissal paths and that raising it materially is a legal question, not
 * a tuning decision. That does not fit in 255 characters, and writing it
 * truncated would leave the most important half of the warning invisible
 * exactly where someone is about to change the value.
 *
 * Widening only. No existing value can be affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_settings')) {
            return;
        }

        DB::statement('ALTER TABLE `ad_settings` MODIFY `description` TEXT NULL');
    }

    public function down(): void
    {
        // Deliberately not narrowing back: any description over 255 characters
        // would be silently truncated, which is the failure this migration exists
        // to remove.
        if (Schema::hasTable('ad_settings')) {
            DB::statement('ALTER TABLE `ad_settings` MODIFY `description` TEXT NULL');
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 — widen `ad_events.event` from varchar(12) to varchar(24).
 *
 * WHY
 * The original column was sized for `request|impression|viewable|click`, the
 * longest of which is 10 characters. The interstitial adds `video_complete`
 * (14) and `video_start` (11). MySQL rejected the insert outright:
 *
 *   SQLSTATE[22001] Data too long for column 'event' at row 101
 *
 * That matters more than a failed insert: **CPCV bills on `video_complete`**, so
 * a video interstitial would have delivered, been watched to the end, and
 * recorded nothing to bill from. Caught by the interstitial test suite before
 * anything shipped.
 *
 * WIDENING ONLY — no data is rewritten, no value is truncated, and every
 * existing row remains valid. 24 leaves headroom for a longer event name later
 * without another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_events')) {
            return;
        }

        // Raw DDL rather than Blueprint::change(), which would require
        // doctrine/dbal and would rewrite the column comment.
        DB::statement(
            "ALTER TABLE `ad_events` MODIFY `event` VARCHAR(24) NOT NULL "
            . "COMMENT 'request | impression | viewable | click | video_* | dismiss'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_events')) {
            return;
        }

        // Deliberately NOT narrowing back to 12: any row holding a video event
        // would be silently truncated, which is worse than an over-wide column.
        DB::statement(
            "ALTER TABLE `ad_events` MODIFY `event` VARCHAR(24) NOT NULL "
            . "COMMENT 'request | impression | viewable | click'"
        );
    }
};

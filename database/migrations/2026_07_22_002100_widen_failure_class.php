<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen social_posts.failure_class.
 *
 * Found by the hardening tests, not in production — which is exactly where you
 * want to find it. The column was varchar(32), but the approval-drift codes are
 * longer:
 *
 *   'permanent:APPROVAL_VOID_CAPTION_CHANGED'  = 39 chars
 *   'permanent:APPROVAL_VOID_MEDIA_CHANGED'    = 37 chars
 *
 * MySQL raised "Data too long" and the UPDATE threw — inside the failure
 * handler. A post that failed for a legitimate reason would have thrown a
 * database exception instead of recording why, losing the diagnosis at the exact
 * moment it mattered. Widened with headroom for future reason codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE social_posts MODIFY failure_class VARCHAR(96) NULL');
    }

    public function down(): void
    {
        // Truncate anything that would not fit before narrowing again.
        DB::statement("UPDATE social_posts SET failure_class = LEFT(failure_class, 32) WHERE CHAR_LENGTH(failure_class) > 32");
        DB::statement('ALTER TABLE social_posts MODIFY failure_class VARCHAR(32) NULL');
    }
};

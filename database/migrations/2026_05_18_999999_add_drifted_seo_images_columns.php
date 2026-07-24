<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1D §8 — reconcile out-of-band schema drift on `seo_images`.
 *
 * THE DRIFT
 * ---------
 * Staging's `seo_images` carries four columns that NO migration ever creates:
 *
 *     size_bytes      (referenced in 12 application files)
 *     content_type    (referenced in 6)
 *     scan_method     (referenced in 1)
 *     last_probed_at  (referenced in 0)
 *
 * They were added out-of-band — direct SQL, or inherited from a restored dump —
 * so the migration chain has no knowledge of them. Two consequences:
 *
 *   1. A clean install lacks them, breaking 12+ files at runtime.
 *   2. `2026_05_19_000001_extend_seo_images_for_optimization` positions a column
 *      with `->after('scan_method')`, which succeeds only because the drift
 *      happens to exist. On a fresh database it fails with
 *      SQLSTATE[42S22] 1054 Unknown column 'scan_method'.
 *
 * WHY THIS FILENAME IS BACK-DATED
 * -------------------------------
 * Deliberate. Migrations run in filename order, so 2026_05_18_999999 executes
 * BEFORE 2026_05_19_000001 on a clean install — `scan_method` exists by the time
 * the extend migration needs it. That repairs fresh installs WITHOUT editing
 * another historical migration, which is the additive strategy the directive
 * prefers. On an already-migrated database every column is present, so each
 * hasColumn guard short-circuits and this migration is a no-op.
 *
 * Types mirror what staging actually has, so a clean install produces the same
 * schema as the live environment rather than a plausible-looking variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seo_images')) {
            return;
        }

        Schema::table('seo_images', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_images', 'size_bytes')) {
                $table->unsignedInteger('size_bytes')->nullable()->after('height');
            }
            if (!Schema::hasColumn('seo_images', 'content_type')) {
                $table->string('content_type', 100)->nullable()->after('size_bytes');
            }
            if (!Schema::hasColumn('seo_images', 'last_probed_at')) {
                $table->timestamp('last_probed_at')->nullable()->after('content_type');
            }
            if (!Schema::hasColumn('seo_images', 'scan_method')) {
                $table->string('scan_method', 20)->nullable()->after('last_probed_at');
            }
        });
    }

    public function down(): void
    {
        // Intentionally NOT dropping these columns. They hold live data on
        // existing environments and were never owned by the migration chain;
        // reversing this migration must not destroy them.
    }
};

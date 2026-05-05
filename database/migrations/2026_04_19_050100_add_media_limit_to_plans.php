<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — media library plan gating.
 *
 * Adds `media_library_limit` to the `plans` table and backfills per-tier
 * values per pricing spec:
 *   0  = no access (Free, Starter)
 *   -1 = unlimited (Pro, Agency)
 *
 * Enforcement lives in App\Services\MediaAccessService.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'media_library_limit')) {
                $table->integer('media_library_limit')->default(0)->after('credit_limit');
            }
        });

        // Backfill per plan slug. Any plan not in this map keeps its default (0).
        $limits = [
            'free'     => 0,
            'starter'  => 0,
            'ai-lite'  => 50,
            'growth'   => 200,
            'pro'      => -1,
            'agency'   => -1,
        ];
        foreach ($limits as $slug => $limit) {
            DB::table('plans')->where('slug', $slug)->update(['media_library_limit' => $limit]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'media_library_limit')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('media_library_limit');
            });
        }
    }
};

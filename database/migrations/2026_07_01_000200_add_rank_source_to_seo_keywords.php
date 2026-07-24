<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-01 — add seo_keywords.rank_source so the two rank sources layer
 * cleanly (SEO↔Sarah alignment forensic, precedence decision: GSC wins where
 * present, DataForSEO fills the rest). 'gsc' = real first-party Google position;
 * 'dataforseo' = platform SERP scrape. Lets seo:track-ranks avoid clobbering a
 * fresh GSC rank, and lets the gatherer/brief show provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seo_keywords')) return;
        Schema::table('seo_keywords', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_keywords', 'rank_source')) {
                $table->string('rank_source', 20)->nullable()->after('current_rank');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('seo_keywords')) return;
        Schema::table('seo_keywords', function (Blueprint $table) {
            if (Schema::hasColumn('seo_keywords', 'rank_source')) {
                $table->dropColumn('rank_source');
            }
        });
    }
};

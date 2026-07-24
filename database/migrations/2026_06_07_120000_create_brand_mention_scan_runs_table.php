<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_mention_scan_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('watchlist_id');
            $t->string('scan_type', 20)->default('scheduled');
                // scheduled | manual | backfill
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('results_found')->default(0);
            $t->unsignedInteger('results_new')->default(0);
                // after dedup vs brand_mentions
            $t->unsignedInteger('credits_charged')->default(0);
            $t->string('backend_used', 40)->nullable();
                // dataforseo | duckduckgo | manual
            $t->text('error')->nullable();
            $t->json('metadata_json')->nullable();
                // {query, location_code, language_code, variants_scanned, ...}
            $t->timestamps();

            $t->foreign('watchlist_id')->references('id')->on('brand_watchlist')->cascadeOnDelete();
            $t->index(['workspace_id', 'started_at']);
            $t->index(["workspace_id", "watchlist_id", "started_at"], "bmsr_ws_wl_started_idx");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_mention_scan_runs');
    }
};
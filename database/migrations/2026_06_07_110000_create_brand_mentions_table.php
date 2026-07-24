<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_mentions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('watchlist_id');
            $t->string('term_matched', 200);
            $t->string('source_url', 500);
            $t->string('source_url_hash', 64);
                // sha256(source_url) — dedup anchor
            $t->string('source_title', 512)->nullable();
            $t->string('source_domain', 255);
            $t->string('source_type', 30)->default('other');
                // news | blog | forum | review | qa | directory | social | other
            $t->text('excerpt')->nullable();
                // context window around the mention (~300 chars)
            $t->timestamp('published_at')->nullable();
            $t->timestamp('discovered_at');
            $t->string('sentiment', 20)->default('unknown');
                // positive | neutral | negative | mixed | unknown
            $t->decimal('sentiment_confidence', 3, 2)->nullable();
                // 0.00 - 1.00
            $t->string('priority', 10)->default('normal');
                // low | normal | high  (computed from sentiment × scope × authority)
            $t->string('status', 20)->default('new');
                // new | triaged | responded | spam | ignored | resolved
            $t->text('triage_notes')->nullable();
            $t->unsignedBigInteger('triaged_by')->nullable();
            $t->timestamp('triaged_at')->nullable();
            $t->json('metadata_json')->nullable();
                // full SERP row + any provider metadata
            $t->timestamps();

            $t->foreign('watchlist_id')->references('id')->on('brand_watchlist')->cascadeOnDelete();
            $t->index(['workspace_id', 'status', 'discovered_at']);
            $t->index(['workspace_id', 'watchlist_id']);
            $t->index(['workspace_id', 'sentiment', 'priority']);
            $t->index(['workspace_id', 'source_domain']);
            // Dedup: same workspace + same URL hash → unique. We keep
            // watchlist_id out of the natural key so that the same URL
            // discovered via two different watchlist terms still counts
            // as one mention (the first scanner to find it wins).
            $t->unique(['workspace_id', 'source_url_hash'], 'brand_mentions_ws_urlhash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_mentions');
    }
};
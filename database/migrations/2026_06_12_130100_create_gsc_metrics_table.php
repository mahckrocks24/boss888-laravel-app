<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Search Console — raw per-(page,query,date) performance rows.
 *
 * Laravel persists the RAW rows pulled from the Search Console API; it does
 * NOT score or rank them. All intelligence (striking-distance, CTR-gap,
 * decline, cannibalization, opportunity ranking) lives in the Node runtime
 * (gsc_intelligence tool). The UNIQUE key makes re-sync idempotent (upsert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('site_url');
            $table->string('page', 768);
            $table->string('query', 512);
            // md5(site_url|page|query|date) — idempotent re-sync anchor that
            // stays under the index key-length limit (page+query can be long
            // URLs / phrases that would overflow a composite string unique).
            $table->char('row_hash', 32);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);      // 0..1
            $table->decimal('position', 8, 2)->default(0);
            $table->date('date');
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'row_hash'], 'gsc_metrics_ws_rowhash_unique');
            $table->index(['workspace_id', 'date']);
            $table->index(['workspace_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_metrics');
    }
};

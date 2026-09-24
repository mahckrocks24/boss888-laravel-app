<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K8 (2026-09-25) — where a business fact is represented publicly.
 *
 * The question this answers is "if this phone number changes, what is now
 * stale?", and it is only answerable if something records which fact reached
 * which page and which schema node.
 *
 * Deliberately NOT a knowledge graph. One row per (fact, page, property). The
 * value hash is what makes drift detectable: when the fact's current value no
 * longer hashes to what was published, that page is stale.
 *
 * Nothing writes here on a page render. A request that rendered a site would
 * otherwise write a row per fact per page, which is the boot-time storm this
 * platform has already paid for once. The rows are produced by an explicit
 * scan (business:fact-usage), so the hot path stays read-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('website_id');
            $table->string('page_url', 512);
            // 'identity' = a column on businesses; 'claim' = a business_facts row.
            $table->string('fact_type', 16);
            // the identity field name, or the business_facts id as a string
            $table->string('fact_key', 64);
            // the schema.org property it was published as
            $table->string('property', 64);
            // the @id of the node carrying it, so a change can be pointed at
            $table->string('node_id', 512)->nullable();
            // sha1 of the published value: drift is a hash mismatch
            $table->string('value_hash', 40);
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['business_id', 'fact_type', 'fact_key'], 'fact_usages_fact_idx');
            $table->index(['website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_usages');
    }
};

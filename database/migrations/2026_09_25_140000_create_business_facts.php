<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K7 (2026-09-25) — evidence-bearing business claims.
 *
 * This is NOT a knowledge warehouse. K4 put the SINGULAR identity facts on the
 * business itself, as columns, because a business has one phone number and one
 * address. This table exists for the claims that are inherently PLURAL and that
 * a reader is entitled to doubt: awards, certifications, memberships,
 * statistics, testimonials. Those need a source and a status, not a column.
 *
 * The discipline is the same as K4's identity_meta_json and is enforced in one
 * place (BusinessFact::isPublishable): a claim reaches public structured data
 * only when its source is owner_stated, verified or imported AND the owner has
 * chosen to publish it. A claim a model wrote is stored, visible, and silent.
 *
 * `confidence` is nullable on purpose. It is meaningful for something observed
 * or inferred and meaningless for something the owner stated, and a number
 * invented to fill a column is worse than an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_facts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('kind', 32);
            $table->string('label', 200);
            $table->text('value')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->text('evidence')->nullable();
            $table->string('source', 24)->default('proposed');
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('occurred_on', 32)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'kind']);
            $table->index(['business_id', 'published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_facts');
    }
};

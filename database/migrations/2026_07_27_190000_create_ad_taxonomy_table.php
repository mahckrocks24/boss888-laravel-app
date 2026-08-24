<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 P0a — controlled vocabularies for ad targeting.
 *
 * WHY THIS TABLE EXISTS
 * Advertisers cannot target free text. `workspaces.industry` currently holds
 * values like "shelving installation", "private chef" and "Travel & Tours";
 * `workspaces.location` holds "New Jersey, USA (serving NJ, NY, CT)". None of
 * that is sellable as a targeting dimension. Every targetable attribute must
 * resolve to a code in a controlled vocabulary, and this table IS that
 * vocabulary — one row per (kind, code).
 *
 * KINDS
 *   archetype  9 business archetypes, mirrored from Builder TemplateArchetypes
 *   industry   31 template industries; parent_code = its archetype
 *   iab        IAB Content Taxonomy 3.0 categories (buyer-facing vocabulary)
 *   interest   contextual interest vocabulary (see AdInterestTaxonomy)
 *
 * The PHP support classes are the source of truth for the *content* of these
 * vocabularies; this table is the queryable projection of them, seeded by
 * `ads:sync-taxonomy`. That split keeps the vocabulary reviewable in code
 * (diffable in git) while still being joinable in SQL for reporting.
 *
 * ADDITIVE ONLY — creates a new table. Touches nothing that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_taxonomy')) {
            return; // idempotent: safe to re-run
        }

        Schema::create('ad_taxonomy', function (Blueprint $table) {
            $table->id();

            $table->string('kind', 24)->comment('archetype|industry|iab|interest');
            $table->string('code', 96)->comment('stable machine code — never renamed once published');
            $table->string('label', 191)->comment('human label shown in the admin console');

            $table->string('parent_code', 96)->nullable()
                ->comment('industry->archetype hierarchy; null for roots');

            $table->boolean('is_active')->default(true)
                ->comment('false hides from targeting UI without breaking existing campaigns');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // A code is unique within its vocabulary, not globally: "medical"
            // is legitimately both an archetype and (conceptually) an interest.
            $table->unique(['kind', 'code'], 'ad_taxonomy_kind_code_unique');
            $table->index(['kind', 'is_active'], 'ad_taxonomy_kind_active_idx');
            $table->index('parent_code', 'ad_taxonomy_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_taxonomy');
    }
};

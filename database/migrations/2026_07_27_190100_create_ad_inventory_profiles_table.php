<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 P0a — the sellable description of one website.
 *
 * WHY THIS TABLE EXISTS
 * A campaign targets attributes ("medical clinics in the UAE"), but those
 * attributes live scattered across free-text `workspaces.industry`,
 * free-text `workspaces.location`, a frequently-NULL `websites.template_industry`,
 * `seo_keywords`, `workspace_memory` and the site's own template variables.
 * Resolving that per ad request would be both slow and non-deterministic.
 * This table is the resolved, cached, auditable answer — computed offline,
 * read cheaply at decision time.
 *
 * THE RULE THIS SCHEMA ENFORCES
 * `source` and `confidence` are first-class columns, not metadata. Inventory
 * that resolved to source='unknown' or a low confidence MUST NOT be sold to a
 * targeted campaign — it serves house ads only. An advertiser gets what they
 * bought or they get nothing; never a guess. Storing *how* we decided is what
 * makes that rule enforceable rather than aspirational.
 *
 * `evidence` records which signal produced which field, so any profile can be
 * explained to an advertiser (or disputed by one) long after it was computed.
 *
 * DESIGN NOTES
 * - No FOREIGN KEY to `websites`. That table uses soft deletes and the sites
 *   directory already contains ~250 orphans; a cascading FK would either block
 *   deletes or silently destroy profile history. Orphans are reaped explicitly
 *   by `ads:profile-inventory --prune` instead.
 * - `business_country` is ISO-3166-1 alpha-2. Two chars, indexed, never a name.
 * - Confidence columns are DECIMAL(3,2) — exact 0.00..1.00, no float drift in
 *   a value that gates whether inventory may be sold.
 *
 * ADDITIVE ONLY — creates a new table. Touches nothing that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_inventory_profiles')) {
            return; // idempotent: safe to re-run
        }

        Schema::create('ad_inventory_profiles', function (Blueprint $table) {
            $table->id();

            // ── Identity ────────────────────────────────────────────────
            $table->unsignedBigInteger('website_id')->unique()
                ->comment('one profile per website; no FK — see class docblock');
            $table->unsignedBigInteger('workspace_id')->index();

            // ── Industry (§7A.4) ────────────────────────────────────────
            $table->string('industry_slug', 64)->nullable()
                ->comment('one of the 31 template industries; null when unresolved');
            $table->string('archetype', 48)->nullable()
                ->comment('one of the 9 business archetypes');
            $table->json('iab_categories')->nullable()
                ->comment('IAB Content Taxonomy 3.0 codes — buyer-facing vocabulary');

            // ── Business location (§7A.3) ───────────────────────────────
            // NOTE: this is where the PUBLISHER's business is. Visitor country
            // is a request-time attribute and is deliberately NOT stored here.
            $table->char('business_country', 2)->nullable()
                ->comment('ISO-3166-1 alpha-2');
            $table->string('business_region', 96)->nullable();
            $table->string('business_city', 96)->nullable();
            $table->json('service_areas')->nullable()
                ->comment('additional areas served, e.g. ["NJ","NY","CT"]');
            $table->decimal('location_confidence', 3, 2)->default(0);

            // ── Interests (§7A.5) — contextual, never behavioural ────────
            $table->json('interests')->nullable()
                ->comment('controlled interest codes derived from site content');
            $table->json('content_topics')->nullable()
                ->comment('raw topical phrases; descriptive, not targetable');

            // ── Descriptive ─────────────────────────────────────────────
            $table->json('languages')->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->boolean('has_blog')->default(false);
            $table->decimal('quality_score', 3, 2)->default(0)
                ->comment('inventory richness 0..1 — completeness, not editorial judgement');

            // ── Provenance — the columns that gate saleability ───────────
            $table->decimal('confidence', 3, 2)->default(0);
            $table->string('source', 24)->default('unknown')
                ->comment('explicit|template|classified|ai|unknown');
            $table->json('evidence')->nullable()
                ->comment('which signal produced which field — auditability');

            $table->timestamp('profiled_at')->nullable();
            $table->timestamp('stale_after')->nullable()
                ->comment('re-profile after this instant');

            $table->timestamps();

            // Decision-time lookup is by website_id (unique, above).
            // These serve the admin console, the reach estimator and reporting.
            $table->index(['industry_slug', 'business_country'], 'ad_inv_industry_country_idx');
            $table->index(['archetype', 'business_country'], 'ad_inv_archetype_country_idx');
            $table->index(['source', 'confidence'], 'ad_inv_source_confidence_idx');
            $table->index('stale_after', 'ad_inv_stale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_inventory_profiles');
    }
};

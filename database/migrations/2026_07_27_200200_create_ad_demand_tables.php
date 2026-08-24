<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 P0 — demand side: who is advertising, what they bought, what runs.
 *
 * HOUSE AND PAID SHARE THESE TABLES.
 * `ad_campaigns.kind` distinguishes them ('house' | 'paid'). One code path, one
 * reporting surface, no parallel system to keep in sync. House campaigns are
 * kind=house, pricing_model=flat, rate_micros=0, priority_tier=5 — so a sold
 * impression (tier 1) always DISPLACES a house ad rather than competing with it,
 * and fill rate is 100% from day one.
 *
 * MONEY IS INTEGER MICROS EVERYWHERE.
 * No floats in the billing path. $1.25 CPM is 1_250_000 micros. Rounding errors
 * in an advertiser invoice are not recoverable reputationally.
 *
 * ADDITIVE ONLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('advertisers')) {
            Schema::create('advertisers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                $table->string('legal_name', 191)->nullable();
                $table->string('contact_email', 191)->nullable();
                $table->string('status', 16)->default('active')
                    ->comment('active | paused | blocked');
                $table->string('category', 64)->nullable()
                    ->comment('checked against the category blocklist at review time');
                $table->string('click_domain', 191)->nullable()
                    ->comment('expected destination domain; re-checked at serve time');
                $table->string('stripe_customer_id', 96)->nullable();
                $table->json('billing_address')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_house')->default(false)
                    ->comment('the platform itself, for house campaigns');
                $table->timestamps();

                $table->index('status', 'advertisers_status_idx');
            });
        }

        if (! Schema::hasTable('ad_campaigns')) {
            Schema::create('ad_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('advertiser_id');
                $table->string('name', 191);
                $table->string('kind', 8)->default('paid')
                    ->comment('house | paid');
                $table->string('status', 16)->default('draft')
                    ->comment('draft | pending | active | paused | completed | rejected');

                $table->string('pricing_model', 8)->default('cpm')
                    ->comment('cpm | cpc | flat');
                $table->unsignedBigInteger('rate_micros')->default(0)
                    ->comment('price per 1000 impressions (cpm) or per click (cpc), in micros');
                $table->unsignedBigInteger('budget_total_micros')->nullable()
                    ->comment('null = uncapped (house campaigns)');
                $table->unsignedBigInteger('budget_daily_micros')->nullable();
                $table->string('pacing', 8)->default('even')
                    ->comment('even | asap');

                $table->unsignedTinyInteger('priority_tier')->default(1)
                    ->comment('1 = direct-sold, 5 = house. Lower wins.');

                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'priority_tier'], 'ad_campaigns_status_tier_idx');
                $table->index('advertiser_id', 'ad_campaigns_advertiser_idx');
                $table->index(['starts_at', 'ends_at'], 'ad_campaigns_flight_idx');
            });
        }

        if (! Schema::hasTable('ad_creatives')) {
            Schema::create('ad_creatives', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->unsignedBigInteger('slot_id');
                $table->string('type', 8)->default('image')
                    ->comment('image | html');
                $table->string('asset_url', 512)->nullable();
                $table->text('html')->nullable()
                    ->comment('for type=html; sanitised at render, never trusted raw');
                $table->string('click_url', 512);
                $table->string('alt_text', 255)->nullable();
                $table->unsignedSmallInteger('weight')->default(100)
                    ->comment('relative weight for rotation within a tier');

                $table->string('review_state', 12)->default('pending')
                    ->comment('pending | approved | rejected — NOTHING serves unapproved');
                $table->string('review_note', 255)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();

                $table->timestamps();

                $table->index(['campaign_id', 'review_state'], 'ad_creatives_campaign_review_idx');
                $table->index(['slot_id', 'review_state'], 'ad_creatives_slot_review_idx');
            });
        }

        if (! Schema::hasTable('ad_targeting')) {
            Schema::create('ad_targeting', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id');
                $table->string('dimension', 32)
                    ->comment('business_country|business_region|visitor_country|archetype|industry|iab_category|interest|page_type|device|language|website|min_confidence');
                $table->string('operator', 8)->default('in')
                    ->comment('in | not_in | gte');
                $table->json('values');
                $table->timestamps();

                $table->index(['campaign_id', 'dimension'], 'ad_targeting_campaign_dim_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_targeting');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('advertisers');
    }
};

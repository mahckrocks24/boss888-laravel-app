<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 P0 — supply side: what slots exist, and where they may appear.
 *
 * ad_slots            Global slot definitions. Only `footer_sticky` is enabled at
 *                     launch (verified against all 31 templates); the other two
 *                     ship DEFINED BUT DISABLED so enabling them later is an
 *                     admin toggle rather than a deploy.
 *
 * ad_slot_eligibility Per-template-industry opt-out. Seeded from the structural
 *                     audit: news_channel is excluded from in_content_mrec (it
 *                     has no data-block attributes at all) and from
 *                     footer_leaderboard (different footer markup).
 *
 * ad_site_overrides   The brand-safety escape hatch. force_off / house_only /
 *                     normal for one specific website, with a required reason.
 *                     When one tenant site becomes a problem, this is how it is
 *                     handled in seconds without touching a campaign.
 *
 * ADDITIVE ONLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_slots')) {
            Schema::create('ad_slots', function (Blueprint $table) {
                $table->id();
                $table->string('code', 48)->unique()
                    ->comment('stable machine code referenced by creatives');
                $table->string('name', 96);
                $table->string('format', 48)
                    ->comment('IAB format name, e.g. leaderboard, mrec, mobile_banner');
                $table->unsignedSmallInteger('width');
                $table->unsignedSmallInteger('height');
                $table->string('position', 32)
                    ->comment('footer_sticky | before_footer | in_content');
                $table->string('device', 16)->default('all')
                    ->comment('all | mobile | desktop');
                $table->boolean('is_active')->default(false)
                    ->comment('ships false for every slot except the launch slot');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index('is_active', 'ad_slots_active_idx');
            });
        }

        if (! Schema::hasTable('ad_slot_eligibility')) {
            Schema::create('ad_slot_eligibility', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('slot_id');
                $table->string('template_industry', 64)
                    ->comment('one of the 31 template industries');
                $table->boolean('enabled')->default(true);
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->unique(['slot_id', 'template_industry'], 'ad_slot_elig_unique');
                $table->index('slot_id', 'ad_slot_elig_slot_idx');
            });
        }

        if (! Schema::hasTable('ad_site_overrides')) {
            Schema::create('ad_site_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('website_id');
                $table->unsignedBigInteger('slot_id')->nullable()
                    ->comment('null = applies to every slot on this site');
                $table->string('mode', 16)->default('normal')
                    ->comment('normal | house_only | force_off');
                $table->string('reason', 255);
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();

                $table->unique(['website_id', 'slot_id'], 'ad_site_override_unique');
                $table->index('website_id', 'ad_site_override_site_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_site_overrides');
        Schema::dropIfExists('ad_slot_eligibility');
        Schema::dropIfExists('ad_slots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 P0 — delivery and measurement.
 *
 * ad_events       One row per request / impression / viewable / click.
 *                 HIGH VOLUME — pruned on a retention horizon (default 90 days)
 *                 after the nightly rollup. Reports must NEVER read this table;
 *                 they read ad_stats_daily. Only reconciliation and the
 *                 invalid-traffic drill-down touch it, always date-bounded.
 *
 *                 `is_invalid` + `invalid_reason` are recorded, NOT dropped.
 *                 Being able to show an advertiser exactly what was filtered and
 *                 why is worth more than the impressions it removes.
 *
 * ad_stats_daily  The nightly rollup every report reads. UNIQUE on the full
 *                 grain so the rollup is idempotent and can be re-run for a date
 *                 without double-counting.
 *
 * ad_frequency    Server-side frequency capping. Keyed on a SALTED, ROTATING
 *                 ip_hash and bucketed by day, so rows cannot be joined across
 *                 days to reconstruct a person. That boundary is what keeps
 *                 clause 9 of the advertising terms true.
 *
 * ADDITIVE ONLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_events')) {
            Schema::create('ad_events', function (Blueprint $table) {
                $table->id();
                $table->string('event', 12)
                    ->comment('request | impression | viewable | click');

                $table->unsignedBigInteger('creative_id')->nullable();
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->unsignedBigInteger('website_id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('slot_id')->nullable();

                $table->char('visitor_country', 2)->nullable()
                    ->comment('CF-IPCountry at request time — aggregate reporting only');
                $table->string('device', 8)->nullable();

                $table->char('ip_hash', 64)->nullable()
                    ->comment('salted + daily-rotating; not joinable across days by design');
                $table->char('ua_hash', 64)->nullable();

                $table->boolean('is_invalid')->default(false);
                $table->string('invalid_reason', 48)->nullable()
                    ->comment('crawler | datacenter | no_js | rate | timing | token');

                $table->timestamp('occurred_at')->useCurrent();

                $table->index(['occurred_at', 'event'], 'ad_events_time_event_idx');
                $table->index(['campaign_id', 'occurred_at'], 'ad_events_campaign_time_idx');
                $table->index(['website_id', 'occurred_at'], 'ad_events_site_time_idx');
                $table->index(['is_invalid', 'occurred_at'], 'ad_events_invalid_idx');
            });
        }

        if (! Schema::hasTable('ad_stats_daily')) {
            Schema::create('ad_stats_daily', function (Blueprint $table) {
                $table->id();
                $table->date('stat_date');
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->unsignedBigInteger('creative_id')->nullable();
                $table->unsignedBigInteger('website_id')->nullable();

                $table->unsignedBigInteger('requests')->default(0);
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('viewable_impressions')->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->unsignedBigInteger('invalid_impressions')->default(0);
                $table->unsignedBigInteger('revenue_micros')->default(0);

                $table->timestamps();

                // Full grain unique → the rollup is idempotent and re-runnable.
                $table->unique(
                    ['stat_date', 'campaign_id', 'creative_id', 'website_id'],
                    'ad_stats_daily_grain_unique'
                );
                $table->index('stat_date', 'ad_stats_date_idx');
                $table->index(['campaign_id', 'stat_date'], 'ad_stats_campaign_date_idx');
            });
        }

        if (! Schema::hasTable('ad_frequency')) {
            Schema::create('ad_frequency', function (Blueprint $table) {
                $table->id();
                $table->char('ip_hash', 64);
                $table->unsignedBigInteger('campaign_id');
                $table->date('bucket_date');
                $table->unsignedInteger('count')->default(0);
                $table->timestamps();

                $table->unique(['ip_hash', 'campaign_id', 'bucket_date'], 'ad_frequency_unique');
                $table->index('bucket_date', 'ad_frequency_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_frequency');
        Schema::dropIfExists('ad_stats_daily');
        Schema::dropIfExists('ad_events');
    }
};

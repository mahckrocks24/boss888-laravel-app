<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADS888 — session interstitial support (1:1 / 3:4, image or video).
 *
 * Plan: LVL/ADS888-INTERSTITIAL-MODAL-PLAN.md
 *
 * ADDITIVE: every statement is an ADD COLUMN on an ADS888-owned table. No
 * existing column is altered or dropped, and no table outside the ad_* family
 * is touched.
 *
 * WHY RATIO AND DIMENSIONS GO ON THE CREATIVE, NOT THE SLOT
 * The footer bar shipped with width/height on the SLOT while the CSS rendered
 * two different heights by breakpoint. The result was a 728x90 creative
 * squashed to ~220px wide on mobile and illegible (§B3b of the pre-launch
 * review). A slot declares what it ACCEPTS (`allowed_ratios`); a creative
 * declares what it IS (`aspect_ratio`, `width`, `height`). This migration fixes
 * both formats at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_slots', 'allowed_ratios')) {
                $table->json('allowed_ratios')->nullable()
                    ->comment('e.g. ["1:1","3:4"] — ratios this slot accepts; null = any');
            }
            if (! Schema::hasColumn('ad_slots', 'is_interstitial')) {
                $table->boolean('is_interstitial')->default(false)
                    ->comment('true = rendered as a blocking modal, not an in-page slot');
            }
        });

        Schema::table('ad_creatives', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_creatives', 'aspect_ratio')) {
                $table->string('aspect_ratio', 8)->nullable()
                    ->comment("the creative's OWN ratio, e.g. 1:1 or 3:4");
            }
            if (! Schema::hasColumn('ad_creatives', 'media_type')) {
                $table->string('media_type', 8)->default('image')
                    ->comment('image | video');
            }
            if (! Schema::hasColumn('ad_creatives', 'video_url')) {
                $table->string('video_url', 512)->nullable();
            }
            if (! Schema::hasColumn('ad_creatives', 'video_webm_url')) {
                $table->string('video_webm_url', 512)->nullable();
            }
            if (! Schema::hasColumn('ad_creatives', 'poster_url')) {
                $table->string('poster_url', 512)->nullable()
                    ->comment('REQUIRED when media_type=video — shown while loading and on error');
            }
            if (! Schema::hasColumn('ad_creatives', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()
                    ->comment('rejected at review above the configured hard cap');
            }
            if (! Schema::hasColumn('ad_creatives', 'width')) {
                $table->unsignedSmallInteger('width')->nullable();
            }
            if (! Schema::hasColumn('ad_creatives', 'height')) {
                $table->unsignedSmallInteger('height')->nullable();
            }
            if (! Schema::hasColumn('ad_creatives', 'file_bytes')) {
                $table->unsignedInteger('file_bytes')->nullable();
            }
        });

        Schema::table('ad_stats_daily', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_stats_daily', 'video_starts')) {
                $table->unsignedBigInteger('video_starts')->default(0);
            }
            if (! Schema::hasColumn('ad_stats_daily', 'video_completes')) {
                $table->unsignedBigInteger('video_completes')->default(0);
            }
            if (! Schema::hasColumn('ad_stats_daily', 'dismissals')) {
                $table->unsignedBigInteger('dismissals')->default(0);
            }
        });

        // `ad_events.event` is a varchar, so the new event names need no schema
        // change — only the application-side whitelist in AdEventRecorder.
    }

    public function down(): void
    {
        Schema::table('ad_stats_daily', function (Blueprint $table) {
            $table->dropColumn(['video_starts', 'video_completes', 'dismissals']);
        });

        Schema::table('ad_creatives', function (Blueprint $table) {
            $table->dropColumn([
                'aspect_ratio', 'media_type', 'video_url', 'video_webm_url',
                'poster_url', 'duration_ms', 'width', 'height', 'file_bytes',
            ]);
        });

        Schema::table('ad_slots', function (Blueprint $table) {
            $table->dropColumn(['allowed_ratios', 'is_interstitial']);
        });
    }
};

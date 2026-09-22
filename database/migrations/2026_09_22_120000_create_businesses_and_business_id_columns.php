<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0011 U1 (2026-09-22): several BUSINESSES in ONE workspace.
 *
 * Additive only. A `businesses` row is a business profile (what `workspaces.business_name/industry/services_json/
 * goal/location/brand_color/logo_url` are today, plus the memory-held facts: tone, target audience, differentiators,
 * pricing anchor). Exactly one row per workspace is the DEFAULT; the workspaces.* columns stay and mirror it, so every
 * reader that has not been rewired sees what it sees today. `business_id` is added, nullable, to the tables whose rows
 * belong to one business but carry no website; NULL means "the default business". Rows that already name a website
 * derive their business through it (websites.business_id) — no second truth.
 *
 * Nothing here changes behaviour until the resolver is consulted (config business.profiles, default off).
 */
return new class extends Migration
{
    private const BUSINESS_ID_TABLES = [
        'websites', 'social_accounts', 'calendar_events', 'tasks', 'meetings', 'workspace_goals', 'projects',
        'studio_brand_kits', 'creative_brand_identities', 'sarah_commitments',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('businesses')) {
            Schema::create('businesses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('name', 160);
                $table->string('slug', 120);
                $table->json('aliases_json')->nullable();          // what the owner calls it: ["the gym", "Boss Mac"]
                $table->string('industry', 120)->nullable();
                $table->json('services_json')->nullable();
                $table->text('goal')->nullable();
                $table->string('location', 200)->nullable();
                $table->string('brand_color', 16)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->text('tone')->nullable();
                $table->text('target_audience')->nullable();
                $table->text('differentiators')->nullable();
                $table->text('pricing_anchor')->nullable();
                $table->json('settings_json')->nullable();
                $table->boolean('is_default')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['workspace_id', 'slug']);
            });
        }

        foreach (self::BUSINESS_ID_TABLES as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'business_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('business_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::BUSINESS_ID_TABLES as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'business_id')) {
                Schema::table($t, function (Blueprint $table) { $table->dropColumn('business_id'); });
            }
        }
        Schema::dropIfExists('businesses');
    }
};

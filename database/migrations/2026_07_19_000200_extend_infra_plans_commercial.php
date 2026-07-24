<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2A-1 — plan lifecycle, term availability, allowances.
 *
 * Phase 1A already gave plans versioning (version, is_current), currency,
 * minor-unit pricing, setup/migration fees and the core numeric allowances.
 * This adds what a commercial catalog needs on top:
 *
 *   - an explicit plan lifecycle + successor (retirement without stranding)
 *   - which terms a plan may be bought on (monthly / annual / three-year)
 *   - the remaining typed allowances the Phase 2A design called for
 *   - region, for future regional pricing WITHOUT re-modelling money
 *
 * Numeric allowances stay TYPED COLUMNS deliberately: they are checked on
 * effectively every provisioning request and must be indexable. Only optional,
 * commercially-variable entitlements go to the normalized entitlement tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_plans')) {
            return;
        }

        Schema::table('infra_plans', function (Blueprint $table) {
            // ---- lifecycle -------------------------------------------------
            if (!Schema::hasColumn('infra_plans', 'lifecycle_status')) {
                // draft | current | superseded | withdrawn
                $table->string('lifecycle_status', 24)->default('current')->after('is_current');
            }
            if (!Schema::hasColumn('infra_plans', 'successor_plan_id')) {
                $table->unsignedBigInteger('successor_plan_id')->nullable()->after('lifecycle_status');
            }
            if (!Schema::hasColumn('infra_plans', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('is_public');
            }

            // ---- term availability ----------------------------------------
            // Which billing terms this plan may be PURCHASED on. A plan may be
            // annual-only (common for discounted tiers).
            if (!Schema::hasColumn('infra_plans', 'allows_monthly')) {
                $table->boolean('allows_monthly')->default(true)->after('billing_period');
            }
            if (!Schema::hasColumn('infra_plans', 'allows_annual')) {
                $table->boolean('allows_annual')->default(true)->after('allows_monthly');
            }
            if (!Schema::hasColumn('infra_plans', 'allows_triennial')) {
                $table->boolean('allows_triennial')->default(false)->after('allows_annual');
            }

            // ---- future regional pricing ----------------------------------
            // NULL = the default/global price row. A future regional price is a
            // NEW plan row with the same slug and a region set, so money never
            // needs re-modelling and existing subscribers are unaffected.
            if (!Schema::hasColumn('infra_plans', 'region')) {
                $table->string('region', 16)->nullable()->after('currency');
            }

            // ---- remaining typed allowances --------------------------------
            if (!Schema::hasColumn('infra_plans', 'included_staging_environments')) {
                $table->unsignedInteger('included_staging_environments')->nullable()->after('included_domains');
            }
            if (!Schema::hasColumn('infra_plans', 'included_restores_per_month')) {
                $table->unsignedInteger('included_restores_per_month')->nullable()->after('included_staging_environments');
            }
            if (!Schema::hasColumn('infra_plans', 'included_migrations')) {
                $table->unsignedInteger('included_migrations')->nullable()->after('included_restores_per_month');
            }
            if (!Schema::hasColumn('infra_plans', 'compute_class')) {
                // Provider-neutral abstraction: shared-small | shared-standard |
                // shared-large | isolated. An adapter maps this to whatever a
                // provider actually offers. NEVER provider specs.
                $table->string('compute_class', 32)->nullable()->after('included_migrations');
            }
            if (!Schema::hasColumn('infra_plans', 'monitoring_level')) {
                $table->string('monitoring_level', 24)->nullable()->after('compute_class');
            }
            if (!Schema::hasColumn('infra_plans', 'support_tier')) {
                // standard | priority | managed | emergency
                $table->string('support_tier', 24)->default('standard')->after('monitoring_level');
            }

            $table->index(['lifecycle_status', 'is_public'], 'infra_plans_lifecycle_public_idx');
            $table->index(['region', 'currency'], 'infra_plans_region_currency_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('infra_plans')) {
            return;
        }

        Schema::table('infra_plans', function (Blueprint $table) {
            $table->dropIndex('infra_plans_lifecycle_public_idx');
            $table->dropIndex('infra_plans_region_currency_idx');
            $table->dropColumn([
                'lifecycle_status', 'successor_plan_id', 'sort_order',
                'allows_monthly', 'allows_annual', 'allows_triennial', 'region',
                'included_staging_environments', 'included_restores_per_month',
                'included_migrations', 'compute_class', 'monitoring_level', 'support_tier',
            ]);
        });
    }
};

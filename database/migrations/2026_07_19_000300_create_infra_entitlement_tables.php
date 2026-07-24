<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2A-1 — supplemental entitlements.
 *
 * WHY TWO TABLES AND NOT EAV OR JSON
 * ----------------------------------
 * The directive requires normalized tables, and explicitly forbids EAV and
 * opaque JSON. The distinction being drawn here:
 *
 *   EAV        = one `value` column of unknown type, keys invented at write time
 *   THIS MODEL = a CONTROLLED REGISTRY of entitlements (definitions), each with a
 *                declared value_type and limit behaviour, plus TYPED value
 *                columns on the join. Every key is a row a human created on
 *                purpose; every value has a known type; everything is queryable
 *                and joinable with normal SQL.
 *
 * Numeric allowances that are enforced on nearly every request (sites, storage,
 * bandwidth, mailboxes, domains) stay as TYPED COLUMNS on infra_plans. These
 * tables carry the optional, commercially-variable extras — the things product
 * wants to add without a migration against a table with live subscribers.
 *
 * That split is deliberate: hot-path enforcement stays fast and indexable,
 * catalog flexibility stays a data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_entitlement_definitions')) {
            Schema::create('infra_entitlement_definitions', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Stable machine key, e.g. 'premium_monitoring', 'staging_addon'.
                $table->string('key', 64)->unique();
                $table->string('name', 120);
                $table->text('description')->nullable();

                // Declared type — the join table stores the value in the matching
                // typed column. Nothing is stringly-typed.
                $table->string('value_type', 16);          // bool | int | string | enum
                $table->string('unit', 24)->nullable();     // gb | count | days | null
                $table->json('allowed_values')->nullable(); // for value_type = enum

                // How a limit behaves when reached. Central to the usage model:
                // limits do NOT all behave the same way.
                // hard | soft | billable | warning | administrative | none
                $table->string('default_behavior', 24)->default('none');

                // Grouping for admin display only.
                $table->string('category', 32)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'category'], 'infra_ent_def_active_cat_idx');
            });
        }

        if (!Schema::hasTable('infra_plan_entitlements')) {
            Schema::create('infra_plan_entitlements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('plan_id');
                $table->unsignedBigInteger('entitlement_definition_id');

                // Typed value columns. Exactly one is populated, per the
                // definition's value_type. This is what keeps it out of EAV.
                $table->boolean('bool_value')->nullable();
                $table->bigInteger('int_value')->nullable();
                $table->string('string_value', 191)->nullable();

                // Per-plan override of the definition's default behaviour, e.g.
                // storage is 'billable' on paid tiers but 'hard' on a free tier.
                $table->string('behavior', 24)->nullable();

                // Optional price for an entitlement sold as an add-on. Minor
                // units + currency, consistent with the rest of the model.
                $table->unsignedBigInteger('addon_amount_minor')->nullable();
                $table->string('addon_currency', 3)->nullable();

                $table->timestamps();

                $table->unique(['plan_id', 'entitlement_definition_id'], 'infra_plan_ent_uq');
                $table->index('entitlement_definition_id', 'infra_plan_ent_def_idx');

                $table->foreign('plan_id', 'infra_plan_ent_plan_fk')
                      ->references('id')->on('infra_plans')->cascadeOnDelete();
                $table->foreign('entitlement_definition_id', 'infra_plan_ent_def_fk')
                      ->references('id')->on('infra_entitlement_definitions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_plan_entitlements');
        Schema::dropIfExists('infra_entitlement_definitions');
    }
};

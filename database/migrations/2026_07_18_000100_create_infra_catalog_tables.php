<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1A — commercial catalog.
 *
 * infra_products  = a sellable infrastructure category (managed hosting, domain
 *                   registration, business email). Category-neutral by design:
 *                   hosting/domain/email are rows, not tables.
 * infra_plans     = a versioned, priced package of a product.
 *
 * Plans are VERSIONED, never edited in place. Directive §13: "Existing subscribers
 * must retain the plan terms they purchased even after future plan changes."
 * A price change creates a new row with version+1; the old row is marked
 * is_current=false and retained forever so historical subscriptions still resolve.
 *
 * Money is stored in MINOR UNITS (integer cents/fils) with an explicit currency.
 * The platform `plans` table stores decimal(10,2) with NO currency column at all
 * (Phase 0 audit §6) — that defect is not inherited here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_products')) {
            Schema::create('infra_products', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('slug', 64)->unique();
                $table->string('name', 120);
                $table->string('category', 32);           // hosting | domain | email
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['category', 'is_active'], 'infra_products_cat_active_idx');
            });
        }

        if (!Schema::hasTable('infra_plans')) {
            Schema::create('infra_plans', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->string('slug', 64);
                $table->string('name', 120);
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_current')->default(true);
                $table->boolean('is_public')->default(true);

                // Money — minor units + explicit currency. Never float.
                $table->string('currency', 3)->default('USD');
                $table->unsignedBigInteger('recurring_amount_minor')->default(0);
                $table->string('billing_period', 16)->default('monthly'); // monthly|annual|triennial
                $table->unsignedBigInteger('setup_fee_minor')->default(0);
                $table->unsignedBigInteger('migration_fee_minor')->default(0);
                $table->unsignedBigInteger('renewal_amount_minor')->nullable(); // null = same as recurring

                // Entitlements — typed, queryable. NOT a JSON blob (directive §5).
                $table->unsignedInteger('included_sites')->nullable();
                $table->unsignedBigInteger('included_storage_mb')->nullable();
                $table->unsignedBigInteger('included_bandwidth_mb')->nullable();
                $table->unsignedInteger('included_mailboxes')->nullable();
                $table->unsignedInteger('included_domains')->nullable();
                $table->unsignedInteger('backup_retention_days')->nullable();

                // Overage pricing — null means "not sold as overage, hard cap instead".
                $table->unsignedBigInteger('overage_storage_minor_per_gb')->nullable();
                $table->unsignedBigInteger('overage_bandwidth_minor_per_gb')->nullable();

                $table->json('features_json')->nullable();   // presentational extras only
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['slug', 'version'], 'infra_plans_slug_version_uq');
                $table->index(['product_id', 'is_current'], 'infra_plans_product_current_idx');
                $table->index(['is_public', 'is_current'], 'infra_plans_public_current_idx');

                $table->foreign('product_id', 'infra_plans_product_fk')
                      ->references('id')->on('infra_products')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_plans');
        Schema::dropIfExists('infra_products');
    }
};

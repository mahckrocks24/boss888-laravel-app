<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2A-1 — product catalog lifecycle.
 *
 * Phase 1A created `infra_products` as a category list (slug, name, category,
 * is_active). That cannot express a commercial catalog: there is no way to
 * version a product, retire it without stranding subscribers, or expose it to
 * partners but not the public.
 *
 * Additive only. Existing rows default to version 1 / active / public, which is
 * exactly what they are today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_products')) {
            return;
        }

        Schema::table('infra_products', function (Blueprint $table) {
            if (!Schema::hasColumn('infra_products', 'version')) {
                // Product-level version, independent of plan versions. A product
                // may be re-shaped (v2) while v1 subscribers continue untouched.
                $table->unsignedInteger('version')->default(1)->after('slug');
            }

            if (!Schema::hasColumn('infra_products', 'lifecycle_status')) {
                // draft | active | deprecated | retired
                // deprecated = not sellable, still serviced.
                // retired    = not sellable, no active subscribers permitted.
                $table->string('lifecycle_status', 24)->default('active')->after('version');
            }

            if (!Schema::hasColumn('infra_products', 'sku')) {
                // Stable internal identifier that survives renames. Nullable
                // because existing rows predate it; unique where present.
                $table->string('sku', 64)->nullable()->after('lifecycle_status');
            }

            if (!Schema::hasColumn('infra_products', 'successor_product_id')) {
                // Migration path. Retiring a product with active subscribers
                // requires a successor (enforced in the service layer, not by a
                // DB constraint, so historical data cannot become unwritable).
                $table->unsignedBigInteger('successor_product_id')->nullable()->after('sku');
            }

            if (!Schema::hasColumn('infra_products', 'visibility')) {
                // public | private | partner | internal
                // `is_active` is retained for backward compatibility but
                // visibility is the richer control going forward.
                $table->string('visibility', 16)->default('public')->after('is_active');
            }

            if (!Schema::hasColumn('infra_products', 'available_from')) {
                $table->timestamp('available_from')->nullable()->after('visibility');
            }

            if (!Schema::hasColumn('infra_products', 'available_until')) {
                $table->timestamp('available_until')->nullable()->after('available_from');
            }

            $table->index(['lifecycle_status', 'visibility'], 'infra_products_lifecycle_vis_idx');
        });

        // Unique only where a SKU is set. Added separately because MySQL treats
        // multiple NULLs as distinct, which is the behaviour we want.
        Schema::table('infra_products', function (Blueprint $table) {
            $table->unique('sku', 'infra_products_sku_uq');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('infra_products')) {
            return;
        }

        Schema::table('infra_products', function (Blueprint $table) {
            $table->dropUnique('infra_products_sku_uq');
            $table->dropIndex('infra_products_lifecycle_vis_idx');
            $table->dropColumn([
                'version', 'lifecycle_status', 'sku', 'successor_product_id',
                'visibility', 'available_from', 'available_until',
            ]);
        });
    }
};

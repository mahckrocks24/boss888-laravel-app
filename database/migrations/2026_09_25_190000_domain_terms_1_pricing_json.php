<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DOMAIN-TERMS-1 (2026-09-25): the per-year breakdown a customer was shown, frozen on the order line. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domain_order_items') && ! Schema::hasColumn('domain_order_items', 'pricing_json')) {
            Schema::table('domain_order_items', fn (Blueprint $t) => $t->json('pricing_json')->nullable()->after('retail_minor'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('domain_order_items') && Schema::hasColumn('domain_order_items', 'pricing_json')) {
            Schema::table('domain_order_items', fn (Blueprint $t) => $t->dropColumn('pricing_json'));
        }
    }
};

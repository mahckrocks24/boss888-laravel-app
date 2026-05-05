<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'billing_period')) {
                $table->string('billing_period', 20)->default('monthly')->after('price');
            }
            if (! Schema::hasColumn('plans', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('billing_period');
            }
            if (! Schema::hasColumn('plans', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('stripe_price_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $cols = array_filter(['billing_period', 'stripe_price_id', 'is_public'], fn($c) => Schema::hasColumn('plans', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
    }
};

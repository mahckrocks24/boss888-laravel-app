<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->after('status');
            }
            if (! Schema::hasColumn('subscriptions', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->after('stripe_subscription_id');
            }
            if (! Schema::hasColumn('subscriptions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('stripe_customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $cols = array_filter(
                ['stripe_subscription_id', 'stripe_customer_id', 'cancelled_at'],
                fn($c) => Schema::hasColumn('subscriptions', $c)
            );
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};

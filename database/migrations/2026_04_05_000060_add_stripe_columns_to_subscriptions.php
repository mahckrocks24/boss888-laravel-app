<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH v1.0.1 — Extends subscriptions with 'superseded' status enum.
 *
 * stripe_subscription_id, stripe_customer_id, and cancelled_at were already
 * added by 2026_04_04_500001_add_stripe_to_subscriptions.php.
 * This migration ONLY adds the 'superseded' status value via raw SQL,
 * using Schema::hasColumn() guards to prevent duplicate-column crashes
 * on fresh deploys that run all migrations in sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: only add columns that are not already present.
        // On a fresh deploy, 500001 runs first and adds all three.
        // On an existing DB that was partially migrated, this is a safety net.
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->after('provider_subscription_id')->index();
            }
            if (! Schema::hasColumn('subscriptions', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->after('stripe_subscription_id')->index();
            }
            if (! Schema::hasColumn('subscriptions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('ends_at');
            }
        });

        // Extend status enum to include 'superseded' for plan-change lifecycle.
        // Uses raw SQL to avoid doctrine/dbal dependency.
        // Wrapped in try/catch: SQLite (used in tests) ignores MODIFY COLUMN.
        try {
            \DB::statement(
                "ALTER TABLE subscriptions MODIFY COLUMN status " .
                "ENUM('active','cancelled','past_due','trialing','expired','superseded') " .
                "DEFAULT 'active'"
            );
        } catch (\Throwable) {
            // Non-MySQL driver — skip enum extension, value stored as string.
        }
    }

    public function down(): void
    {
        // Revert enum extension only — columns were added by 500001 and
        // should be dropped by its down() method, not here.
        try {
            \DB::statement(
                "ALTER TABLE subscriptions MODIFY COLUMN status " .
                "ENUM('active','cancelled','past_due','trialing','expired') " .
                "DEFAULT 'active'"
            );
        } catch (\Throwable) {}
    }
};

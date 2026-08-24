<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fan-out state on the event row — Phase 1B.
 *
 * ADDITIVE ONLY. `platform_events.status` is NOT redefined and NOT migrated:
 * the Phase 1A relay wrote to it, Phase 1A tests assert on it, and destructively
 * changing a column's meaning — even with zero live rows — breaks a passing suite
 * for no benefit.
 *
 * Instead, fan-out records its own state:
 *
 *   fanned_out_at   when eligible subscribers were resolved into delivery rows
 *   delivery_count  how many delivery rows were created (0 = no eligible subscriber)
 *
 * The distinction that matters:
 *   - the EVENT row says whether fan-out has happened
 *   - the DELIVERY rows say what each subscriber did about it
 *
 * The event row never carries a delivery outcome, because with N subscribers
 * there is no single outcome to carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_events', function (Blueprint $table) {
            $table->timestamp('fanned_out_at')->nullable()->after('dispatched_at');
            $table->unsignedInteger('delivery_count')->default(0)->after('fanned_out_at');

            // Fan-out worker claim path: events not yet fanned out, oldest first.
            $table->index(['fanned_out_at', 'id'], 'pe_fanout_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_events', function (Blueprint $table) {
            $table->dropIndex('pe_fanout_idx');
            $table->dropColumn(['fanned_out_at', 'delivery_count']);
        });
    }
};

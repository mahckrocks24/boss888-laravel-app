<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2A-1 — subscription commercial fields.
 *
 * Phase 1A already pinned currency, unit amount, billing period and term dates,
 * and gave subscriptions a state machine with grace/suspension/cancellation.
 * This adds what grandfathering and plan migration require:
 *
 *   trial_ends_at              the machine had no trial state
 *   plan_version_at_purchase   grandfathering must survive plan edits
 *   price_locked_until         contractual price protection on multi-year terms
 *   renewal_amount_minor       renewal price may differ from acquisition price
 *   superseded_by_subscription_id  upgrade/downgrade lineage
 *   archived_at                data-retention state, distinct from cancelled
 *
 * `cancelled` and `archived` are deliberately different: cancelled means no
 * longer served; archived means data removed per retention policy. Conflating
 * them is how hosting companies either delete a customer's data early or keep it
 * for years and inherit a compliance problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_subscriptions')) {
            return;
        }

        Schema::table('infra_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('infra_subscriptions', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('term_end_at');
            }
            if (!Schema::hasColumn('infra_subscriptions', 'plan_version_at_purchase')) {
                // Snapshot of infra_plans.version at purchase. The plan row is
                // immutable-by-convention, but this makes grandfathering explicit
                // and auditable without a join through superseded versions.
                $table->unsignedInteger('plan_version_at_purchase')->nullable()->after('plan_id');
            }
            if (!Schema::hasColumn('infra_subscriptions', 'price_locked_until')) {
                $table->timestamp('price_locked_until')->nullable()->after('unit_amount_minor');
            }
            if (!Schema::hasColumn('infra_subscriptions', 'renewal_amount_minor')) {
                // NULL means "renew at the same unit amount".
                $table->unsignedBigInteger('renewal_amount_minor')->nullable()->after('price_locked_until');
            }
            if (!Schema::hasColumn('infra_subscriptions', 'superseded_by_subscription_id')) {
                $table->unsignedBigInteger('superseded_by_subscription_id')->nullable()->after('cancellation_reason');
            }
            if (!Schema::hasColumn('infra_subscriptions', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('infra_subscriptions', 'support_tier')) {
                // Pinned from the plan at purchase. Support level drives approval
                // routing and response targets, so it must not drift when the
                // catalog changes.
                $table->string('support_tier', 24)->nullable()->after('billing_mode');
            }

            $table->index('trial_ends_at', 'infra_subs_trial_idx');
            $table->index('superseded_by_subscription_id', 'infra_subs_superseded_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('infra_subscriptions')) {
            return;
        }

        Schema::table('infra_subscriptions', function (Blueprint $table) {
            $table->dropIndex('infra_subs_trial_idx');
            $table->dropIndex('infra_subs_superseded_idx');
            $table->dropColumn([
                'trial_ends_at', 'plan_version_at_purchase', 'price_locked_until',
                'renewal_amount_minor', 'superseded_by_subscription_id',
                'archived_at', 'support_tier',
            ]);
        });
    }
};

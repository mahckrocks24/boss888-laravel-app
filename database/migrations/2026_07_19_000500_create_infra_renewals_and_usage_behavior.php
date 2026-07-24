<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2A-1 — renewal records + usage limit behaviour.
 *
 * RENEWALS
 * A renewal is an EVENT with its own state and retry history, not a timestamp.
 * `infra_subscriptions.next_renewal_at` can say when the next one is due; it
 * cannot express "attempted twice, failed, now in dunning, reminder sent on the
 * 3rd". No payment integration here — only the lifecycle.
 *
 * USAGE BEHAVIOUR
 * The central insight of the Phase 2A usage model: limits do NOT all behave the
 * same way. Websites are a hard limit (provisioning is discrete). Storage is a
 * soft limit with billable overage, because refusing writes at 100.1% of quota
 * breaks live sites and generates churn. Recording WHICH behaviour applied at
 * metering time means a later policy change cannot silently reinterpret
 * historical usage.
 *
 * No enforcement is implemented in this phase — architecture only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_renewals')) {
            Schema::create('infra_renewals', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('subscription_id');

                // scheduled | due | attempting | renewed | failed | dunning |
                // abandoned | cancelled
                $table->string('state', 24)->default('scheduled');
                $table->string('previous_state', 24)->nullable();

                // manual = Mode A (staff-issued). automated = Mode B (future).
                $table->string('renewal_mode', 16)->default('manual');

                $table->timestamp('scheduled_for');
                $table->timestamp('reminder_sent_at')->nullable();
                $table->timestamp('attempted_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedTinyInteger('attempt_count')->default(0);

                // The term this renewal would establish.
                $table->string('billing_period', 16)->nullable();
                $table->string('currency', 3)->nullable();
                $table->unsignedBigInteger('amount_minor')->nullable();

                // New term boundaries once renewed.
                $table->timestamp('new_period_start')->nullable();
                $table->timestamp('new_period_end')->nullable();

                $table->string('failure_code', 64)->nullable();
                $table->text('failure_summary')->nullable();   // customer-safe text
                $table->unsignedBigInteger('actor_user_id')->nullable();

                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'state'], 'infra_renewals_ws_state_idx');
                $table->index(['state', 'scheduled_for'], 'infra_renewals_due_idx');
                $table->index('subscription_id', 'infra_renewals_sub_idx');

                $table->foreign('subscription_id', 'infra_renewals_sub_fk')
                      ->references('id')->on('infra_subscriptions')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('infra_usage_records') && !Schema::hasColumn('infra_usage_records', 'behavior')) {
            Schema::table('infra_usage_records', function (Blueprint $table) {
                // hard | soft | billable | warning | administrative | none
                // Snapshotted at metering time so historical records remain
                // interpretable after a policy change.
                $table->string('behavior', 24)->nullable()->after('unit');

                // Threshold crossings, for warn-before-bill behaviour.
                $table->unsignedTinyInteger('warning_threshold_pct')->nullable()->after('behavior');
                $table->boolean('warning_sent')->default(false)->after('warning_threshold_pct');

                $table->index(['metric', 'behavior'], 'infra_usage_metric_behavior_idx');
            });
        }

        if (Schema::hasTable('infra_cost_entries')) {
            Schema::table('infra_cost_entries', function (Blueprint $table) {
                if (!Schema::hasColumn('infra_cost_entries', 'period_start')) {
                    // Costs are usually billed per period, not per instant.
                    $table->date('period_start')->nullable()->after('incurred_on');
                    $table->date('period_end')->nullable()->after('period_start');
                }
                if (!Schema::hasColumn('infra_cost_entries', 'reconciled_at')) {
                    // An 'estimated' entry is later reconciled against a real
                    // provider invoice. Both states are retained.
                    $table->timestamp('reconciled_at')->nullable()->after('source');
                    $table->unsignedBigInteger('reconciled_amount_minor')->nullable()->after('reconciled_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_renewals');

        if (Schema::hasTable('infra_usage_records') && Schema::hasColumn('infra_usage_records', 'behavior')) {
            Schema::table('infra_usage_records', function (Blueprint $table) {
                $table->dropIndex('infra_usage_metric_behavior_idx');
                $table->dropColumn(['behavior', 'warning_threshold_pct', 'warning_sent']);
            });
        }

        if (Schema::hasTable('infra_cost_entries')) {
            Schema::table('infra_cost_entries', function (Blueprint $table) {
                foreach (['period_start', 'period_end', 'reconciled_at', 'reconciled_amount_minor'] as $c) {
                    if (Schema::hasColumn('infra_cost_entries', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};

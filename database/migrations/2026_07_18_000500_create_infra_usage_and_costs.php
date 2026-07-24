<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1A — usage metering + provider cost attribution.
 *
 * infra_usage_records = measured consumption per subscription per period.
 * infra_cost_entries  = what a provider CHARGED US for a resource.
 *
 * Directive §8 (previous phase) and §13: infrastructure actions carry zero AI
 * credits but MUST still produce cost events. These two tables are what make
 * gross-margin reporting possible: revenue lives on infra_subscriptions, cost
 * lives here, and margin is the difference over a period.
 *
 * The platform has no equivalent. Existing usage tables (api_usage_logs,
 * chatbot_usage_logs) drive hard caps and never charge — CreditService.php:79
 * aborts with 402 rather than billing (Phase 0 audit §6).
 *
 * Quantities are stored as unsigned integers in explicit units (MB, count) rather
 * than floats, so metering arithmetic is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_usage_records')) {
            Schema::create('infra_usage_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('subscription_id')->nullable();

                $table->string('owner_type', 64)->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();

                $table->string('metric', 48);      // storage_mb | bandwidth_mb | mailbox_count | site_count
                $table->unsignedBigInteger('quantity')->default(0);
                $table->string('unit', 16);        // mb | count

                $table->date('period_start');
                $table->date('period_end');
                $table->timestamp('measured_at')->useCurrent();
                $table->string('source', 32)->default('provider'); // provider|internal|estimated

                // Overage evaluation, resolved at metering time against the pinned plan.
                $table->unsignedBigInteger('included_quantity')->nullable();
                $table->unsignedBigInteger('overage_quantity')->default(0);
                $table->unsignedBigInteger('overage_amount_minor')->default(0);
                $table->string('currency', 3)->nullable();
                $table->boolean('is_billable')->default(false);
                $table->boolean('is_invoiced')->default(false);

                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'subscription_id', 'metric', 'period_start'],
                    'infra_usage_period_uq'
                );
                $table->index(['workspace_id', 'metric'], 'infra_usage_ws_metric_idx');
                $table->index(['is_billable', 'is_invoiced'], 'infra_usage_billable_idx');
            });
        }

        if (!Schema::hasTable('infra_cost_entries')) {
            Schema::create('infra_cost_entries', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Nullable: platform-level costs (e.g. the flat edge fee) are not
                // attributable to a single workspace.
                $table->unsignedBigInteger('workspace_id')->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->unsignedBigInteger('provider_resource_id')->nullable();

                $table->string('provider', 64);
                $table->string('cost_type', 48);   // hostname_fee|registration|renewal|mailbox_seat|storage
                $table->string('currency', 3);
                $table->bigInteger('amount_minor');       // signed: credits/refunds are negative
                $table->date('incurred_on');
                $table->string('provider_reference', 191)->nullable();
                $table->string('source', 32)->default('estimated'); // estimated|invoice|api

                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'incurred_on'], 'infra_costs_ws_date_idx');
                $table->index(['provider', 'cost_type'], 'infra_costs_provider_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_cost_entries');
        Schema::dropIfExists('infra_usage_records');
    }
};

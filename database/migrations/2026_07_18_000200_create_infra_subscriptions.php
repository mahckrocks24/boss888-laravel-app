<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1A — customer infrastructure subscriptions.
 *
 * A subscription is the CUSTOMER'S ENTITLEMENT, not a server object (directive §16).
 * It is the commercial anchor every provisioned resource hangs off.
 *
 * Deliberate divergences from the platform `subscriptions` table (Phase 0 audit §6):
 *   - `quantity` is a real column. The platform hardcodes quantity=1 at
 *     StripeService.php:83 and :415, which makes N mailboxes unrepresentable.
 *   - `current_period_end` / `term_end_at` are OUR source of truth. The platform
 *     never writes `ends_at` and must live-fetch Stripe to know when anything
 *     expires (StripeService.php:256-267).
 *   - Plan terms are pinned by `plan_id` to a VERSIONED plan row, so a future
 *     price change cannot retroactively alter what a customer bought.
 *   - `state` is a lifecycle string validated by SubscriptionState, not booleans.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('infra_subscriptions')) {
            return;
        }

        Schema::create('infra_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('product_id');

            $table->string('state', 32)->default('draft');
            $table->string('previous_state', 32)->nullable();

            $table->unsignedInteger('quantity')->default(1);

            // Pinned commercial terms — copied from the plan at purchase time so the
            // subscription remains self-describing even if the plan row changes.
            $table->string('currency', 3);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->string('billing_period', 16);
            $table->unsignedBigInteger('setup_fee_minor')->default(0);

            // Term + period. Ours, not the payment provider's.
            $table->timestamp('term_start_at')->nullable();
            $table->timestamp('term_end_at')->nullable();          // multi-year contracts
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_renewal_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            // Link to platform billing WITHOUT coupling to it. Nullable because a
            // Mode A (manually contracted / manually invoiced) subscription has no
            // payment-provider object at all.
            $table->string('billing_reference', 191)->nullable();
            $table->string('billing_mode', 16)->default('manual'); // manual | automated

            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'state'], 'infra_subs_ws_state_idx');
            $table->index('next_renewal_at', 'infra_subs_renewal_idx');
            $table->index('term_end_at', 'infra_subs_term_end_idx');
            $table->index(['workspace_id', 'product_id'], 'infra_subs_ws_product_idx');
            $table->unique(['billing_reference'], 'infra_subs_billing_ref_uq');

            $table->foreign('plan_id', 'infra_subs_plan_fk')
                  ->references('id')->on('infra_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_subscriptions');
    }
};

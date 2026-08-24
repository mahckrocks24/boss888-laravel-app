<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2 -- Customer Domain Commerce.
 *
 * Three tables, one per stage of the lifecycle:
 *
 *   domain_orders       the commercial transaction (cart -> payment)
 *   domain_order_items  what was bought, with cost/markup/retail preserved
 *   customer_domains    what the customer now OWNS
 *
 * The split matters. An order is immutable commercial history: once paid, its
 * prices must never move, even if markup or registrar cost changes tomorrow.
 * A customer_domain is living state that syncs with the registrar forever.
 * Collapsing them into one table would make either history mutable or state
 * duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_orders', function (Blueprint $table) {
            $table->id();

            // Tenancy. Every query in this module filters on workspace_id.
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // pending -> awaiting_payment -> paid -> provisioning -> completed
            //                             \-> cancelled  \-> failed / partially_completed
            $table->string('status', 32)->default('pending')->index();

            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            // Wholesale total, retained so margin is auditable per order without
            // recomputing from a markup that may since have changed.
            $table->unsignedBigInteger('cost_total_minor')->default(0);

            $table->string('stripe_session_id', 191)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 191)->nullable()->index();
            $table->timestamp('paid_at')->nullable();

            // Guards against a double-submitted cart creating two orders.
            $table->string('idempotency_key', 191)->nullable()->unique();

            $table->text('last_error')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('domain_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('domain_order_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();

            $table->string('domain', 253);
            $table->string('tld', 63);
            $table->unsignedTinyInteger('years')->default(1);
            $table->string('action', 16)->default('register');   // register | renew | transfer

            $table->string('provider', 64)->default('namecheap');

            // The three figures that make the commercial record complete.
            // Frozen at purchase time and never recalculated.
            $table->unsignedBigInteger('registrar_cost_minor')->default(0);
            $table->unsignedBigInteger('markup_minor')->default(0);
            $table->unsignedBigInteger('retail_minor')->default(0);
            $table->string('currency', 3)->default('USD');

            $table->boolean('is_premium')->default(false);

            // pending -> registering -> registered | failed | refund_due
            $table->string('status', 32)->default('pending')->index();

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('registered_at')->nullable();
            $table->string('provider_order_id', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->text('last_error')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->json('provider_metadata_json')->nullable();
            $table->timestamps();

            // One domain can only be bought once per order.
            $table->unique(['domain_order_id', 'domain']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('customer_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('domain_order_item_id')->nullable()->index();

            // A registered domain is globally unique. This constraint is the
            // last line of defence against a double registration surviving
            // every other idempotency guard.
            $table->string('domain', 253)->unique();

            $table->string('provider', 64)->default('namecheap');
            $table->string('provider_domain_id', 191)->nullable();
            $table->string('provider_order_id', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();

            // active | expired | pending_transfer | suspended | released
            $table->string('status', 32)->default('active')->index();

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('auto_renew')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('whois_privacy')->default(false);

            $table->json('nameservers_json')->nullable();
            $table->json('provider_metadata_json')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_domains');
        Schema::dropIfExists('domain_order_items');
        Schema::dropIfExists('domain_orders');
    }
};

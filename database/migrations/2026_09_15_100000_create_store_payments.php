<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STORE PAYMENTS (DEC-0051 gap 2, 2026-09-15): a workspace connects its OWN Stripe account (a restricted or secret
 * key, stored encrypted); catalogue items with a price get a "Buy now / Book & pay" button that opens Stripe
 * Checkout on that account; paid sessions become orders and CRM leads. No platform money ever passes through.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_payment_accounts')) {
            Schema::create('workspace_payment_accounts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id')->unique();
                $table->string('provider', 20)->default('stripe');
                $table->text('secret_key');                       // encrypted
                $table->string('publishable_key', 120)->nullable();
                $table->string('key_hint', 12)->nullable();        // "…1234" for the panel
                $table->string('mode', 8)->default('test');        // test | live
                $table->string('webhook_id', 80)->nullable();
                $table->text('webhook_secret')->nullable();        // encrypted
                $table->char('currency', 3)->default('USD');
                $table->string('status', 24)->default('active');   // active | no_webhook | disabled
                $table->string('account_label', 120)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('catalogue_orders')) {
            Schema::create('catalogue_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('website_id')->index();
                $table->unsignedBigInteger('item_id')->nullable()->index();
                $table->string('kind', 24)->nullable();
                $table->string('item_title', 190)->nullable();
                $table->string('session_id', 120)->unique();
                $table->decimal('amount', 14, 2)->nullable();
                $table->char('currency', 3)->default('USD');
                $table->string('customer_email', 190)->nullable();
                $table->string('customer_name', 190)->nullable();
                $table->string('status', 20)->default('pending');  // pending | paid | expired
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->json('raw_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_orders');
        Schema::dropIfExists('workspace_payment_accounts');
    }
};

<?php
// RFC-0026 P1 (Owner 2026-10-05): the partner program - affiliates, links, clicks, vouchers, referrals, commissions, payouts.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->string('handle', 32)->unique();
            $t->string('display_name', 120);
            $t->string('status', 16)->default('pending');          // pending, approved, rejected, suspended, closed
            $t->string('channel_url', 255)->nullable();
            $t->string('audience', 500)->nullable();
            $t->unsignedSmallInteger('budget_monthly_bps')->default(2000);   // D9: admin may raise
            $t->unsignedSmallInteger('budget_yearly_bps')->default(1500);
            $t->unsignedSmallInteger('budget_domain_bps')->default(1000);
            $t->string('payout_method', 16)->nullable();            // stripe, paypal, wise
            $t->string('payout_account_id', 64)->nullable();        // Stripe Connect Express account
            $t->boolean('payouts_enabled')->default(false);
            $t->string('payout_email', 190)->nullable();            // PayPal / Wise
            $t->string('terms_version', 16)->nullable();
            $t->timestamp('terms_accepted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->unsignedBigInteger('demo_workspace_id')->nullable(); // D7
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('affiliate_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->string('sub_id', 40);
            $t->string('label', 120)->nullable();
            $t->timestamps();
            $t->unique(['affiliate_id', 'sub_id']);
        });

        Schema::create('affiliate_clicks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->unsignedBigInteger('link_id')->nullable();
            $t->string('sub_id', 40)->nullable();
            $t->char('ip_hash', 64);
            $t->string('device', 12)->default('desktop');
            $t->string('landing', 255)->nullable();
            $t->string('referer_host', 120)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['affiliate_id', 'created_at']);
            $t->index(['ip_hash', 'created_at']);
        });

        Schema::create('vouchers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 24)->unique();
            $t->unsignedBigInteger('affiliate_id')->nullable();     // null = a house promotion
            $t->string('label', 120)->nullable();
            $t->unsignedSmallInteger('discount_monthly_bps')->default(0);
            $t->unsignedSmallInteger('discount_yearly_bps')->default(0);
            $t->unsignedSmallInteger('discount_domain_bps')->default(0);
            $t->boolean('applies_plans')->default(true);
            $t->boolean('applies_domains')->default(true);
            $t->unsignedSmallInteger('months')->default(6);          // monthly plans: how many payments are discounted
            $t->unsignedInteger('max_redemptions')->nullable();
            $t->unsignedInteger('redemptions')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('new_customers_only')->default(true);
            $t->string('status', 12)->default('active');             // active, paused, archived
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index('affiliate_id');
        });

        Schema::create('referrals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->unique();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('affiliate_id')->nullable();
            $t->unsignedBigInteger('voucher_id')->nullable();
            $t->string('source', 12);                                 // link, voucher
            $t->string('sub_id', 40)->nullable();
            $t->unsignedBigInteger('click_id')->nullable();
            // the terms the customer joined on - later edits to a code never change them
            $t->unsignedSmallInteger('discount_monthly_bps')->default(0);
            $t->unsignedSmallInteger('commission_monthly_bps')->default(0);
            $t->unsignedSmallInteger('discount_yearly_bps')->default(0);
            $t->unsignedSmallInteger('commission_yearly_bps')->default(0);
            $t->unsignedSmallInteger('discount_domain_bps')->default(0);
            $t->unsignedSmallInteger('commission_domain_bps')->default(0);
            $t->unsignedSmallInteger('months')->default(6);
            $t->unsignedSmallInteger('cycles_paid')->default(0);
            $t->string('status', 12)->default('signed_up');           // signed_up, paying, ended, void
            $t->string('void_reason', 120)->nullable();
            $t->timestamp('signed_up_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'status']);
        });

        Schema::create('voucher_redemptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('voucher_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('kind', 12);                                   // plan, domain
            $t->string('ref', 80);
            $t->unsignedBigInteger('amount_saved_minor')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->unique(['voucher_id', 'workspace_id', 'kind', 'ref'], 'vr_once');
        });

        Schema::create('commissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->unsignedBigInteger('referral_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('source_type', 16);                            // invoice, domain_order, adjustment
            $t->string('source_id', 80);
            $t->string('stripe_event_id', 80)->unique();              // a replayed webhook never pays twice
            $t->string('payment_intent_id', 80)->nullable();
            $t->string('kind', 10);                                   // monthly, yearly, domain
            $t->bigInteger('base_minor');
            $t->unsignedSmallInteger('rate_bps');
            $t->bigInteger('amount_minor');                           // negative = a reversal
            $t->char('currency', 3)->default('USD');
            $t->string('status', 10)->default('pending');             // pending, payable, paid, void
            $t->timestamp('payable_at')->nullable();
            $t->unsignedBigInteger('payout_id')->nullable();
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->string('note', 255)->nullable();
            $t->unsignedSmallInteger('cycle_no')->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'status']);
            $t->index('payment_intent_id');
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->string('period', 7);
            $t->string('method', 16);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3)->default('USD');
            $t->string('status', 10)->default('draft');               // draft, sent, paid, failed
            $t->string('stripe_transfer_id', 80)->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('idempotency_key', 80)->unique();
            $t->json('commission_ids')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'period']);
        });

        Schema::create('affiliate_flags', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('kind', 32);
            $t->json('evidence_json')->nullable();
            $t->string('status', 12)->default('open');
            $t->string('resolution', 255)->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });

        Schema::create('partner_stripe_coupons', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('percent_bps');
            $t->string('duration', 12);                               // repeating6, once
            $t->string('mode', 4);                                    // test, live
            $t->string('coupon_id', 80);
            $t->timestamps();
            $t->unique(['percent_bps', 'duration', 'mode']);
        });
    }

    public function down(): void
    {
        foreach (['partner_stripe_coupons', 'affiliate_flags', 'payouts', 'commissions', 'voucher_redemptions', 'referrals', 'vouchers', 'affiliate_clicks', 'affiliate_links', 'affiliates'] as $t) Schema::dropIfExists($t);
    }
};

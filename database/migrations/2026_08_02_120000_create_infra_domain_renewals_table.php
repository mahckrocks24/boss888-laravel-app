<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · S3 — DOMAIN RENEWAL ORCHESTRATION
 *
 * `infra_renewals` already exists, but it is keyed to `subscription_id` ->
 * `infra_subscriptions`, which is empty. It models the renewal of a hosting
 * SUBSCRIPTION. Real registered domains live in `customer_domains` and have no
 * subscription row, so that table cannot express a domain renewal at all.
 *
 * Rather than overload a table another concern owns — and risk changing
 * subscription behaviour owned elsewhere — domain renewals get their own ledger
 * keyed to the thing actually being renewed.
 *
 * A renewal is an EVENT WITH HISTORY, not a date field. The row survives its
 * outcome so that "did we already renew this?" is answerable after a crash, a
 * timeout, or an ambiguous provider reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_domain_renewals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('customer_domain_id');

            // Denormalised deliberately: the audit trail must stay readable even
            // if the domain row is later released or deleted.
            $table->string('domain', 253);

            $table->string('state', 24)->default('scheduled');
            $table->string('previous_state', 24)->nullable();
            $table->string('renewal_mode', 16)->default('manual');
            $table->unsignedTinyInteger('years')->default(1);

            // ── schedule ────────────────────────────────────────────────────
            $table->timestamp('scheduled_for');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('window_opens_at')->nullable();

            // ── execution ───────────────────────────────────────────────────
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();

            // ── verification (read-back, never provider-OK alone) ────────────
            $table->timestamp('expiry_before')->nullable();
            $table->timestamp('expiry_observed')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('verify_attempts')->default(0);

            // ── provider ────────────────────────────────────────────────────
            $table->string('provider', 64)->default('namecheap');
            $table->string('provider_order_id', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();

            // The single strongest duplicate-renewal guard we have: one key per
            // renewal attempt-set, unique at the database level.
            $table->string('idempotency_key', 128)->unique();

            // ── failure ─────────────────────────────────────────────────────
            $table->string('failure_code', 64)->nullable();
            // transient | permanent | ambiguous — ambiguous is NOT failure.
            $table->string('failure_class', 16)->nullable();
            $table->text('failure_summary')->nullable();

            // ── commercial ──────────────────────────────────────────────────
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('customer_domain_id');
            $table->index('state');
            $table->index('scheduled_for');
            $table->index('next_attempt_at');
            $table->index(['state', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_domain_renewals');
    }
};

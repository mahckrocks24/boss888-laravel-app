<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-B — charge lifecycle record for a chat message.
 *
 * THIS IS NOT A SECOND BALANCE SYSTEM.
 *
 * `credits` and `credit_transactions` remain the ONLY authority on what a
 * workspace owns and owes. This table records the LIFECYCLE of one logical
 * request's charge and the LINKAGE that does not exist today — which message
 * caused which reservation, and which ledger row settled it. It is deliberately
 * droppable: remove it and billing is exactly as it is now, just untraceable
 * again.
 *
 * Clause K-07 fails on 10 surfaces today precisely because no column anywhere
 * joins a credit transaction to the message that caused it, so a billing
 * dispute about a specific chat cannot be answered. `message_id` +
 * `existing_credit_transaction_reference` is that join.
 *
 * INV-06: exactly one charge per logical request, enforced by a unique index on
 * idempotency_record_id — not by application logic.
 *
 * No foreign keys (matching creative_jobs): rows may reference messages in five
 * different stores, and the table must stay droppable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('message_charges')) {
            return;
        }

        Schema::create('message_charges', function (Blueprint $t) {
            $t->id();

            // ── tenancy ──────────────────────────────────────────────────────
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id')->nullable()->index();

            // ── what was charged for ─────────────────────────────────────────
            $t->string('surface', 32);
            $t->string('agent_id', 64)->nullable();
            $t->string('conversation_id', 128)->nullable();
            $t->unsignedBigInteger('message_id')->nullable();     // K-07: the missing join
            $t->string('message_store', 48)->nullable();          // which of the 5 stores message_id lives in
            $t->string('client_message_id', 128)->nullable();

            // ── linkage ──────────────────────────────────────────────────────
            $t->unsignedBigInteger('idempotency_record_id');
            $t->string('idempotency_key', 191)->nullable();
            $t->string('correlation_id', 64);

            // ── provider + usage ─────────────────────────────────────────────
            $t->string('provider', 48)->nullable();
            $t->string('model', 64)->nullable();
            $t->unsignedInteger('usage_units')->nullable();       // tokens where known

            // ── amounts (record of what the LEDGER did, not a balance) ───────
            $t->unsignedInteger('estimated_credits')->default(0);
            $t->unsignedInteger('reserved_credits')->default(0);
            $t->unsignedInteger('charged_credits')->default(0);
            $t->unsignedInteger('released_credits')->default(0);

            // pending · reserved · executing · committed · released
            // · failed · not_chargeable
            $t->string('status', 24)->default('pending');

            // Join back to the authoritative ledger.
            $t->string('existing_credit_transaction_reference', 128)->nullable();

            $t->timestamp('provider_execution_started_at')->nullable();
            $t->timestamp('reserved_at')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->string('failure_code', 48)->nullable();

            $t->json('metadata')->nullable();
            $t->timestamps();

            // INV-06 — one charge per logical request, enforced by the database.
            $t->unique('idempotency_record_id', 'msg_charges_idem_unique');

            $t->index(['workspace_id', 'status'], 'msg_charges_ws_status_idx');
            $t->index('existing_credit_transaction_reference', 'msg_charges_ledger_ref_idx'); // reconciliation
            $t->index('correlation_id', 'msg_charges_correlation_idx');
            $t->index(['message_store', 'message_id'], 'msg_charges_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_charges');
    }
};

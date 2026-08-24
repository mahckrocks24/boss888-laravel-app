<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL888 · delivery observability.
 *
 * WHY THIS EXISTS
 * The platform accepted 37 notification sends to a suppressed recipient and
 * reported nothing. "Mail::send returned" was mistaken for "the customer got
 * it". These two tables make that mistake impossible to repeat: every outbound
 * message gets a row, and no row reaches a terminal state without provider
 * evidence.
 *
 * NO SECRETS AND NO BODIES. The ledger records routing and outcome, not
 * content. `provider_response` holds the provider's own diagnostic text only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $t) {
            $t->id();

            // Correlates one logical business action to every message it caused,
            // across retries and across engines.
            $t->uuid('correlation_id')->index();

            $t->unsignedBigInteger('workspace_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();

            // Provider-neutral classification. 'unclassified' is deliberately a
            // visible state, not a silent default.
            $t->string('purpose', 64)->default('unclassified')->index();
            $t->string('stream_class', 24)->default('transactional')->index();

            $t->string('provider', 32)->default('postmark');
            $t->string('provider_message_id', 128)->nullable();
            $t->string('provider_stream', 64)->nullable();

            $t->string('sender_address', 255)->nullable();
            $t->string('sender_name', 255)->nullable();
            $t->string('recipient_address', 320)->index();
            $t->string('subject', 255)->nullable();

            $t->string('state', 24)->default('queued')->index();

            $t->timestamp('queued_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('deferred_at')->nullable();
            $t->timestamp('bounced_at')->nullable();
            $t->timestamp('suppressed_at')->nullable();
            $t->timestamp('failed_at')->nullable();

            $t->string('failure_category', 48)->nullable()->index();
            $t->boolean('retryable')->default(false);

            $t->json('provider_response')->nullable();
            $t->json('metadata')->nullable();

            $t->timestamps();

            // One ledger row per provider message. Duplicate webhook or duplicate
            // send cannot fork the history. MySQL permits many NULLs here, which
            // is what we want before the provider has issued an id.
            $t->unique(['provider', 'provider_message_id'], 'email_deliveries_provider_msg_uq');
            $t->index(['workspace_id', 'state'], 'email_deliveries_ws_state_idx');
            $t->index(['recipient_address', 'state'], 'email_deliveries_rcpt_state_idx');
            $t->index(['state', 'queued_at'], 'email_deliveries_state_queued_idx');
        });

        Schema::create('email_delivery_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('email_delivery_id')->nullable()->index();

            $t->string('provider', 32)->default('postmark');
            $t->string('provider_message_id', 128)->nullable()->index();

            // Normalised first, raw kept alongside. Product code reads the
            // normalised column; the raw one exists so an operator can audit
            // what the provider actually said.
            $t->string('event_type', 32)->index();
            $t->string('provider_event_type', 64)->nullable();

            $t->timestamp('occurred_at')->nullable();

            // Idempotency key for webhook replay. A provider that redelivers the
            // identical payload produces one row, not two.
            $t->char('payload_digest', 64);

            $t->json('raw_metadata')->nullable();
            $t->timestamps();

            $t->unique(['provider', 'payload_digest'], 'email_delivery_events_digest_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_events');
        Schema::dropIfExists('email_deliveries');
    }
};

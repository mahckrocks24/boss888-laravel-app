<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL888 EM-6 — durable audit of webhook INGRESS.
 *
 * email_delivery_events records what the provider told us about a message.
 * This records every ATTEMPT to tell us anything at all — including the ones we
 * refused. Before it existed, a wrong-secret request returned 404 and vanished:
 * an operator could not distinguish "nobody is calling us" from "someone is
 * probing" from "Postmark has been silently refused since a credential change".
 *
 * NEVER STORED: credentials, Authorization headers, provider tokens, raw bodies.
 * The digest proves identity without retaining content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_webhook_receipts', function (Blueprint $t) {
            $t->id();

            $t->timestamp('received_at')->index();
            $t->string('provider', 32)->default('postmark')->index();

            // Path only. The secret is a credential and never lands here.
            $t->string('endpoint', 191)->nullable();
            $t->string('stream', 64)->nullable();

            // Ours, minted per attempt, so an operator can quote one line.
            $t->uuid('request_id')->unique();

            $t->uuid('correlation_id')->nullable()->index();
            $t->string('provider_message_id', 128)->nullable()->index();
            $t->string('event_type', 48)->nullable()->index();

            // The four questions, each answered independently. A single
            // "success" boolean is what let HTTP 200 stand in for processing.
            $t->string('authentication_result', 32)->index();
            $t->string('validation_result', 32)->nullable();
            $t->string('processing_stage', 32)->nullable()->index();
            $t->string('processing_result', 32)->nullable()->index();

            $t->char('payload_digest', 64)->nullable()->index();
            $t->unsignedInteger('processing_latency_ms')->nullable();

            // Plain language for a human. Never an exception trace, never a secret.
            $t->string('operator_visible_reason', 255)->nullable();

            $t->string('client_ip', 45)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();

            // Replay lineage. The original receipt is never modified.
            $t->unsignedBigInteger('replay_of_receipt_id')->nullable()->index();
            $t->unsignedBigInteger('replayed_by_user_id')->nullable();
            $t->string('replay_reason', 255)->nullable();

            $t->timestamps();

            $t->index(['authentication_result', 'received_at'], 'ewr_auth_time_idx');
            $t->index(['processing_result', 'received_at'], 'ewr_proc_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_webhook_receipts');
    }
};

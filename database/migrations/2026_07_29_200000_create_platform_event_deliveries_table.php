<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subscriber delivery ledger — Phase 1B.
 *
 * platform_events records the immutable FACT.
 * platform_event_deliveries records ONE SUBSCRIBER'S HANDLING of that fact.
 *
 * These are deliberately separate. A single event may have many subscribers with
 * independent outcomes: audit may succeed while notification is still retrying.
 * A single status on the event row cannot express that, and pretending it can is
 * how a partially-delivered event gets reported as complete.
 *
 * The unique constraint below is the hard idempotency guarantee. Combined with
 * writing the subscriber's output and the 'delivered' status in ONE transaction,
 * it makes duplicate logical delivery impossible without a "check then insert"
 * race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_event_deliveries', function (Blueprint $table) {
            $table->id();

            // The event being delivered. Not a FK: platform_events rows are
            // immutable and never deleted, and a FK would add lock contention
            // to the fan-out hot path for no integrity gain.
            $table->uuid('event_id');

            // Identity of the consumer, e.g. 'platform.audit'.
            $table->string('subscriber_key', 64);

            // A behaviour change in a subscriber increments its version. That
            // makes the delivery identity different, so a deliberate re-delivery
            // under new behaviour is possible WITHOUT reopening old rows.
            $table->unsignedSmallInteger('subscriber_version');

            // Denormalised from the event so a worker can filter and a query can
            // scope by tenant without joining.
            $table->unsignedBigInteger('workspace_id')->index();

            // pending → processing → delivered
            //                     ↘ retryable_failure → (claimed again)
            //                     ↘ permanently_failed
            // skipped: eligible event the subscriber deliberately will not handle
            //          (e.g. an unsupported schema version). NEVER silent success.
            $table->string('status', 24)->default('pending');

            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();

            // Why a row was skipped, so 'skipped' is never a mystery.
            $table->string('skip_reason', 191)->nullable();

            $table->timestamps();

            // ── THE IDEMPOTENCY GUARANTEE ──────────────────────────────────
            $table->unique(['event_id', 'subscriber_key', 'subscriber_version'], 'ped_identity_unique');

            // Worker claim path: due work for a subscriber, oldest first.
            $table->index(['subscriber_key', 'status', 'next_attempt_at'], 'ped_claim_idx');
            // Operational inspection per tenant.
            $table->index(['workspace_id', 'status'], 'ped_ws_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_event_deliveries');
    }
};

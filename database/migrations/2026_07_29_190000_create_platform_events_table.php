<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform transactional outbox — Phase 1A.
 *
 * ONE platform-wide table. Not an infrastructure table, not a domain table.
 * `infra_events` remains subsystem detail and is NOT replaced by this.
 *
 * The row is written inside the same transaction as the business state change
 * it describes, so either both commit or neither does. Dispatch happens later,
 * from a separate process, and can never roll the business change back.
 *
 * Field discipline: every column below has a demonstrated purpose. The ADR's
 * suggested `available_at` was deliberately dropped and replaced by
 * `next_attempt_at`, which serves retry backoff today and delayed delivery
 * later, rather than carrying two columns for one idea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_events', function (Blueprint $table) {
            // Surrogate key: cheap ordering for the pending poll.
            $table->id();

            // Globally unique, DETERMINISTIC identity. Derived from
            // (event_type, subject, schema_version) so a duplicate producer
            // invocation collides here instead of writing a second logical
            // event. This unique index IS the idempotency constraint.
            $table->uuid('event_id')->unique();

            // Tenancy lives on the event itself. Never nullable: an event that
            // cannot name its workspace cannot be safely delivered to anyone.
            $table->unsignedBigInteger('workspace_id')->index();

            $table->string('event_type', 64);
            $table->unsignedSmallInteger('schema_version')->default(1);

            // engine.action that produced this, where one applies.
            $table->string('capability_key', 128)->nullable();

            // user | agent | system | customer
            $table->string('actor_type', 24);
            $table->unsignedBigInteger('actor_id')->nullable();

            // subject_id is a string: subjects are not always numeric
            // (a domain name is a legitimate subject).
            $table->string('subject_type', 64);
            $table->string('subject_id', 191);

            // correlation = one customer journey. causation = the event that
            // caused this one. Together they give a full causal chain.
            $table->uuid('correlation_id')->index();
            $table->uuid('causation_id')->nullable();

            // Immutable after insert. Never contains secrets — enforced by the
            // Outbox service and by an architecture test.
            $table->json('payload_json');

            // public | internal | restricted
            $table->string('sensitivity', 16)->default('internal');

            // pending → dispatching → dispatched | no_subscribers | failed
            //
            // 'no_subscribers' is a distinct terminal state on purpose. Marking
            // an event 'dispatched' when nothing consumed it would be a false
            // claim of delivery, and Phase 1A has no subscribers at all.
            $table->string('status', 20)->default('pending');

            $table->timestamp('occurred_at');          // when the fact happened
            $table->timestamp('recorded_at');          // when we wrote it
            $table->timestamp('next_attempt_at')->nullable();  // retry backoff
            $table->timestamp('locked_at')->nullable();        // crash reaping
            $table->timestamp('dispatched_at')->nullable();

            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();

            // Pending poll: status + id ordering.
            $table->index(['status', 'id'], 'pe_status_id_idx');
            // Workspace timeline for operational inspection.
            $table->index(['workspace_id', 'occurred_at'], 'pe_ws_occurred_idx');
            // Per-type inspection and future replay.
            $table->index(['event_type', 'occurred_at'], 'pe_type_occurred_idx');
        });
    }

    /**
     * Rollback drops recorded events.
     *
     * Safe while the table is empty (Phase 1A, shadow producer default-off).
     * Once real events exist, this migration must NOT be rolled back without a
     * retention and export decision — recorded events are the audit substrate
     * every later phase depends on.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_events');
    }
};

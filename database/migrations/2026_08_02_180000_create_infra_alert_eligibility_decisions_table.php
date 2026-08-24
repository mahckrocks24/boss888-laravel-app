<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · S8.3 — ELIGIBILITY DECISION AUDIT.
 *
 * The question this phase answers is not "how is an alert delivered" but
 * "is this alert allowed to leave INFRA888 at all".
 *
 * That decision has to be explainable after the fact, in both directions. When
 * a customer asks why they were paged at 3am, and when an operator asks why they
 * were NOT told about something that mattered, the answer must be reconstructable
 * from data rather than reasoned about from code.
 *
 * So every rule evaluation is recorded — not just the final verdict — and the
 * table is append-only. A decision that changes tomorrow because the estate
 * changed is a new row, not an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_alert_eligibility_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id');
            $table->string('subject', 253);
            $table->string('alert_key', 191);

            // eligible | ineligible | suppressed | deferred | manual_review
            $table->string('verdict', 20);
            $table->string('deciding_rule', 64)->nullable();
            $table->text('reason')->nullable();

            // Full trail: every rule that ran, in order, with its own result.
            $table->json('trail_json')->nullable();

            // Context the decision was made against, so a historical row stays
            // interpretable after the estate moves on.
            $table->string('custody', 24)->nullable();
            $table->string('severity', 16)->nullable();
            $table->string('confidence', 16)->nullable();
            $table->string('environment', 24)->nullable();

            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('alert_id');
            $table->index(['verdict', 'decided_at']);
            $table->index('subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_alert_eligibility_decisions');
    }
};

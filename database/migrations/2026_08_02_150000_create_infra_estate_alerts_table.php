<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · S7 — ESTATE ALERT LEDGER.
 *
 * S6 could detect drift. It could not remember that it had already told you.
 * Re-running observation produced the same findings again, with no notion of
 * acknowledgement, suppression, escalation or recovery — which is the difference
 * between a diagnostic tool and an operational service.
 *
 * An alert has IDENTITY (`alert_key` = domain + drift class), so the same
 * problem seen twelve times is one alert with an occurrence count, not twelve
 * alerts. It has a LIFECYCLE that an operator drives.
 *
 * THE LOAD-BEARING RULE: `recovered_by_observation_id` is NOT NULL for every
 * recovered alert. An alert may only close because a FRESH SUCCESSFUL
 * OBSERVATION proved the drift gone. It can never close because it got old,
 * because nobody looked, or because the registrar became unreachable. Timing out
 * an alert is how a real problem becomes an invisible one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_estate_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('customer_domain_id')->nullable();
            $table->string('domain', 253);

            // Stable identity for one problem on one subject.
            $table->string('alert_key', 191)->unique();
            $table->string('drift_class', 64);
            // critical | high | medium | low | info
            $table->string('severity', 16);

            // open | acknowledged | suppressed | escalated | recovered
            $table->string('state', 16)->default('open');
            $table->string('previous_state', 16)->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('occurrence_count')->default(1);

            // ── acknowledgement ─────────────────────────────────────────────
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->text('acknowledgement_note')->nullable();

            // ── suppression (time-boxed on purpose; permanent silence is how
            //    outages get missed) ────────────────────────────────────────
            $table->timestamp('suppressed_until')->nullable();
            $table->text('suppression_reason')->nullable();
            $table->unsignedBigInteger('suppressed_by')->nullable();

            // ── escalation ──────────────────────────────────────────────────
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('next_escalation_at')->nullable();

            // ── recovery — only ever by fresh observation ───────────────────
            $table->timestamp('recovered_at')->nullable();
            $table->unsignedBigInteger('recovered_by_observation_id')->nullable();

            $table->json('detail_json')->nullable();
            $table->json('history_json')->nullable();
            $table->timestamps();

            $table->index(['state', 'severity']);
            $table->index('customer_domain_id');
            $table->index('domain');
            $table->index('next_escalation_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_estate_alerts');
    }
};

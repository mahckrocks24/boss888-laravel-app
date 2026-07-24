<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-9 — Provider control-plane events.
 *
 * WHY A SEPARATE TABLE FROM `infra_events`
 * ----------------------------------------
 * `infra_events` is WORKSPACE-SCOPED — it uses the BelongsToWorkspace global
 * scope and requires a workspace_id. Provider control-plane activity has no
 * owning workspace: registering a provider or rotating a platform credential is
 * not something that happened *to a tenant*.
 *
 * Forcing these into `infra_events` would mean inventing a fake workspace_id for
 * platform events, which would then either leak platform operations into a
 * tenant's history or be hidden from every query by the global scope. Both are
 * wrong. Operational events and commercial/tenant events stay separate
 * (directive §2B-9: "These are operational events, not commercial events").
 *
 * APPEND-ONLY
 * No updated_at. Model-level guards reject update and delete. Corrections are
 * NEW events, never edits — a history that can be rewritten is not evidence.
 *
 * 🔴 NO SECRETS. Every payload passes through ProviderEventRecorder::sanitize()
 * before it reaches `metadata_json`. There is no column for a credential body,
 * an authorization header, or a raw provider response.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_provider_events')) {
            Schema::create('infra_provider_events', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Immutable external identifier, safe to quote in an incident
                // report or expose through an API without revealing row counts.
                $table->uuid('event_uid')->unique('infra_provevt_uid_uq');

                $table->unsignedBigInteger('provider_id')->nullable();
                // Denormalised so an event survives provider deletion. Historical
                // truth must not depend on a row that still exists.
                $table->string('provider_key', 64)->nullable();

                $table->string('capability', 64)->nullable();
                $table->string('environment', 32)->nullable();

                $table->string('event_type', 64);
                $table->string('severity', 16)->default('info');  // info|success|warning|error|critical

                // ── actor ────────────────────────────────────────────────────
                // actor_type distinguishes a human from the scheduler from a
                // queue worker. "system" is a real answer, not a missing one.
                $table->string('actor_type', 32)->default('system'); // user|system|scheduler|worker|api
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_label', 120)->nullable();

                $table->string('from_state', 32)->nullable();
                $table->string('to_state', 32)->nullable();

                // Ties a chain of events to one logical activity — the thing that
                // makes "what happened during that rotation" answerable.
                $table->string('correlation_id', 64)->nullable();
                $table->unsignedBigInteger('operation_id')->nullable();
                $table->unsignedBigInteger('credential_id')->nullable();

                $table->text('summary')->nullable();
                $table->json('metadata_json')->nullable();   // sanitized only

                $table->timestamp('created_at')->nullable();

                $table->index(['provider_id', 'created_at'], 'infra_provevt_prov_idx');
                $table->index(['event_type', 'created_at'], 'infra_provevt_type_idx');
                $table->index('correlation_id', 'infra_provevt_corr_idx');
                $table->index(['severity', 'created_at'], 'infra_provevt_sev_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_provider_events');
    }
};

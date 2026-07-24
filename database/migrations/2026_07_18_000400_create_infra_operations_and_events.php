<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1A — provisioning operations + immutable event log.
 *
 * infra_operations = one attempted state-changing action against a provider.
 *                    Created BEFORE the provider is called, so a crash mid-flight
 *                    leaves a recoverable record rather than an invisible orphan.
 * infra_events     = append-only history. This is INFRA888's forensic record.
 *
 * Directive §10 requires idempotency, retry classification, stuck-operation
 * recovery, timeout handling, provider correlation IDs, human-readable failure
 * summaries, and raw provider errors stored safely + redacted. Every one of those
 * is a column here rather than a convention.
 *
 * infra_events exists because the platform audit_logs path is insufficient for
 * infrastructure (Phase 0 audit §7): it records array_keys($params) only — no
 * values — and denials/failures return early before the audit write ever happens.
 * infra_events records before/after state and DOES record failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_operations')) {
            Schema::create('infra_operations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');

                $table->string('owner_type', 64);
                $table->unsignedBigInteger('owner_id')->nullable();

                $table->string('operation', 64);   // provision_hosting | connect_domain | create_mailbox ...
                $table->string('state', 32)->default('queued');
                $table->string('capability', 64)->nullable();
                $table->string('provider', 64)->nullable();

                // Idempotency: unique per workspace. A retried job MUST reuse the key
                // so a 4x TaskExecutionJob retry cannot provision four times.
                $table->string('idempotency_key', 191);

                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->unsignedTinyInteger('max_attempts')->default(3);
                $table->string('retry_classification', 32)->nullable(); // retryable|permanent|manual
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('timeout_at')->nullable();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                // Governance linkage — which human approved this destructive action.
                $table->unsignedBigInteger('approval_id')->nullable();
                $table->unsignedBigInteger('task_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('source', 32)->default('manual'); // manual|agent|task|admin_bypass|system

                $table->string('provider_correlation_id', 191)->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->text('failure_summary')->nullable();      // human-readable, no vendor leak
                $table->json('request_json')->nullable();          // redacted
                $table->json('result_json')->nullable();           // redacted
                $table->timestamps();

                $table->unique(['workspace_id', 'idempotency_key'], 'infra_ops_idem_uq');
                $table->index(['workspace_id', 'state'], 'infra_ops_ws_state_idx');
                $table->index(['state', 'next_retry_at'], 'infra_ops_retry_idx');
                $table->index(['state', 'timeout_at'], 'infra_ops_timeout_idx');
                $table->index(['owner_type', 'owner_id'], 'infra_ops_owner_idx');
            });
        }

        if (!Schema::hasTable('infra_events')) {
            Schema::create('infra_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');

                $table->string('owner_type', 64);
                $table->unsignedBigInteger('owner_id')->nullable();

                $table->string('event', 64);       // state_changed | operation_failed | permission_denied ...
                $table->string('severity', 16)->default('info'); // info|success|warning|error
                $table->string('from_state', 32)->nullable();
                $table->string('to_state', 32)->nullable();

                $table->unsignedBigInteger('operation_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('source', 32)->default('system');
                $table->string('provider', 64)->nullable();
                $table->string('provider_resource_id', 191)->nullable();

                $table->text('summary')->nullable();
                $table->json('context_json')->nullable();   // redacted; never secrets

                // Append-only: created_at ONLY, mirroring audit_logs. No updated_at.
                $table->timestamp('created_at')->useCurrent();

                $table->index(['workspace_id', 'created_at'], 'infra_events_ws_created_idx');
                $table->index(['owner_type', 'owner_id'], 'infra_events_owner_idx');
                $table->index(['event', 'severity'], 'infra_events_event_sev_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_events');
        Schema::dropIfExists('infra_operations');
    }
};

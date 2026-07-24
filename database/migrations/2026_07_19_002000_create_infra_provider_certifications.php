<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-CERT — provider certification framework.
 *
 * PROVIDER-NEUTRAL BY CONSTRUCTION. Nothing here names a vendor. Cloudflare is
 * simply the first subject; the same three tables certify a registrar, a mail
 * provider or a backup provider without alteration.
 *
 * THREE TABLES, THREE JOBS
 *   infra_provider_certifications — the verdict for one provider+capability+environment
 *   infra_certification_checks    — one evidence-backed answer per checklist item
 *   infra_certification_waivers   — governed exceptions that can never silently pass
 *
 * WHY A CHECK IS ITS OWN ROW RATHER THAN A JSON BLOB
 * A certification's value is entirely in its evidence. Storing 60 answers as one
 * JSON column would make "which control failed, on what evidence, assessed by
 * whom, and when" unqueryable — which is exactly the question an auditor asks.
 * One row per check keeps that answerable, and keeps evidence attached to the
 * decision it justifies rather than to the certification as a whole.
 *
 * IMMUTABILITY: a completed certification is never edited. Recertification
 * creates a NEW row referencing the old one via `supersedes_id`. History must
 * survive, because "was this provider certified when we activated it?" is a
 * question asked after an incident, not before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_provider_certifications')) {
            Schema::create('infra_provider_certifications', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Immutable external identifier, quotable in an audit report.
                $table->uuid('certification_uid')->unique('infra_cert_uid_uq');

                $table->unsignedBigInteger('provider_id');
                // Denormalised so a certification survives provider deletion.
                $table->string('provider_key', 64);

                // NULL capability = a provider-wide certification (ownership,
                // governance, commercial). Non-null = capability-specific.
                $table->string('capability', 64)->nullable();
                $table->string('environment', 32)->default('sandbox');

                $table->string('adapter_version', 32)->nullable();
                $table->string('provider_api_version', 32)->nullable();

                // draft|in_review|passed|passed_with_conditions|failed|expired|revoked
                // Deliberately NOT a boolean: "certified" has more than two states,
                // and passed_with_conditions must never collapse into passed.
                $table->string('status', 32)->default('draft');

                // 0=registered 1=read_only_verified 2=sandbox_validated
                // 3=staging_certified 4=production_approved
                $table->unsignedTinyInteger('level')->default(0);
                // The level the assessment was ATTEMPTING. If level < level_target
                // the gap is explicit rather than inferred from a missing pass.
                $table->unsignedTinyInteger('level_target')->default(1);

                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('recertify_after')->nullable();

                $table->unsignedBigInteger('assessor_user_id')->nullable();
                $table->string('assessor_label', 120)->nullable();

                // Chain, never overwrite.
                $table->unsignedBigInteger('supersedes_id')->nullable();
                $table->unsignedBigInteger('superseded_by_id')->nullable();

                // Denormalised counters so a dashboard need not aggregate 60 rows.
                $table->unsignedSmallInteger('checks_total')->default(0);
                $table->unsignedSmallInteger('checks_passed')->default(0);
                $table->unsignedSmallInteger('checks_failed')->default(0);
                $table->unsignedSmallInteger('checks_blocked')->default(0);
                $table->unsignedSmallInteger('checks_not_tested')->default(0);
                $table->unsignedSmallInteger('waivers_active')->default(0);

                // Named blockers preventing a higher level. Not free text: a list
                // an operator can act on.
                $table->json('blockers_json')->nullable();
                $table->json('summary_json')->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();

                $table->index(['provider_id', 'capability', 'environment'], 'infra_cert_scope_idx');
                $table->index(['status', 'level'], 'infra_cert_status_idx');
                $table->index('expires_at', 'infra_cert_exp_idx');

                $table->foreign('provider_id', 'infra_cert_prov_fk')
                    ->references('id')->on('infra_providers')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('infra_certification_checks')) {
            Schema::create('infra_certification_checks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('certification_id');

                // Domain A..H from the checklist framework.
                $table->string('domain', 32);
                $table->string('check_key', 96);
                $table->text('description')->nullable();

                // pass|pass_with_condition|fail|not_applicable|not_tested|blocked
                // `not_tested` and `blocked` are DISTINCT from `fail` on purpose:
                // "we could not test this" is not "this is broken", and neither is
                // a pass. Unknown must never equal pass.
                $table->string('result', 32)->default('not_tested');

                // Critical checks cannot be waived into an unconditional pass.
                $table->boolean('is_critical')->default(false);

                // runtime|readonly_api|config_snapshot|database|documentation|
                // screenshot|test_result|audit_event|attestation|contract|pricing_source
                $table->string('evidence_type', 32)->nullable();
                $table->text('evidence_reference')->nullable();
                // Sanitized. Never a raw authorization header or secret.
                $table->json('evidence_json')->nullable();

                // Guards the most common integrity failure in an audit: presenting
                // an inference as though it were an observation.
                $table->boolean('evidence_is_runtime')->default(false);
                $table->string('confidence', 16)->default('medium'); // high|medium|low

                $table->unsignedBigInteger('assessor_user_id')->nullable();
                $table->timestamp('assessed_at')->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();

                $table->unique(['certification_id', 'check_key'], 'infra_certcheck_uq');
                $table->index(['certification_id', 'result'], 'infra_certcheck_result_idx');
                $table->index(['domain', 'result'], 'infra_certcheck_domain_idx');

                $table->foreign('certification_id', 'infra_certcheck_cert_fk')
                    ->references('id')->on('infra_provider_certifications')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('infra_certification_waivers')) {
            Schema::create('infra_certification_waivers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('waiver_uid')->unique('infra_waiver_uid_uq');

                $table->unsignedBigInteger('certification_id');
                $table->string('check_key', 96);

                $table->text('risk');
                $table->text('justification');
                $table->text('compensating_control')->nullable();

                // Separation of duties applies to waivers too: the person who
                // requests an exception must not be the one who grants it.
                $table->unsignedBigInteger('owner_user_id');
                $table->unsignedBigInteger('approver_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();

                // Expiry is REQUIRED at the service layer. A permanent waiver is
                // not a waiver, it is a silent policy change.
                $table->timestamp('expires_at');
                $table->timestamp('review_at')->nullable();

                $table->json('affected_capabilities_json')->nullable();
                $table->json('affected_environments_json')->nullable();

                // pending|active|expired|revoked
                $table->string('status', 32)->default('pending');
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason', 191)->nullable();

                $table->timestamps();

                $table->index(['certification_id', 'status'], 'infra_waiver_cert_idx');
                $table->index('expires_at', 'infra_waiver_exp_idx');

                $table->foreign('certification_id', 'infra_waiver_cert_fk')
                    ->references('id')->on('infra_provider_certifications')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_certification_waivers');
        Schema::dropIfExists('infra_certification_checks');
        Schema::dropIfExists('infra_provider_certifications');
    }
};

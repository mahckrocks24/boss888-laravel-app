<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXPERIENCE888 — workspace-scoped governed experience.
 *
 * Every table carries workspace_id and it is NOT nullable. The legacy stores
 * (global_knowledge, agent_experience_stats) have no workspace identity, which
 * is why they cannot be used as Experience888 stores and are contained instead.
 *
 * Nothing here grants authority. These tables record what happened and what it
 * implies; they can never carry permission, approval or credit entitlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── EVENTS: something meaningful happened, with provenance ──────────
        if (!Schema::hasTable('experience_events')) {
            Schema::create('experience_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('event_type', 48)->index();
                $t->timestamp('occurred_at')->index();

                $t->string('actor_type', 24)->default('system');   // owner|sarah|system|engine
                $t->string('actor_id', 64)->nullable();

                $t->string('capability', 48)->nullable()->index();  // write|seo|creative|crm|...
                $t->string('action', 64)->nullable()->index();
                $t->string('entity_type', 48)->nullable();
                $t->string('entity_id', 64)->nullable();

                // Correlation to the authoritative records this was derived from.
                $t->string('conversation_id', 120)->nullable()->index();
                $t->string('execution_id', 64)->nullable()->index();
                $t->unsignedBigInteger('task_id')->nullable()->index();
                $t->unsignedBigInteger('proposal_id')->nullable();
                $t->unsignedBigInteger('commitment_id')->nullable();
                $t->unsignedBigInteger('source_message_id')->nullable();

                // Provenance: WHICH authoritative row proves this event happened.
                $t->string('evidence_type', 48);      // tasks|approvals|articles|sarah_commitments|agent_messages
                $t->string('evidence_id', 64);
                $t->json('payload_json')->nullable();

                // Idempotency: replaying a source row must not double-count.
                $t->string('dedupe_key', 190)->unique();
                $t->timestamps();

                $t->index(['workspace_id', 'event_type', 'occurred_at'], 'exp_ev_ws_type_time');
                $t->index(['workspace_id', 'capability', 'action'], 'exp_ev_ws_cap_action');
            });
        }

        // ── OUTCOMES: what actually resulted, and how strongly we can say so ─
        if (!Schema::hasTable('experience_outcomes')) {
            Schema::create('experience_outcomes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('event_id')->index();      // the action/recommendation
                $t->string('outcome_type', 48)->index();          // succeeded|failed|published|recovered|no_effect
                $t->timestamp('observed_at')->index();

                /**
                 * Attribution is the whole point. "We did X" is not "X caused Y".
                 * UNKNOWN < OBSERVED < CORRELATED < LIKELY_CONTRIBUTOR < DIRECTLY_ATTRIBUTABLE
                 * Only DIRECTLY_ATTRIBUTABLE may be stated as causation.
                 */
                $t->string('attribution_level', 24)->default('UNKNOWN')->index();
                $t->text('attribution_basis')->nullable();   // why this level and not a stronger one

                $t->json('value_json')->nullable();
                $t->string('evidence_type', 48);
                $t->string('evidence_id', 64);
                $t->string('status', 24)->default('resolved'); // pending|resolved|unverifiable
                $t->string('dedupe_key', 190)->unique();
                $t->timestamps();

                $t->index(['workspace_id', 'outcome_type', 'observed_at'], 'exp_out_ws_type_time');
            });
        }

        // ── PATTERNS: a repeated relationship, never a single observation ────
        if (!Schema::hasTable('experience_patterns')) {
            Schema::create('experience_patterns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('pattern_type', 48)->index();   // action_recovery|owner_preference|publish_outcome
                $t->string('subject', 190)->index();       // e.g. "retry:write_article"
                $t->text('statement');                     // human-readable, derived from evidence
                $t->json('payload_json')->nullable();      // typed form for machine use

                $t->unsignedInteger('sample_size')->default(0);
                $t->unsignedInteger('positive_count')->default(0);
                $t->unsignedInteger('negative_count')->default(0);
                $t->unsignedInteger('contradiction_count')->default(0);

                $t->timestamp('first_observed_at')->nullable();
                $t->timestamp('last_observed_at')->nullable();
                $t->timestamp('last_evaluated_at')->nullable();

                $t->string('confidence_level', 12)->default('LOW');  // LOW|MEDIUM|HIGH
                $t->string('status', 24)->default('HYPOTHESIS');
                // HYPOTHESIS -> PATTERN -> EXPERIENCE ; CONTRADICTED | STALE | SUPERSEDED
                $t->unsignedBigInteger('supersedes_id')->nullable();
                $t->timestamps();

                $t->unique(['workspace_id', 'pattern_type', 'subject'], 'exp_pat_ws_type_subject');
                $t->index(['workspace_id', 'status', 'confidence_level'], 'exp_pat_ws_status_conf');
            });
        }

        // ── EVIDENCE: a pattern must be reproducible from its evidence ──────
        if (!Schema::hasTable('experience_pattern_evidence')) {
            Schema::create('experience_pattern_evidence', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('pattern_id')->index();
                $t->unsignedBigInteger('event_id')->nullable();
                $t->unsignedBigInteger('outcome_id')->nullable();
                $t->string('direction', 16);   // supports|contradicts
                $t->decimal('weight', 5, 3)->default(1.000);
                $t->timestamp('observed_at')->index();
                $t->timestamps();

                $t->unique(['pattern_id', 'event_id', 'outcome_id'], 'exp_pev_unique');
            });
        }

        // ── OWNER FEEDBACK: corrections, preferences, policy ────────────────
        if (!Schema::hasTable('experience_owner_feedback')) {
            Schema::create('experience_owner_feedback', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('source_message_id')->nullable()->index();
                $t->string('conversation_id', 120)->nullable();

                // FACT_CORRECTION|PREFERENCE|POLICY|ONE_OFF_INSTRUCTION|
                // STRATEGIC_DECISION|OUTCOME_FEEDBACK
                $t->string('feedback_type', 32)->index();
                $t->string('subject', 190)->index();
                $t->text('value');

                // Explicit owner statements outrank inferred ones, and a one-off
                // never silently becomes durable policy.
                $t->string('explicitness', 16)->default('inferred'); // explicit|inferred
                $t->string('strength', 16)->default('WEAK');         // WEAK|MEDIUM|STRONG
                $t->boolean('durable')->default(false);
                $t->unsignedBigInteger('supersedes_id')->nullable();
                $t->timestamp('superseded_at')->nullable();
                $t->string('quote', 500)->nullable();   // provenance: the owner's own words
                $t->timestamps();

                $t->index(['workspace_id', 'subject', 'durable'], 'exp_fb_ws_subject_durable');
            });
        }

        // ── PLAYBOOKS: promoted experience, evidence-gated ──────────────────
        if (!Schema::hasTable('experience_playbooks')) {
            Schema::create('experience_playbooks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->string('name', 190);
                $t->string('trigger_type', 64)->index();
                $t->text('objective')->nullable();
                $t->json('preconditions_json')->nullable();
                $t->json('steps_json')->nullable();
                $t->json('expected_outcome_json')->nullable();
                $t->json('risks_json')->nullable();
                $t->json('reversal_conditions_json')->nullable();

                $t->unsignedInteger('evidence_count')->default(0);
                $t->unsignedInteger('success_count')->default(0);
                $t->unsignedInteger('failure_count')->default(0);
                $t->string('confidence_level', 12)->default('LOW');
                // CANDIDATE|ACTIVE|DEGRADED|DEPRECATED|SUPERSEDED
                $t->string('status', 16)->default('CANDIDATE')->index();
                $t->unsignedInteger('version')->default(1);
                $t->timestamp('last_validated_at')->nullable();
                $t->unsignedBigInteger('supersedes_id')->nullable();
                $t->unsignedBigInteger('derived_from_pattern_id')->nullable();
                $t->timestamps();

                $t->unique(['workspace_id', 'name', 'version'], 'exp_pb_ws_name_version');
            });
        }

        // ── PLAYBOOK RUNS: applications and their results ───────────────────
        if (!Schema::hasTable('experience_playbook_runs')) {
            Schema::create('experience_playbook_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('playbook_id')->index();
                $t->string('conversation_id', 120)->nullable();
                $t->string('execution_id', 64)->nullable();
                $t->timestamp('applied_at')->index();
                $t->string('result', 24)->default('pending'); // pending|success|failure|abandoned
                $t->unsignedBigInteger('outcome_id')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'experience_playbook_runs', 'experience_playbooks', 'experience_owner_feedback',
            'experience_pattern_evidence', 'experience_patterns', 'experience_outcomes',
            'experience_events',
        ] as $t) Schema::dropIfExists($t);
    }
};

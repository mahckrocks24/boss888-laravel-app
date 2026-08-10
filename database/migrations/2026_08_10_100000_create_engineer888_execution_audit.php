<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables that let an execution be reconstructed after the process that ran
 * it is gone.
 *
 * WHY NEITHER OF THESE IS AN EXISTING TABLE. `engineering_executions` is
 * commit-plan history — a different engine, driven from a console command, whose
 * rows describe staged commits rather than candidate installs.
 * `engineering_snapshots` is RepositoryIntelligence output.
 * `engineering_task_stages` looks closest and is the worst choice of all: it is
 * DELETED at the start of every run, so the one record that would matter after a
 * crash is the one a re-run erases. `engineering_recoveries` records that a
 * recovery HAPPENED; what was missing is evidence written BEFORE the first byte,
 * while a recovery is still hypothetical. Overloading any of them would make
 * two different questions share one answer.
 *
 * APPEND-ONLY IN INTENT, ENFORCED IN CODE. Rows are inserted once and only ever
 * transition their status fields. The identity, hash and evidence columns are
 * written once and refused thereafter by ExecutionAttempt and DurableRecovery,
 * which are the only writers. That enforcement is application-level and stated
 * as such — there is no database trigger, so a hand-written UPDATE would still
 * succeed. The guarantee is "nothing in Engineer888 rewrites this", not
 * "MySQL forbids it".
 *
 * Index names stay short. A 65-character index name broke migrate:fresh across
 * the whole suite on 2026-08-01; MySQL's limit is 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('engineering_execution_attempts')) {
            Schema::create('engineering_execution_attempts', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique('eea_uuid_unique');

                // ── identity, written once at open ────────────────────────
                $table->char('task_uuid', 36);
                $table->string('repository_path', 255);
                $table->string('lock_key', 128)->nullable();
                $table->string('lock_owner', 191)->nullable();
                $table->string('worker', 191)->nullable();
                $table->boolean('dry_run')->default(false);

                // ── bound when IMPLEMENT resolves the candidate ───────────
                // NULL until known; refused once set. Filling a null is not a
                // rewrite, and changing a non-null one is.
                $table->char('candidate_uuid', 36)->nullable();
                $table->unsignedBigInteger('approval_id')->nullable();
                $table->char('approval_fingerprint', 64)->nullable();
                $table->char('pre_image_fingerprint', 64)->nullable();
                $table->longText('targets')->nullable();

                // ── what the repository looked like ───────────────────────
                // head_* anchors the whole tree to a commit. targets_hash_* is
                // this execution's own footprint: sha256 over "path:hash|absent"
                // for exactly the files it touches. A whole-tree hash would be
                // dominated by other engineers' unrelated dirt and would differ
                // on every run while proving nothing about this change.
                $table->char('repository_head_before', 40)->nullable();
                $table->char('repository_head_after', 40)->nullable();
                $table->char('targets_hash_before', 64)->nullable();
                $table->char('targets_hash_after', 64)->nullable();

                // ── outcome, the only genuinely mutable part ──────────────
                $table->string('result', 32)->default('OPEN');
                $table->string('workflow_state', 32)->nullable();
                $table->text('failure')->nullable();
                $table->char('commit_sha', 40)->nullable();

                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['task_uuid', 'result'], 'eea_task_result_idx');
                $table->index('repository_path', 'eea_repo_idx');
                $table->index('result', 'eea_result_idx');
            });
        }

        if (! Schema::hasTable('engineering_recovery_manifests')) {
            Schema::create('engineering_recovery_manifests', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique('erm_uuid_unique');

                $table->char('attempt_uuid', 36)->nullable();
                $table->char('task_uuid', 36);
                $table->char('candidate_uuid', 36)->nullable();
                $table->string('repository_path', 255);

                $table->char('approval_fingerprint', 64)->nullable();
                $table->char('manifest_fingerprint', 64);

                // The whole RecoveryManifest, verbatim, written before the first
                // byte. This is the column that makes a rollback possible after
                // the process that planned it has died.
                $table->longText('manifest');

                // SafeInstaller's decisions, which only exist after the install
                // loop. NULL means the process died before the loop finished —
                // which is recoverable anyway, because each manifest entry
                // carries the intended hash and can be compared against disk.
                $table->longText('decisions')->nullable();

                // CAPTURED -> WRITTEN -> COMPLETE, or -> RECOVERED / RECOVERY_BLOCKED
                $table->string('state', 24)->default('CAPTURED');
                $table->longText('outcome')->nullable();

                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['repository_path', 'state'], 'erm_repo_state_idx');
                $table->index(['task_uuid', 'state'], 'erm_task_state_idx');
                $table->index('attempt_uuid', 'erm_attempt_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_recovery_manifests');
        Schema::dropIfExists('engineering_execution_attempts');
    }
};

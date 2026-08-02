<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineer888 — commit plans and their execution log.
 *
 * TWO TABLES, because they answer different questions.
 *
 * engineering_commit_plans stores a plan as it was GENERATED. Execution must
 * consume a stored plan rather than re-analyse, so that what is approved is what
 * runs. It also stores a fingerprint of every planned file, which is the defence
 * against the real hazard here: another session edits this working tree
 * continuously, and a plan made ten minutes ago may describe files that have
 * since changed underneath it.
 *
 * engineering_executions is the log — one row per attempt on one group, whether
 * it committed or not. Aborted and failed attempts are recorded as deliberately
 * as successful ones; a log that only records successes cannot answer "why is
 * this still uncommitted".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_commit_plans', function (Blueprint $table) {
            $table->id();
            $table->timestamp('generated_at')->index();
            $table->string('repository_path');
            $table->string('branch');
            $table->string('head_commit', 64);

            // The plan verbatim. Execution reads this, never the repository.
            $table->longText('plan');

            // path => sha1(size:mtime:content-hash) at generation time.
            $table->longText('fingerprints');

            $table->unsignedInteger('group_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->string('status', 24)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('engineering_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('engineering_commit_plans')->cascadeOnDelete();

            $table->string('group_key');
            $table->unsignedInteger('group_position');
            $table->string('repository_path');

            // pending · preflight_failed · aborted · committed ·
            // verification_failed · verification_inconclusive · completed
            $table->string('status', 32)->index();
            $table->string('outcome', 255)->nullable();

            $table->longText('files');            // exactly what was staged
            $table->longText('checks')->nullable();     // the verification selected
            $table->longText('check_output')->nullable();
            $table->longText('commit_message')->nullable();
            $table->string('commit_sha', 64)->nullable();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'group_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_executions');
        Schema::dropIfExists('engineering_commit_plans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineer888 — the engineering workflow.
 *
 * PROJECT ABSTRACTION. Engineer888 is the company's engineering department, not
 * a LevelUp Growth utility. The project table exists so that nothing in the
 * workflow has to know which codebase it is working on: LevelUp Growth is
 * Project #001, and Studio888, Hosting, ChefListed and future systems are rows
 * alongside it. Every task belongs to a project; every project names its own
 * repository, ownership manifest and test database.
 *
 * ONE ROW PER STAGE, not a status column. A task that failed at VERIFY after
 * passing nine stages is a different thing from a task that never started, and
 * a single enum cannot tell them apart. The stage table is the audit trail the
 * quality standard requires — purpose, inputs, outputs, evidence and failure,
 * recorded per stage as it happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_projects', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->string('key', 64)->unique();          // 'levelup-growth-platform'
            $table->string('name');
            $table->string('repository_path');
            $table->string('ownership_manifest')->nullable();
            $table->string('test_database')->nullable();
            $table->string('phpunit_config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('engineering_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('engineering_projects')->cascadeOnDelete();

            $table->string('title');
            $table->text('description');
            $table->string('kind', 32)->default('feature');      // bug|feature|refactor|security|...
            $table->string('priority', 16)->default('normal');
            $table->string('requested_by')->nullable();

            $table->json('acceptance_criteria')->nullable();
            $table->json('constraints')->nullable();
            $table->json('modules')->nullable();
            $table->json('change_set')->nullable();              // destination => source label
            $table->json('unknowns')->nullable();

            // received|running|awaiting_approval|blocked|failed|completed
            $table->string('status', 24)->default('received')->index();
            $table->string('current_stage', 32)->nullable();

            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();

            $table->string('session', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('engineering_task_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('engineering_tasks')->cascadeOnDelete();

            $table->string('stage', 32);
            $table->unsignedSmallInteger('position');
            $table->string('status', 16);                        // ok|blocked|failed|skipped
            $table->text('summary')->nullable();

            $table->longText('inputs')->nullable();
            $table->longText('outputs')->nullable();
            $table->longText('evidence')->nullable();
            $table->text('failure')->nullable();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['task_id', 'position'], 'ets_task_position_idx');
        });

        Schema::create('engineering_assets', function (Blueprint $table) {
            $table->id();
            // recipe|scaffold|check|regression-test|deployment-pattern|standard|playbook|incident-pattern|knowledge
            $table->string('kind', 32)->index();
            $table->string('name');
            $table->text('summary');

            // Promotion requires evidence: the task that proved it.
            $table->foreignId('evidence_task_id')->nullable()->constrained('engineering_tasks')->nullOnDelete();
            $table->text('evidence')->nullable();
            $table->string('artifact_path')->nullable();

            $table->string('promoted_by', 64)->nullable();
            $table->timestamps();

            $table->unique(['kind', 'name'], 'ea_kind_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_assets');
        Schema::dropIfExists('engineering_task_stages');
        Schema::dropIfExists('engineering_tasks');
        Schema::dropIfExists('engineering_projects');
    }
};

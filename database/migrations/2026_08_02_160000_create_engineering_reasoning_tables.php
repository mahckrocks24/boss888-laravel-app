<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the Engineering Reasoning Engine.
 *
 * Every proposal is kept, including the rejected ones — those are how a bad
 * provider, a bad prompt or a bad context selection becomes visible as a
 * pattern rather than a thing somebody half-remembers.
 *
 * Index names are short deliberately. A 65-character index name broke
 * migrate:fresh across the whole suite on 2026-08-01; MySQL's limit is 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('project_id');

            $table->string('provider', 64);
            $table->string('model', 128);

            // VALIDATED | REJECTED — see ReasoningOutcome.
            $table->string('status', 24);

            // Identity of the context the provider was given, so a candidate
            // approved against one context cannot be installed against another.
            $table->char('request_fingerprint', 40);
            $table->char('content_fingerprint', 40)->nullable();

            $table->longText('context_manifest')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('violations')->nullable();

            $table->string('confidence', 16)->nullable();
            $table->unsignedSmallInteger('file_count')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('usage')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['task_id', 'status'], 'ec_task_status_idx');
            $table->index('project_id', 'ec_project_idx');
        });

        // Assets become project-scoped. NULL means company-wide: something the
        // company learned, available to every project. A non-null project_id is
        // knowledge that has only ever been proved in one codebase, and offering
        // it elsewhere would be presenting a local habit as a company standard.
        if (! Schema::hasColumn('engineering_assets', 'project_id')) {
            Schema::table('engineering_assets', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('kind');
                $table->index(['project_id', 'kind'], 'ea_project_kind_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_candidates');

        if (Schema::hasColumn('engineering_assets', 'project_id')) {
            Schema::table('engineering_assets', function (Blueprint $table) {
                $table->dropIndex('ea_project_kind_idx');
                $table->dropColumn('project_id');
            });
        }
    }
};

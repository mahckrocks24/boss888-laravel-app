<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable project_id FK to:
 *   - execution_plans  (the canonical task group of a project)
 *   - content_packs    (asset bundles produced under a project)
 *   - automation_events (timeline items traceable back to project)
 *
 * Nullable everywhere — backward-compatible. Legacy rows stay valid;
 * a separate backfill migration sets project_id for legacy execution_plans.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('execution_plans', function (Blueprint $t) {
            $t->unsignedBigInteger('project_id')->nullable()->after('workspace_id');
            $t->index(['workspace_id', 'project_id']);
        });
        Schema::table('content_packs', function (Blueprint $t) {
            $t->unsignedBigInteger('project_id')->nullable()->after('workspace_id');
            $t->index(['workspace_id', 'project_id']);
        });
        Schema::table('automation_events', function (Blueprint $t) {
            $t->unsignedBigInteger('project_id')->nullable()->after('workspace_id');
            $t->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('execution_plans', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'project_id']);
            $t->dropColumn('project_id');
        });
        Schema::table('content_packs', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'project_id']);
            $t->dropColumn('project_id');
        });
        Schema::table('automation_events', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'project_id']);
            $t->dropColumn('project_id');
        });
    }
};
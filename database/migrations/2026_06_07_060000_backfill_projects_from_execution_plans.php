<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill — for each existing execution_plan with project_id IS NULL,
 * create a Project and link it. Preserves history while activating the
 * Project layer cleanly.
 *
 *   plan.title  → project.name
 *   plan.goal   → project.goal
 *   plan status → project status (mapped)
 *   plan.created_by → project.owner_user_id
 *   source_type = 'direct' (no meeting linked for legacy)
 */
return new class extends Migration {
    public function up(): void
    {
        $statusMap = [
            'draft'                  => 'proposed',
            'awaiting_approval'      => 'proposed',
            'needs_revision'         => 'proposed',
            'approved'               => 'active',
            'executing'              => 'active',
            'completed'              => 'completed',
            'completed_with_errors'  => 'completed',
            'degraded'               => 'active',
            'failed'                 => 'cancelled',
            'cancelled'              => 'cancelled',
        ];

        $orphans = DB::table('execution_plans')
            ->whereNull('project_id')
            ->orderBy('id')
            ->get();

        $created = 0;
        foreach ($orphans as $plan) {
            $now = now();
            $projectId = DB::table('projects')->insertGetId([
                'workspace_id'      => $plan->workspace_id,
                'name'              => $plan->title ?: ('Project ' . $plan->id),
                'goal'              => $plan->goal ?: '',
                'description'       => null,
                'status'            => $statusMap[$plan->status] ?? 'proposed',
                'source_type'       => 'direct',
                'owner_user_id'     => $plan->created_by,
                'budget_credits'    => 0,
                'budget_spent'      => 0,
                'started_at'        => $plan->started_at,
                'completed_at'      => $plan->completed_at,
                'created_at'        => $plan->created_at ?? $now,
                'updated_at'        => $plan->updated_at ?? $now,
            ]);

            DB::table('execution_plans')->where('id', $plan->id)->update([
                'project_id' => $projectId,
                'updated_at' => $now,
            ]);
            $created++;
        }

        Log::info('B25 Projects Phase 1 backfill complete', [
            'orphans_found'       => count($orphans),
            'projects_created'    => $created,
        ]);
    }

    public function down(): void
    {
        // Reverse: delete projects that were backfilled (source_type='direct'
        // with no source_meeting_id). Carefully unlinks execution_plans first.
        $backfilled = DB::table('projects')
            ->where('source_type', 'direct')
            ->whereNull('source_meeting_id')
            ->pluck('id');
        if ($backfilled->isNotEmpty()) {
            DB::table('execution_plans')
                ->whereIn('project_id', $backfilled)
                ->update(['project_id' => null]);
            DB::table('projects')->whereIn('id', $backfilled)->delete();
        }
    }
};
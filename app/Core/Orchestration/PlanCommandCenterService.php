<?php

namespace App\Core\Orchestration;

use Illuminate\Support\Facades\DB;

/**
 * PlanCommandCenterService — aggregates plan state into one response
 * shape suitable for direct UI consumption.
 *
 * One canonical endpoint to avoid the UI making 5 separate API calls
 * to stitch together a plan dashboard.
 */
class PlanCommandCenterService
{
    /**
     * Return the full command-center payload for a plan. Cross-tenant safe:
     * returns null if the plan doesn't belong to $wsId.
     */
    public function get(int $wsId, int $planId): ?array
    {
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->where('workspace_id', $wsId)
            ->first();
        if (!$plan) return null;

        $strategy = json_decode($plan->strategy_json ?? '{}', true) ?: [];
        $scheduleSpec = $strategy['schedule'] ?? null;

        $planTasks = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->orderBy('step_order')
            ->get();

        return [
            'plan'           => $this->buildPlanSection($plan, $scheduleSpec),
            'timeline'       => $this->buildTimelineSection($planTasks),
            'tasks'          => $this->buildTaskStats($planTasks),
            'publish_queue'  => $this->buildPublishQueueSection($wsId, $planId),
            'credits'        => $this->buildCreditsSection($plan, $planTasks),
        ];
    }

    /** Lighter-weight check used by health probes / listings. */
    public function summary(int $wsId, int $planId): ?array
    {
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->where('workspace_id', $wsId)
            ->first(['id', 'title', 'status', 'total_tasks', 'completed_tasks', 'failed_tasks', 'created_at']);
        if (!$plan) return null;
        $queueQueued = DB::table('plan_publish_queue')
            ->where('plan_id', $planId)->where('workspace_id', $wsId)
            ->where('status', 'queued')->count();
        return [
            'id'              => $plan->id,
            'title'           => $plan->title,
            'status'          => $plan->status,
            'total_tasks'     => (int) $plan->total_tasks,
            'completed_tasks' => (int) $plan->completed_tasks,
            'failed_tasks'    => (int) $plan->failed_tasks,
            'queued_for_review' => $queueQueued,
            'created_at'      => $plan->created_at,
        ];
    }

    // ─── Private builders ────────────────────────────────────────────

    private function buildPlanSection(object $plan, ?array $scheduleSpec): array
    {
        return [
            'id'             => $plan->id,
            'title'          => $plan->title,
            'goal'           => $plan->goal,
            'status'         => $plan->status,
            'total_tasks'    => (int) $plan->total_tasks,
            'completed_tasks'=> (int) $plan->completed_tasks,
            'failed_tasks'   => (int) $plan->failed_tasks,
            'version'        => (int) $plan->version,
            'requires_approval' => (bool) $plan->requires_approval,
            'approved_at'    => $plan->approved_at,
            'started_at'     => $plan->started_at,
            'completed_at'   => $plan->completed_at,
            'created_at'     => $plan->created_at,
            'schedule_spec'  => $scheduleSpec,
            'agents_required'=> json_decode($plan->agents_required_json ?? '[]', true) ?: [],
        ];
    }

    private function buildTimelineSection($planTasks): array
    {
        $scheduled = [];
        $unscheduled = [];
        $byDate = [];

        foreach ($planTasks as $t) {
            /* b19-phase2-pcc */
            $arr = [
                'plan_task_id'         => $t->id,
                'engine'               => $t->engine,
                'action'               => $t->action,
                'assigned_agent'       => $t->assigned_agent,
                'scheduled_for'        => $t->scheduled_for,
                'automation_event_id'  => $t->automation_event_id ?? null,
                'status'               => $t->status,
                'step_order'           => $t->step_order,
                'credits_used'         => (int) $t->credits_used,
            ];
            if ($t->scheduled_for) {
                $scheduled[] = $arr;
                $date = substr((string) $t->scheduled_for, 0, 10);
                $byDate[$date][] = $arr;
            } else {
                $unscheduled[] = $arr;
            }
        }
        ksort($byDate);

        return [
            'scheduled_count'   => count($scheduled),
            'unscheduled_count' => count($unscheduled),
            'tasks_by_date'     => $byDate,
            'unscheduled'       => $unscheduled,
        ];
    }

    private function buildTaskStats($planTasks): array
    {
        $byStatus = [];
        $byEngine = [];
        foreach ($planTasks as $t) {
            $byStatus[$t->status] = ($byStatus[$t->status] ?? 0) + 1;
            $byEngine[$t->engine] = ($byEngine[$t->engine] ?? 0) + 1;
        }
        return [
            'total'      => count($planTasks),
            'by_status'  => $byStatus,
            'by_engine'  => $byEngine,
        ];
    }

    private function buildPublishQueueSection(int $wsId, int $planId): array
    {
        $rows = DB::table('plan_publish_queue')
            ->where('workspace_id', $wsId)
            ->where('plan_id', $planId)
            ->orderBy('created_at')
            ->get();
        $byStatus = ['queued' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0];
        $items = [];
        foreach ($rows as $r) {
            $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + 1;
            $items[] = [
                'id'             => $r->id,
                'engine'         => $r->engine,
                'action'         => $r->action,
                'entity_type'    => $r->entity_type,
                'entity_id'      => $r->entity_id,
                'status'         => $r->status,
                'batch_id'       => $r->batch_id,
                'approval_id'    => $r->approval_id,
                'decided_by'     => $r->decision_by,
                'decided_at'     => $r->decided_at,
                'created_at'     => $r->created_at,
                'preview'        => json_decode($r->preview_json ?? '{}', true) ?: [],
            ];
        }
        return [
            'total'    => count($rows),
            'by_status'=> $byStatus,
            'items'    => $items,
        ];
    }

    private function buildCreditsSection(object $plan, $planTasks): array
    {
        $strategy = json_decode($plan->strategy_json ?? '{}', true) ?: [];
        $estimated = (int) ($strategy['cost_breakdown']['total'] ?? 0);
        $used = 0;
        foreach ($planTasks as $t) {
            $used += (int) $t->credits_used;
        }
        return [
            'estimated' => $estimated,
            'used'      => $used,
            'remaining' => max(0, $estimated - $used),
        ];
    }
}
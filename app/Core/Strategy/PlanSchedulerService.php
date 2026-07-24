<?php

namespace App\Core\Strategy;

/* b19-phase2 */
use App\Engines\Calendar\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PlanSchedulerService — distributes plan_tasks across time per a schedule
 * spec, and plots them on the calendar so the user sees the timeline BEFORE
 * approving the plan.
 *
 * Per Shukran's design: "Sarah should create 1st the calendar item for the
 * upcoming days in the specific timeframe mentioned, and then create a task
 * group for all agents involved with the individual tasks, single approval
 * needed."
 *
 * This service handles step 1 of that flow — once a Plan with plan_tasks
 * exists, plotSchedule() assigns each plan_task a scheduled_for and creates
 * a linked calendar_event. The user-visible calendar then shows the campaign
 * laid out across days.
 *
 * Distribution algorithm:
 *   - Walk plan_tasks in step_order
 *   - Place items_per_day tasks per calendar day starting from start_date
 *   - Skip weekends if skip_weekends=true
 *   - All tasks on a given day share the same start time (configurable)
 *   - Stop when all tasks placed or end_date reached
 *
 * Idempotent: re-running plotSchedule on the same plan clears previous
 * scheduled_for + calendar_event_id values and rebuilds.
 */
class PlanSchedulerService
{
    public function __construct(
        private readonly \App\Core\Strategy\AutomationCalendarService $automationCal
    ) {}

    /**
     * Plot a schedule across a plan's tasks. Creates calendar events for each.
     *
     * @param int   $wsId
     * @param int   $planId
     * @param array $spec  schedule spec — see class docblock
     * @return array  [success, scheduled, total_tasks, calendar_events_created, schedule_preview]
     */
    public function plotSchedule(int $wsId, int $planId, array $spec): array
    {
        // Validate plan
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->where('workspace_id', $wsId)
            ->first();
        if (!$plan) {
            return ['success' => false, 'error' => 'plan not found'];
        }

        // Validate + normalize spec
        $startDate = $spec['start_date'] ?? null;
        if (!$startDate) {
            return ['success' => false, 'error' => 'start_date is required'];
        }
        try {
            $start = Carbon::parse($startDate);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'invalid start_date'];
        }

        $itemsPerDay     = max(1, (int) ($spec['items_per_day'] ?? 1));
        $days            = isset($spec['days']) ? max(1, (int) $spec['days']) : null;
        $endDateRaw      = $spec['end_date'] ?? null;
        $timeOfDay       = (string) ($spec['time_of_day'] ?? '09:00');
        $durationMinutes = max(15, (int) ($spec['duration_minutes'] ?? 60));
        $skipWeekends    = (bool) ($spec['skip_weekends'] ?? false);
        $eventCategory   = (string) ($spec['category'] ?? 'campaign');
        $eventColor      = (string) ($spec['color'] ?? '#5B5BD6');

        // Resolve end date
        if ($endDateRaw) {
            $end = Carbon::parse($endDateRaw);
        } elseif ($days) {
            $end = $start->copy()->addDays($days - 1);
        } else {
            return ['success' => false, 'error' => 'either days or end_date is required'];
        }
        // b15 — the window must close at END of the final day. The cursor now
        // carries a time-of-day (needed for interval mode), so comparing it
        // against a midnight boundary silently dropped the whole last day —
        // and, for an intra-day cadence starting later than 00:00, every slot.
        $end = $end->endOfDay();

        // Load plan_tasks in step_order
        $tasks = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->orderBy('step_order')
            ->get(['id', 'engine', 'action', 'assigned_agent', 'params_json']);

        if ($tasks->isEmpty()) {
            return ['success' => false, 'error' => 'plan has no plan_tasks to schedule'];
        }

        // Clear any prior schedule on this plan (idempotent)
        $priorEvents = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->whereNotNull('automation_event_id')
            ->pluck('automation_event_id')
            ->toArray();
        if (!empty($priorEvents)) {
            $this->automationCal->deleteEvents($priorEvents);
            DB::table('plan_tasks')
                ->where('plan_id', $planId)
                ->update(['scheduled_for' => null, 'automation_event_id' => null]);
        }

        // b15 (2026-07-24) — INTERVAL MODE. When the spec carries
        // interval_minutes (e.g. "every 5 minutes"), the cursor advances by
        // that interval and can cross day boundaries, instead of advancing a
        // whole day and placing every task at the same time_of_day. Day mode
        // is byte-for-byte unchanged: the cursor simply steps +1 day and is
        // re-pinned to time_of_day each step.
        $intervalMinutes = isset($spec['interval_minutes'])
            ? max(1, (int) $spec['interval_minutes']) : null;
        $itemsPerSlot = $intervalMinutes !== null
            ? max(1, (int) ($spec['items_per_slot'] ?? 1))
            : $itemsPerDay;
        // Runaway guard — an open-ended fast cadence must never plot forever.
        $maxOccurrences = max(1, (int) ($spec['max_occurrences'] ?? 1000));
        $occurrences = 0;

        // Distribute tasks across the window
        [$slotH, $slotM] = $this->parseTimeOfDay($timeOfDay);
        $cursor = $start->copy()->setTime($slotH, $slotM, 0);
        $tasksRemaining = $tasks->all();
        $taskIdx = 0;
        $totalTasks = count($tasksRemaining);
        $scheduled = 0;
        $scheduledPreview = [];

        while ($taskIdx < $totalTasks && $cursor->lte($end) && $occurrences < $maxOccurrences) {
            // Skip weekends if requested
            if ($skipWeekends && $cursor->isWeekend()) {
                $cursor->addDay()->setTime($slotH, $slotM, 0);
                continue;
            }

            // Place up to items-per-slot tasks at this slot
            for ($i = 0; $i < $itemsPerSlot && $taskIdx < $totalTasks; $i++) {
                $task = $tasksRemaining[$taskIdx];

                // Cursor already holds the exact slot datetime (day mode pins
                // it to time_of_day; interval mode walks it forward).
                $startsAt = $cursor->copy();
                $endsAt   = $startsAt->copy()->addMinutes($durationMinutes);

                // Create calendar event
                $params = json_decode($task->params_json ?? '{}', true) ?: [];
                $title = $this->buildEventTitle($task, $params, $i + 1, $itemsPerSlot);

                try {
                    $eventId = $this->automationCal->createEvent($wsId, [
                        'title'          => $title,
                        'description'    => "Plan #$planId task #{$task->id} — {$task->engine}/{$task->action}",
                        'category'       => 'plan_task',
                        'engine'         => $task->engine,
                        'reference_id'   => $task->id,
                        'reference_type' => 'plan_task',
                        'color'          => $eventColor,
                        'starts_at'      => $startsAt->toDateTimeString(),
                        'ends_at'        => $endsAt->toDateTimeString(),
                        'all_day'        => false,
                        'status'         => 'scheduled',
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[PlanScheduler] automation event creation failed', [
                        'plan_id' => $planId, 'task_id' => $task->id, 'err' => $e->getMessage(),
                    ]);
                    $eventId = null;
                }

                // Update plan_task
                DB::table('plan_tasks')->where('id', $task->id)->update([
                    'scheduled_for'        => $startsAt->toDateTimeString(),
                    'automation_event_id'  => $eventId,
                    'updated_at'           => now(),
                ]);

                $scheduled++;
                $scheduledPreview[] = [
                    'plan_task_id'         => $task->id,
                    'engine'               => $task->engine,
                    'action'               => $task->action,
                    'scheduled_for'        => $startsAt->toDateTimeString(),
                    'automation_event_id'  => $eventId,
                ];
                $taskIdx++;
            }

            $occurrences++;
            if ($intervalMinutes !== null) {
                $cursor->addMinutes($intervalMinutes);
            } else {
                $cursor->addDay()->setTime($slotH, $slotM, 0);
            }
        }

        // Update execution_plans.strategy_json with the applied schedule spec
        $existingStrategy = json_decode($plan->strategy_json ?? '{}', true) ?: [];
        $existingStrategy['schedule'] = array_merge($spec, [
            '_applied_at'        => now()->toDateTimeString(),
            '_total_tasks'       => $totalTasks,
            '_scheduled_count'   => $scheduled,
            '_unscheduled_count' => $totalTasks - $scheduled,
        ]);
        DB::table('execution_plans')->where('id', $planId)->update([
            'strategy_json' => json_encode($existingStrategy),
            'updated_at'    => now(),
        ]);

        return [
            'success'                  => true,
            'plan_id'                  => $planId,
            'total_tasks'              => $totalTasks,
            'scheduled'                => $scheduled,
            'unscheduled'              => $totalTasks - $scheduled,
            'automation_events_created' => count(array_filter(array_column($scheduledPreview, 'automation_event_id'))),
            'schedule_preview'         => $scheduledPreview,
            'window'                   => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
                'days'  => (int) ($start->diffInDays($end) + 1),
            ],
            // b15 — make the applied cadence + any guardrail clamps explicit so
            // the caller can tell the user exactly what was scheduled and why.
            'cadence' => [
                'mode'             => $intervalMinutes !== null ? 'interval' : 'daily',
                'interval_minutes' => $intervalMinutes,
                'items_per_slot'   => $itemsPerSlot,
                'occurrences'      => $occurrences,
                'occurrence_cap'   => $maxOccurrences,
                'capped'           => $occurrences >= $maxOccurrences,
                'adjustments'      => $spec['adjustments'] ?? [],
            ],
        ];
    }

    /**
     * Return the schedule preview for a plan (for UI rendering at approval time).
     */
    public function getSchedule(int $wsId, int $planId): array
    {
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->where('workspace_id', $wsId)
            ->first();
        if (!$plan) return ['success' => false, 'error' => 'plan not found'];

        $tasks = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->whereNotNull('scheduled_for')
            ->orderBy('scheduled_for')
            ->orderBy('step_order')
            ->get(['id', 'engine', 'action', 'assigned_agent', 'scheduled_for', 'automation_event_id', 'status'])
            ->toArray();

        // Group by date for a calendar-friendly view
        $byDate = [];
        foreach ($tasks as $t) {
            $d = substr((string) $t->scheduled_for, 0, 10);
            $byDate[$d][] = (array) $t;
        }

        $strategy = json_decode($plan->strategy_json ?? '{}', true) ?: [];
        return [
            'success'      => true,
            'plan_id'      => $planId,
            'schedule_spec'=> $strategy['schedule'] ?? null,
            'tasks_by_date'=> $byDate,
            'total_scheduled' => count($tasks),
        ];
    }

    /**
     * Clear a plan's schedule (deletes calendar events, nulls scheduled_for
     * + calendar_event_id on plan_tasks). Used when the user wants to re-plot.
     */
    public function clearSchedule(int $wsId, int $planId): array
    {
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->where('workspace_id', $wsId)
            ->first();
        if (!$plan) return ['success' => false, 'error' => 'plan not found'];

        $eventIds = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->whereNotNull('automation_event_id')
            ->pluck('automation_event_id')
            ->toArray();
        if (!empty($eventIds)) {
            $this->automationCal->deleteEvents($eventIds);
        }
        $cleared = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->whereNotNull('scheduled_for')
            ->update(['scheduled_for' => null, 'automation_event_id' => null]);
        return ['success' => true, 'plan_id' => $planId, 'cleared_count' => $cleared];
    }

    // ─── Private ──────────────────────────────────────────────────────

    private function buildEventTitle(object $task, array $params, int $slotIndex, int $itemsPerDay): string
    {
        // Human-friendly title for the calendar
        $topic = $params['topic'] ?? $params['title'] ?? null;
        $base = match ($task->engine) {
            'write'     => 'Article',
            'social'    => 'Social post',
            'marketing' => 'Email',
            'studio'    => 'Design',
            'creative'  => 'Image',
            default     => ucfirst($task->engine),
        };
        if ($topic) $base .= ": $topic";
        if ($itemsPerDay > 1) $base .= " ($slotIndex/$itemsPerDay)";
        return $base;
    }

    private function parseTimeOfDay(string $time): array
    {
        // Accept "HH:MM" or "HH:MM:SS"
        $parts = explode(':', $time);
        $h = max(0, min(23, (int) ($parts[0] ?? 9)));
        $m = max(0, min(59, (int) ($parts[1] ?? 0)));
        return [$h, $m];
    }
}
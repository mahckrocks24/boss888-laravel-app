<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;

/**
 * AutomationCalendarService — writes / reads automation_events.
 *
 * Mirrors the CalendarService surface so PlanSchedulerService + EES
 * crossEngineSync can be repointed in Phase 2 with minimal code churn.
 *
 * Semantic distinction (locked):
 *   - CalendarService writes to calendar_events  → USER's personal calendar
 *   - AutomationCalendarService writes to automation_events → AGENT/SYSTEM timeline
 *
 * The two never mix at the storage layer. Aggregation happens at the read
 * layer when building the Main Automation Calendar (Phase 4).
 */
class AutomationCalendarService
{
    /**
     * Create an automation event. Mirrors CalendarService::createEvent shape
     * so callers can swap services without changing payload structure.
     *
     * Expected keys:
     *   title, starts_at  (required)
     *   description, category, engine, reference_id, reference_type,
     *   color, ends_at, all_day, recurrence, recurrence_config_json, status
     */
    public function createEvent(int $wsId, array $data): int
    {
        return DB::table('automation_events')->insertGetId([
            'workspace_id'           => $wsId,
            'title'                  => $data['title'],
            'description'            => $data['description'] ?? null,
            'category'               => $data['category'] ?? 'general',
            'engine'                 => $data['engine'] ?? null,
            'reference_id'           => $data['reference_id'] ?? null,
            'reference_type'         => $data['reference_type'] ?? null,
            'color'                  => $data['color'] ?? null,
            'starts_at'              => $data['starts_at'],
            'ends_at'                => $data['ends_at'] ?? null,
            'all_day'                => $data['all_day'] ?? false,
            'recurrence'             => $data['recurrence'] ?? null,
            'recurrence_config_json' => isset($data['recurrence_config_json'])
                ? (is_string($data['recurrence_config_json'])
                    ? $data['recurrence_config_json']
                    : json_encode($data['recurrence_config_json']))
                : null,
            'status'                 => $data['status'] ?? 'scheduled',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * Update an automation event. Only writable fields whitelisted; workspace_id
     * cannot be changed (multi-tenancy safety).
     */
    public function updateEvent(int $eventId, array $data): void
    {
        $allowed = [
            'title', 'description', 'category', 'engine', 'reference_id',
            'reference_type', 'color', 'starts_at', 'ends_at', 'all_day',
            'recurrence', 'recurrence_config_json', 'status',
        ];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($update['recurrence_config_json']) && !is_string($update['recurrence_config_json'])) {
            $update['recurrence_config_json'] = json_encode($update['recurrence_config_json']);
        }
        if (empty($update)) return;
        $update['updated_at'] = now();
        DB::table('automation_events')->where('id', $eventId)->update($update);
    }

    public function deleteEvent(int $eventId): void
    {
        DB::table('automation_events')->where('id', $eventId)->delete();
    }

    /**
     * Bulk delete by IDs. Used by PlanSchedulerService when re-plotting
     * (it wipes prior automation events before rebuilding).
     */
    public function deleteEvents(array $eventIds): int
    {
        if (empty($eventIds)) return 0;
        return DB::table('automation_events')->whereIn('id', $eventIds)->delete();
    }

    /**
     * List automation events in a window. Optional engine + category filters
     * for per-engine sub-calendars (Phase 4).
     */
    public function listEvents(
        int $wsId,
        ?string $from = null,
        ?string $to = null,
        ?string $engine = null,
        ?string $category = null,
        ?string $status = null
    ): array {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to   = $to   ?: now()->endOfMonth()->toDateString();

        $q = DB::table('automation_events')
            ->where('workspace_id', $wsId)
            ->whereBetween('starts_at', [$from, $to]);

        if ($engine)   $q->where('engine', $engine);
        if ($category) $q->where('category', $category);
        if ($status)   $q->where('status', $status);

        return $q->orderBy('starts_at')->get()->map(fn($r) => (array) $r)->toArray();
    }

    /**
     * Look up an event by its source entity. Used when an entity (e.g. a
     * scheduled social post) gets its scheduled_at changed and we want to
     * sync the matching automation event.
     */
    public function findByReference(int $wsId, string $referenceType, int $referenceId): ?array
    {
        $row = DB::table('automation_events')
            ->where('workspace_id', $wsId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->first();
        return $row ? (array) $row : null;
    }

    /**
     * Compute a status summary for a workspace — useful for Sarah's morning
     * brief ("you have 4 events scheduled today, 2 running, 1 completed").
     */
    /* b20-phase4 */

    /**
     * Main Automation Calendar — aggregated agent-timeline view.
     *
     * Returns events from automation_events plus derived recurring routines
     * (Sarah's morning brief, weekly review, monthly strategy). Grouped by
     * date for calendar UI rendering. Includes per-engine + per-status filters
     * for the sidebar drill-down.
     *
     * Never includes user calendar events (those live in calendar_events
     * and are served by /api/calendar/events).
     *
     * @param int    $wsId
     * @param ?string $from  ISO date (default: start of current month)
     * @param ?string $to    ISO date (default: end of current month)
     * @param array  $filters  ['engine' => str?, 'category' => str?, 'status' => str?]
     * @return array  [tasks_by_date, recurring_routines, totals, window]
     */
    public function mainCalendar(
        int $wsId,
        ?string $from = null,
        ?string $to = null,
        array $filters = []
    ): array {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to   = $to   ?: now()->endOfMonth()->toDateString();

        $events = $this->listEvents(
            $wsId,
            $from,
            $to,
            $filters['engine']   ?? null,
            $filters['category'] ?? null,
            $filters['status']   ?? null
        );

        // Group events by date for calendar rendering
        $byDate = [];
        foreach ($events as $e) {
            $date = substr((string) $e['starts_at'], 0, 10);
            $byDate[$date][] = $e;
        }
        ksort($byDate);

        // Tally counts for the dashboard widget
        $byEngine = [];
        $byStatus = [];
        $byCategory = [];
        foreach ($events as $e) {
            if (!empty($e['engine']))   $byEngine[$e['engine']]     = ($byEngine[$e['engine']]     ?? 0) + 1;
            if (!empty($e['status']))   $byStatus[$e['status']]     = ($byStatus[$e['status']]     ?? 0) + 1;
            if (!empty($e['category'])) $byCategory[$e['category']] = ($byCategory[$e['category']] ?? 0) + 1;
        }

        return [
            'events_by_date'     => $byDate,
            'total_events'       => count($events),
            'by_engine'          => $byEngine,
            'by_status'          => $byStatus,
            'by_category'        => $byCategory,
            'recurring_routines' => $this->recurringRoutines($wsId),
            'window'             => [
                'from' => $from,
                'to'   => $to,
            ],
        ];
    }

    /**
     * Per-engine calendar — convenience view filtered to one engine.
     * Used by /api/email-marketing/calendar (engine=marketing) and could
     * be reused by other per-engine surfaces in future.
     */
    public function perEngineCalendar(int $wsId, string $engine, ?string $from = null, ?string $to = null): array
    {
        return $this->mainCalendar($wsId, $from, $to, ['engine' => $engine]);
    }

    /**
     * Sarah's recurring routines — derived events showing her scheduled
     * cron triggers. These aren't stored as rows; they're computed from
     * the known cron schedule (defined in bootstrap/app.php withSchedule).
     *
     * Each entry has a `next_fires_at` so the calendar UI can show when
     * the next run is due in the current workspace timezone.
     */
    public function recurringRoutines(int $wsId): array
    {
        // Workspace timezone for per-tenant cron firing
        $ws = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first(['timezone']);
        $tz = $ws->timezone ?? 'UTC';

        $now = now($tz);
        $tomorrow8 = $now->copy()->setTime(8, 0, 0);
        if ($tomorrow8->lte($now)) $tomorrow8->addDay();

        $nextMonday9 = $now->copy()->next(\Carbon\Carbon::MONDAY)->setTime(9, 0, 0);

        $firstOfNextMonth10 = $now->copy()->addMonthNoOverflow()->startOfMonth()->setTime(10, 0, 0);
        // If we're before 10am on the 1st of current month, the next is THIS month
        if ($now->day === 1 && $now->hour < 10) {
            $firstOfNextMonth10 = $now->copy()->startOfMonth()->setTime(10, 0, 0);
        }

        return [
            [
                'key'           => 'sarah_morning_brief',
                'title'         => "Sarah's morning brief",
                'description'   => 'Daily proactive scan + posted brief at 08:00 workspace-local',
                'cadence'       => 'daily',
                'engine'        => 'sarah',
                'category'      => 'sarah_cron',
                'next_fires_at' => $tomorrow8->toIso8601String(),
            ],
            [
                'key'           => 'sarah_weekly_review',
                'title'         => "Sarah's weekly review",
                'description'   => 'Past 7-day retrospective + pivot proposals, Monday 09:00',
                'cadence'       => 'weekly',
                'engine'        => 'sarah',
                'category'      => 'sarah_cron',
                'next_fires_at' => $nextMonday9->toIso8601String(),
            ],
            [
                'key'           => 'sarah_monthly_strategy',
                'title'         => "Sarah's monthly strategy meeting",
                'description'   => 'Multi-agent strategy meeting, 1st of month 10:00',
                'cadence'       => 'monthly',
                'engine'        => 'sarah',
                'category'      => 'sarah_cron',
                'next_fires_at' => $firstOfNextMonth10->toIso8601String(),
            ],
        ];
    }
    public function statusSummary(int $wsId, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfDay();
        $to   = $to   ?: now()->endOfDay();

        $counts = DB::table('automation_events')
            ->where('workspace_id', $wsId)
            ->whereBetween('starts_at', [$from, $to])
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        return [
            'total'     => array_sum($counts),
            'scheduled' => (int) ($counts['scheduled'] ?? 0),
            'running'   => (int) ($counts['running']   ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'failed'    => (int) ($counts['failed']    ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
        ];
    }
}
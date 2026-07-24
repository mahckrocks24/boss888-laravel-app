<?php

namespace App\Engines\Calendar\Services;

use Illuminate\Support\Facades\DB;

class CalendarService
{
    public function createEvent(int $wsId, array $data): int
    {
        $id = DB::table('calendar_events')->insertGetId([
            'workspace_id' => $wsId,
            'title' => $data['title'] ?? 'Untitled Event',
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? 'general',
            'engine' => $data['engine'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'color' => $data['color'] ?? $this->categoryColor($data['category'] ?? 'general'),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'all_day' => $data['all_day'] ?? false,
            'recurrence' => $data['recurrence'] ?? null,
            'recurrence_config_json' => json_encode($data['recurrence_config'] ?? []),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function updateEvent(int $eventId, array $data, ?int $wsId = null): void
    {
        $update = array_intersect_key($data, array_flip([
            'title', 'description', 'category', 'color', 'starts_at', 'ends_at', 'all_day', 'recurrence',
        ]));
        if (isset($data['recurrence_config'])) $update['recurrence_config_json'] = json_encode($data['recurrence_config']);
        $update['updated_at'] = now();
        $n = DB::table('calendar_events')->where('id', $eventId)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update($update);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Event not found');
    }

    public function deleteEvent(int $eventId, ?int $wsId = null): void
    {
        $n = DB::table('calendar_events')->where('id', $eventId)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->delete();
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Event not found');
    }

    /* b21-phase5 */
    public function getEvents(
        int $wsId,
        ?string $from,
        ?string $to,
        ?string $category = null,
        ?int $userId = null
    ): array {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to   = $to   ?: now()->endOfMonth()->toDateString();

        $q = DB::table('calendar_events')->where('workspace_id', $wsId)
            ->where(fn($q) => $q->whereBetween('starts_at', [$from, $to])
                ->orWhere(fn($q2) => $q2->whereNotNull('recurrence')));

        if ($category) $q->where('category', $category);

        // W6 launch scope: Publisher and article-share rows are internal. They must
        // never reach a customer calendar. Null-safe: user-created rows have no
        // reference_type and must survive the filter.
        $q->where(function ($sub) {
            $sub->whereNull('reference_type')
                ->orWhereNotIn('reference_type', ['publisher_post', 'article_share']);
        });

        $events = $q->orderBy('starts_at')->get()->toArray();

        // Stamp each calendar_events row with source='calendar' + attendance='required'
        // so the UI can render a consistent shape across both sources.
        foreach ($events as $e) {
            if (is_object($e)) {
                $e->source     = 'calendar';
                $e->attendance = 'required';
            } else {
                $events_arr = $e; // shouldn't hit but defensive
            }
        }

        // Expand recurring calendar events within range
        $expanded = $this->expandRecurring($events, $from, $to);

        // ── Strategy Room visibility (B21 Phase 5) ─────────────────────
        // When userId is supplied, surface meetings where the user is a
        // participant. Meeting sources are tagged attendance='optional'
        // because the user said "invited but not required." If category
        // filter is set, we apply it to meetings too (category='meeting').
        if ($userId !== null && ($category === null || $category === 'meeting')) {
            $meetings = DB::table('meetings')
                ->where('meetings.workspace_id', $wsId)
                ->whereBetween('meetings.created_at', [$from, $to])
                ->whereExists(function ($q) use ($userId) {
                    $q->select(DB::raw(1))
                      ->from('meeting_participants')
                      ->whereColumn('meeting_participants.meeting_id', 'meetings.id')
                      ->where('meeting_participants.participant_type', 'user')
                      ->where('meeting_participants.participant_id', $userId);
                })
                ->select([
                    'meetings.id',
                    'meetings.workspace_id',
                    'meetings.title',
                    'meetings.type',
                    'meetings.status',
                    'meetings.created_at',
                ])
                ->orderBy('meetings.created_at')
                ->get();

            foreach ($meetings as $m) {
                // Adapt the meeting row to the calendar event shape so the
                // UI can render uniformly. starts_at = meetings.created_at.
                $expanded[] = (object) [
                    'id'             => 'meeting:' . $m->id,   // namespaced so it doesn't collide with calendar_events ids
                    'workspace_id'   => $m->workspace_id,
                    'title'          => $m->title,
                    'description'    => 'Strategy Room — multi-agent meeting (optional)',
                    'category'       => 'meeting',
                    'engine'         => 'sarah',
                    'reference_id'   => $m->id,
                    'reference_type' => 'meeting',
                    'color'          => '#A855F7',
                    'starts_at'      => $m->created_at,
                    'ends_at'        => null,
                    'all_day'        => 0,
                    'recurrence'     => null,
                    'recurrence_config_json' => null,
                    'created_at'     => $m->created_at,
                    'updated_at'     => $m->created_at,
                    'source'         => 'meeting',
                    'attendance'     => 'optional',
                    'meeting_status' => $m->status,
                ];
            }

            // Re-sort the merged set by starts_at for chronological order
            usort($expanded, function ($a, $b) {
                $aTime = is_object($a) ? $a->starts_at : ($a['starts_at'] ?? '');
                $bTime = is_object($b) ? $b->starts_at : ($b['starts_at'] ?? '');
                return strcmp((string) $aTime, (string) $bTime);
            });
        }

        return $expanded;
    }

    /* b19-phase2-removed-syncFromEngine */
    // CalendarService::syncFromEngine — REMOVED in B19 Phase 2.
    // Was dead code (zero internal callers). Cross-engine sync logic for
    // marketing + social scheduled items moved to EES::crossEngineSync
    // routing to AutomationCalendarService (automation_events table)
    // since those events belong to the agent timeline, not user calendar.

    /* b22-dashboard-meetings */
    public function getDashboard(int $wsId, ?int $userId = null): array
    {
        $today = now()->startOfDay();
        $weekEnd = now()->endOfWeek();
        $events = DB::table('calendar_events')->where('workspace_id', $wsId)
            ->where(function ($sub) {   // W6 launch scope — see getEvents()
                $sub->whereNull('reference_type')
                    ->orWhereNotIn('reference_type', ['publisher_post', 'article_share']);
            });

        $todayList    = (clone $events)->whereDate('starts_at', $today)->orderBy('starts_at')->get()->toArray();
        $thisWeekList = (clone $events)->whereBetween('starts_at', [$today, $weekEnd])->orderBy('starts_at')->get()->toArray();

        // Phase 5 parity: surface Strategy Room invites for this user
        if ($userId !== null) {
            $mq = DB::table('meetings')
                ->where('meetings.workspace_id', $wsId)
                ->whereExists(function ($q) use ($userId) {
                    $q->select(DB::raw(1))
                      ->from('meeting_participants')
                      ->whereColumn('meeting_participants.meeting_id', 'meetings.id')
                      ->where('meeting_participants.participant_type', 'user')
                      ->where('meeting_participants.participant_id', $userId);
                });

            $todayMeetings    = (clone $mq)->whereDate('created_at', $today)->get();
            $thisWeekMeetings = (clone $mq)->whereBetween('created_at', [$today, $weekEnd])->get();

            foreach ($todayMeetings as $m) {
                $todayList[] = (object) [
                    'id'         => 'meeting:' . $m->id,
                    'title'      => $m->title,
                    'category'   => 'meeting',
                    'engine'     => 'sarah',
                    'starts_at'  => $m->created_at,
                    'source'     => 'meeting',
                    'attendance' => 'optional',
                ];
            }
            foreach ($thisWeekMeetings as $m) {
                $thisWeekList[] = (object) [
                    'id'         => 'meeting:' . $m->id,
                    'title'      => $m->title,
                    'category'   => 'meeting',
                    'engine'     => 'sarah',
                    'starts_at'  => $m->created_at,
                    'source'     => 'meeting',
                    'attendance' => 'optional',
                ];
            }
        }

        return [
            'today'        => $todayList,
            'this_week'    => $thisWeekList,
            'total_events' => (clone $events)->count(),
            'by_category'  => (clone $events)->selectRaw('category, COUNT(*) as count')->groupBy('category')->get()->toArray(),
        ];
    }

    private function expandRecurring(array $events, string $from, string $to): array
    {
        $result = [];
        foreach ($events as $event) {
            if (empty($event->recurrence)) {
                $result[] = $event;
                continue;
            }
            // Generate occurrences within range
            $start = new \DateTime($event->starts_at);
            $end = new \DateTime($to);
            $interval = match ($event->recurrence) {
                'daily' => new \DateInterval('P1D'), 'weekly' => new \DateInterval('P1W'),
                'monthly' => new \DateInterval('P1M'), 'yearly' => new \DateInterval('P1Y'),
                default => null,
            };
            if (!$interval) { $result[] = $event; continue; }
            $current = clone $start;
            while ($current <= $end) {
                if ($current->format('Y-m-d') >= $from) {
                    $occurrence = clone $event;
                    $occurrence->starts_at = $current->format('Y-m-d H:i:s');
                    $occurrence->is_recurring_instance = true;
                    $result[] = $occurrence;
                }
                $current->add($interval);
            }
        }
        return $result;
    }

    private function categoryColor(string $category): string
    {
        return match ($category) {
            'meeting' => '#6C5CE7', 'task_deadline' => '#F87171',
            'content_publish' => '#A78BFA', default => '#3B82F6',
        };
    }
}

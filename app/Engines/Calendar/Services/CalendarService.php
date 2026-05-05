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

    public function updateEvent(int $eventId, array $data): void
    {
        $update = array_intersect_key($data, array_flip([
            'title', 'description', 'category', 'color', 'starts_at', 'ends_at', 'all_day', 'recurrence',
        ]));
        if (isset($data['recurrence_config'])) $update['recurrence_config_json'] = json_encode($data['recurrence_config']);
        $update['updated_at'] = now();
        DB::table('calendar_events')->where('id', $eventId)->update($update);
    }

    public function deleteEvent(int $eventId): void
    {
        DB::table('calendar_events')->where('id', $eventId)->delete();
    }

    public function getEvents(int $wsId, ?string $from, ?string $to, ?string $category = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();

        $q = DB::table('calendar_events')->where('workspace_id', $wsId)
            ->where(fn($q) => $q->whereBetween('starts_at', [$from, $to])
                ->orWhere(fn($q2) => $q2->whereNotNull('recurrence')));

        if ($category) $q->where('category', $category);

        $events = $q->orderBy('starts_at')->get()->toArray();

        // Expand recurring events within range
        return $this->expandRecurring($events, $from, $to);
    }

    /**
     * Cross-engine: auto-create calendar events from other engines.
     * Called by EngineExecutionService after commits.
     */
    public function syncFromEngine(int $wsId, string $engine, string $action, array $data): void
    {
        $eventData = null;

        if ($engine === 'social' && $action === 'social_schedule_post') {
            $eventData = [
                'title' => 'Social: ' . substr($data['content'] ?? 'Scheduled post', 0, 40),
                'category' => 'social_post', 'engine' => 'social',
                'starts_at' => $data['scheduled_at'], 'color' => '#EC4899',
                'reference_id' => $data['post_id'] ?? null, 'reference_type' => 'social_post',
            ];
        } elseif ($engine === 'marketing' && $action === 'schedule_campaign') {
            $eventData = [
                'title' => 'Campaign: ' . ($data['name'] ?? 'Scheduled'),
                'category' => 'campaign_launch', 'engine' => 'marketing',
                'starts_at' => $data['scheduled_at'], 'color' => '#F59E0B',
                'reference_id' => $data['campaign_id'] ?? null, 'reference_type' => 'campaign',
            ];
        } elseif ($engine === 'write' && $action === 'write_article') {
            $eventData = [
                'title' => 'Publish: ' . ($data['title'] ?? 'Article'),
                'category' => 'content_publish', 'engine' => 'write',
                'starts_at' => now()->addDays(3)->toDateString(), 'color' => '#A78BFA',
                'reference_id' => $data['article_id'] ?? null, 'reference_type' => 'article',
            ];
        }

        if ($eventData) $this->createEvent($wsId, $eventData);
    }

    public function getDashboard(int $wsId): array
    {
        $today = now()->startOfDay();
        $weekEnd = now()->endOfWeek();
        $events = DB::table('calendar_events')->where('workspace_id', $wsId);

        return [
            'today' => (clone $events)->whereDate('starts_at', $today)->orderBy('starts_at')->get()->toArray(),
            'this_week' => (clone $events)->whereBetween('starts_at', [$today, $weekEnd])->orderBy('starts_at')->get()->toArray(),
            'total_events' => (clone $events)->count(),
            'by_category' => (clone $events)->selectRaw('category, COUNT(*) as count')->groupBy('category')->get()->toArray(),
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
            'meeting' => '#6C5CE7', 'task_deadline' => '#F87171', 'campaign_launch' => '#F59E0B',
            'content_publish' => '#A78BFA', 'social_post' => '#EC4899', default => '#3B82F6',
        };
    }
}

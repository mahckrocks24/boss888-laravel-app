<?php

namespace App\Engines\Calendar\Http\Controllers;

use App\Engines\Calendar\Services\CalendarService;
use App\Http\Controllers\Api\BaseEngineController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CAL-2: the owner's own calendar. Calls, meetings, appointments, client bookings and requests, follow-ups,
 * emails, to-dos and strategy meetings with the AI agents. Engine content schedules (campaign posts and articles,
 * social posts, SEO) are NOT here: each engine keeps its own calendar, and Sarah reads those.
 */
class MyCalendarController extends BaseEngineController
{
    /** category => kind shown to the owner */
    public const KINDS = [
        'call' => 'call', 'meeting' => 'meeting', 'appointment' => 'appointment', 'strategy_meeting' => 'strategy',
        'booking_pending' => 'booking', 'booking_confirmed' => 'booking', 'booking_declined' => 'booking',
        'callback_pending' => 'callback', 'callback_confirmed' => 'callback', 'callback_declined' => 'callback',
        'task_deadline' => 'follow_up', 'follow_up' => 'follow_up', 'email' => 'email', 'chat' => 'chat',
        'campaign_owner_task' => 'todo', 'reminder' => 'reminder', 'personal' => 'personal', 'general' => 'event', 'event' => 'event', 'deadline' => 'follow_up',
        'cancelled' => 'appointment', 'completed' => 'appointment', 'no_show' => 'appointment',
    ];
    /** engine content that lives in its engine's own calendar, never on the owner's */
    public const ENGINE_ONLY = ['campaign_post', 'campaign_article', 'campaign_email', 'campaign_ad', 'social_post', 'seo_task', 'publisher_post', 'article_share'];

    protected function engineSlug(): string { return 'calendar'; }

    public static function userScope($q)
    {
        return $q->whereNotIn('category', self::ENGINE_ONLY)
            ->where(fn ($s) => $s->whereNull('reference_type')->orWhereNotIn('reference_type', ['publisher_post', 'article_share']));
    }

    private function statusOf(object $e): string
    {
        if ($e->status) return $e->status;
        $c = (string) $e->category;
        return match (true) {
            str_ends_with($c, '_pending') => 'pending',
            str_ends_with($c, '_confirmed') => 'confirmed',
            str_ends_with($c, '_declined'), $c === 'cancelled' => 'cancelled',
            $c === 'completed' => 'done', $c === 'no_show' => 'no_show',
            default => 'scheduled',
        };
    }

    /** GET /calendar/agenda?from=&to=&business_id= — the owner's items, with the client each one is with. */
    public function agenda(Request $r): JsonResponse
    {
        $ws = $this->wsId($r);
        $from = $r->input('from') ?: now()->startOfDay()->toDateTimeString();
        $to = $r->input('to') ?: now()->addDays(7)->endOfDay()->toDateTimeString();
        $biz = (string) $r->input('business_id', '');
        $q = DB::table('calendar_events')->where('workspace_id', $ws)->whereBetween('starts_at', [$from, $to]);
        self::userScope($q);
        if ($biz === 'none') $q->whereNull('business_id'); elseif (ctype_digit($biz)) $q->where('business_id', (int) $biz);
        $rows = $q->orderBy('starts_at')->limit(1000)->get();
        $leadIds = $rows->pluck('lead_id')->filter()->unique()->values()->all();
        $leads = $leadIds ? DB::table('leads')->whereIn('id', $leadIds)->where('workspace_id', $ws)->get(['id', 'name', 'phone', 'email', 'business_id'])->keyBy('id') : collect();
        $bizNames = DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->pluck('name', 'id');
        $items = $rows->map(function ($e) use ($leads, $bizNames) {
            $l = $e->lead_id ? ($leads[$e->lead_id] ?? null) : null;
            $status = $this->statusOf($e);
            return [
                'id' => $e->id, 'kind' => self::KINDS[$e->category] ?? 'event', 'category' => $e->category, 'title' => $e->title, 'notes' => $e->description,
                'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at, 'all_day' => (bool) $e->all_day, 'status' => $status, 'location' => $e->location,
                'remind_minutes' => $e->remind_minutes, 'business_id' => $e->business_id, 'business_name' => $e->business_id ? ($bizNames[$e->business_id] ?? null) : null,
                'client' => $l ? ['id' => $l->id, 'name' => $l->name, 'phone' => $l->phone, 'email' => $l->email] : null,
                'can_decide' => $status === 'pending' && preg_match('/^(booking|callback)_pending$/', (string) $e->category) === 1,
                'source' => $e->engine,
            ];
        })->values()->all();
        // strategy meetings the owner held with the AI agents in this window (sessions already run)
        $held = DB::table('meetings')->where('workspace_id', $ws)->where('type', 'strategy')->whereBetween('created_at', [$from, $to])
            ->when(ctype_digit($biz), fn ($x) => $x->where('business_id', (int) $biz))->orderBy('created_at')->limit(50)->get(['id', 'title', 'status', 'created_at', 'business_id']);
        foreach ($held as $m) {
            $items[] = ['id' => 'm' . $m->id, 'kind' => 'strategy', 'category' => 'strategy_session', 'title' => $m->title, 'notes' => null, 'starts_at' => (string) $m->created_at, 'ends_at' => null,
                'all_day' => false, 'status' => in_array($m->status, ['closed', 'completed'], true) ? 'done' : 'scheduled', 'location' => 'Strategy Room', 'remind_minutes' => null,
                'business_id' => $m->business_id, 'business_name' => $m->business_id ? ($bizNames[$m->business_id] ?? null) : null, 'client' => null, 'can_decide' => false, 'source' => 'meeting', 'meeting_id' => $m->id];
        }
        usort($items, fn ($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        return $this->readJson(['items' => $items, 'from' => $from, 'to' => $to]);
    }

    /** PUT /calendar/events/{id}/status {status: done|no_show|cancelled|confirmed|scheduled, note?} */
    public function status(Request $r, int $id): JsonResponse
    {
        $ws = $this->wsId($r);
        $e = DB::table('calendar_events')->where('workspace_id', $ws)->where('id', $id)->first();
        if (! $e) return response()->json(['success' => false, 'message' => 'That item was not found.'], 404);
        $st = (string) $r->input('status');
        if (! in_array($st, ['done', 'no_show', 'cancelled', 'confirmed', 'scheduled'], true)) return response()->json(['success' => false, 'message' => 'Unknown status.'], 422);
        $note = mb_substr(trim((string) $r->input('note', '')), 0, 2000);
        DB::table('calendar_events')->where('id', $id)->update(['status' => $st, 'updated_at' => now()]);
        $kind = self::KINDS[$e->category] ?? 'event';
        // a follow-up task done here is done in Clients too
        if ($e->reference_type === 'Activity' && $e->reference_id && $st === 'done') {
            DB::table('activities')->where('id', $e->reference_id)->where('workspace_id', $ws)->update(['completed' => 1, 'completed_at' => now(), 'updated_at' => now()]);
        }
        // the client's timeline hears how it went
        if ($e->lead_id && DB::table('leads')->where('id', $e->lead_id)->where('workspace_id', $ws)->exists()) {
            $type = $st === 'done' ? (['call' => 'call', 'callback' => 'call', 'email' => 'email'][$kind] ?? 'meeting') : 'booked';
            $label = ['done' => ($type === 'call' ? 'Call' : ($type === 'email' ? 'Email' : 'Meeting')) . ' done', 'no_show' => 'No-show', 'cancelled' => 'Cancelled', 'confirmed' => 'Confirmed', 'scheduled' => 'Back on the calendar'][$st];
            if (! ($e->reference_type === 'Activity' && $st === 'done')) {
                \App\Models\Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => (int) $e->lead_id, 'type' => $type,
                    'subject' => $label . ': ' . $e->title, 'description' => $note !== '' ? $note : null, 'completed' => 1, 'performed_by' => $this->userId($r),
                    'metadata_json' => ['event_id' => $e->id, 'status' => $st]]);
            }
        }
        return $this->readJson(['success' => true, 'status' => $st]);
    }

    /** POST /calendar/schedule — the owner adds something to their own calendar (with a client, a place and a reminder). */
    public function schedule(Request $r): JsonResponse
    {
        $ws = $this->wsId($r);
        $r->validate(['title' => 'required|string|max:190', 'starts_at' => 'required|date', 'kind' => 'required|string']);
        $cat = ['call' => 'call', 'meeting' => 'meeting', 'appointment' => 'appointment', 'strategy' => 'strategy_meeting', 'follow_up' => 'follow_up',
            'email' => 'email', 'chat' => 'chat', 'reminder' => 'reminder', 'personal' => 'personal'][$r->input('kind')] ?? null;
        if (! $cat) return response()->json(['success' => false, 'message' => 'Unknown kind.'], 422);
        $lead = $r->filled('lead_id') ? DB::table('leads')->where('workspace_id', $ws)->where('id', (int) $r->input('lead_id'))->whereNull('deleted_at')->first(['id', 'business_id']) : null;
        $rm = $r->input('remind_minutes');
        $noReminder = $rm === 'none';
        if ($noReminder) $rm = null;
        $id = app(CalendarService::class)->createEvent($ws, [
            'title' => $r->input('title'), 'description' => $r->input('notes'), 'category' => $cat, 'engine' => 'calendar',
            'starts_at' => $r->input('starts_at'), 'ends_at' => $r->input('ends_at'), 'all_day' => (bool) $r->input('all_day', false),
            'reference_type' => $lead ? 'Lead' : null, 'reference_id' => $lead->id ?? null, 'lead_id' => $lead->id ?? null,
            'business_id' => $r->input('business_id') ?: ($lead->business_id ?? null), 'location' => $r->input('location'),
            'remind_minutes' => $rm === null || $rm === '' ? (($noReminder ?? false) ? null : 30) : max(0, (int) $rm), 'status' => 'scheduled', 'created_by' => $this->userId($r),
        ]);
        if ($noReminder) DB::table('calendar_events')->where('id', $id)->update(['reminded_at' => now()]);
        return $this->readJson(['success' => true, 'id' => $id], 201);
    }
}

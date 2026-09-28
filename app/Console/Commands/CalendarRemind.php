<?php

namespace App\Console\Commands;

use App\Core\Agents\AgentMessageService;
use App\Core\Brand\BrandIntakeService;
use App\Core\Notifications\NotificationService;
use App\Engines\Calendar\Http\Controllers\MyCalendarController;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CAL-2 reminders (Owner 2026-09-29: "Notifications, emails, reminders must be set in place").
 *
 * Every item on an owner's own calendar is reminded before it starts:
 *   1. Sarah says it in the owner's chat, in her words (the chat post also pushes to the companion app), and
 *   2. the bell notification, which emails the owner unless they switched calendar reminders off.
 *
 * Times on the calendar are the owner's local wall time (workspaces.timezone, adopted from the app, TZ-1), so "now"
 * is taken in that zone. Default lead time: 30 minutes for calls, meetings, appointments, confirmed bookings and
 * strategy meetings; at the time for follow-ups and to-dos. Booking REQUESTS are not reminded (the owner was told
 * when they arrived). Engine content (campaign posts, social) is never reminded here.
 *
 * Runs every minute when storage/app/remind1.on exists. `--dry-run` lists who would be reminded and sends nothing.
 */
class CalendarRemind extends Command
{
    protected $signature = 'calendar:remind {--dry-run : list what would be sent, send nothing} {--workspace= : one workspace only}';
    protected $description = 'Remind owners of their calendar items (Sarah in chat + notification + email)';

    private const DEFAULT_LEAD = ['call' => 30, 'meeting' => 30, 'appointment' => 30, 'strategy_meeting' => 30, 'booking_confirmed' => 30, 'callback_confirmed' => 15,
        'chat' => 15, 'email' => 0, 'follow_up' => 0, 'task_deadline' => 0, 'campaign_owner_task' => 0, 'reminder' => 0, 'personal' => 30, 'general' => 30, 'event' => 30];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('calendar_events')->whereNull('reminded_at')
            ->whereIn('category', array_keys(self::DEFAULT_LEAD))
            ->where(fn ($s) => $s->whereNull('status')->orWhereIn('status', ['scheduled', 'confirmed']))
            ->where('starts_at', '<=', now()->addDays(2)) // cheap window; exact check below per workspace clock
            ->where('starts_at', '>=', now()->subDays(2));
        MyCalendarController::userScope($q);
        if ($this->option('workspace')) $q->where('workspace_id', (int) $this->option('workspace'));
        $due = []; $tzs = [];
        foreach ($q->orderBy('starts_at')->limit(500)->get() as $e) {
            $tz = $tzs[$e->workspace_id] ??= $this->tz((int) $e->workspace_id);
            $now = Carbon::now($tz);
            $start = Carbon::parse($e->starts_at, $tz);
            $lead = $e->remind_minutes !== null ? (int) $e->remind_minutes : (self::DEFAULT_LEAD[$e->category] ?? 30);
            if ($start->copy()->subMinutes($lead)->gt($now)) continue;          // not yet
            if ($start->lt($now->copy()->subMinutes(15))) {                   // already well past: never remind late
                if (! $dry) DB::table('calendar_events')->where('id', $e->id)->update(['reminded_at' => now()]);
                continue;
            }
            $due[] = [$e, $start, $now, $tz];
        }
        $this->info(($dry ? '[dry run] ' : '') . count($due) . ' reminder(s) due');
        foreach ($due as [$e, $start, $now, $tz]) {
            $this->line(sprintf('  ws %d · #%d %s · %s (%s) · %s', $e->workspace_id, $e->id, $e->category, $start->format('D j M H:i'), $tz, mb_substr((string) $e->title, 0, 60)));
            if ($dry) continue;
            // claim it first so two runs never send twice
            if (! DB::table('calendar_events')->where('id', $e->id)->whereNull('reminded_at')->update(['reminded_at' => now()])) continue;
            try { $this->remind($e, $start, $now); } catch (\Throwable $x) { Log::warning('[calendar:remind] ' . $x->getMessage(), ['event' => $e->id]); }
        }
        return self::SUCCESS;
    }

    private function tz(int $wsId): string
    {
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?? '');
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    private function remind(object $e, Carbon $start, Carbon $now): void
    {
        $ws = (int) $e->workspace_id;
        $lead = $e->lead_id ? DB::table('leads')->where('id', $e->lead_id)->first(['id', 'name', 'phone', 'email']) : null;
        $biz = $e->business_id ? DB::table('businesses')->where('id', $e->business_id)->value('name') : null;
        $mins = max(0, (int) round($now->diffInMinutes($start, false)));
        $when = $mins <= 1 ? 'now' : ($mins < 60 ? "in {$mins} minutes" : 'at ' . $start->format('g:i A'));
        $kind = [
            'call' => 'Call', 'callback_confirmed' => 'Callback', 'meeting' => 'Meeting', 'appointment' => 'Appointment', 'booking_confirmed' => 'Booking',
            'strategy_meeting' => 'Strategy meeting', 'chat' => 'Chat', 'email' => 'Email to send', 'follow_up' => 'Follow-up', 'task_deadline' => 'Follow-up',
            'campaign_owner_task' => 'Campaign step', 'reminder' => 'Reminder', 'personal' => 'Reminder', 'general' => 'Event', 'event' => 'Event',
        ][$e->category] ?? 'Event';
        $fallback = "{$kind} {$when}: **" . $e->title . '**' . ($lead ? ' with ' . $lead->name . ($lead->phone ? ' (' . $lead->phone . ')' : '') : '')
            . ($e->location ? ' · ' . $e->location : '') . ($e->category === 'strategy_meeting' ? '. The team is ready when you are.' : '.');
        $facts = ['kind' => $kind, 'title' => $e->title, 'starts' => $start->format('g:i A'), 'in_minutes' => $mins, 'with' => $lead->name ?? null,
            'phone' => $lead->phone ?? null, 'where' => $e->location, 'business' => $biz, 'notes' => $e->description ? mb_substr((string) $e->description, 0, 300) : null];
        $text = $fallback;
        try {
            $text = app(BrandIntakeService::class)->sarahWords($ws, 'calendar_reminder',
                'Remind the owner of this item on their calendar in one or two short sentences, warm and practical. Say what it is, when, and with whom. '
                . 'If there are notes, add the one detail that helps them prepare. Bold the title with **. No greeting, no sign-off.', $facts, $fallback);
        } catch (\Throwable $x) { /* the fallback stands */ }
        app(AgentMessageService::class)->postAsAgent($ws, 'sarah', $text, ['notification_type' => 'calendar_reminder', 'event_id' => (int) $e->id,
            'screen' => 'calendar', 'action_url' => $lead ? '/app/crm/' . $lead->id : '/app/calendar']);
        // the bell (and email, unless the owner switched calendar reminders off)
        $owners = DB::table('workspace_users')->where('workspace_id', $ws)->whereIn('role', ['owner', 'admin'])->pluck('user_id')->unique();
        foreach ($owners as $uid) {
            app(NotificationService::class)->dispatch(
                type: 'calendar.reminder', userId: (int) $uid,
                title: $kind . ' ' . $when . ': ' . mb_substr((string) $e->title, 0, 120), workspaceId: $ws,
                body: trim(($lead ? 'With ' . $lead->name . ($lead->phone ? ' · ' . $lead->phone : '') . '. ' : '') . ($e->location ? 'Where: ' . $e->location . '. ' : '') . 'Starts ' . $start->format('D j M, g:i A') . '.'),
                data: ['event_id' => (int) $e->id, 'lead_id' => $lead->id ?? null], actionUrl: $lead ? '/app/crm/' . $lead->id : '/app/calendar', severity: 'info');
        }
    }
}

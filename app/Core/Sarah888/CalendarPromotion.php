<?php

namespace App\Core\Sarah888;

use App\Core\Orchestration\ToolSchemaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REPORT-0071 P1-1 (2026-10-02): "Put a private tasting dinner on my calendar for Saturday 10 October at 7pm" went to the
 * WEBSITE builder ("calendar" read as a page section) and asked which of 14 sites to change; no event was created.
 *
 * A scheduling request is the owner's own calendar (Owner 2026-09-29: the calendar is the user's schedule). It is
 * recognised structurally - the owner's calendar/diary/schedule named, or a scheduling verb with a resolvable date - and
 * routed to the calendar capability through the governed tool (calendar.create_event keeps its own approval mode). The
 * builder only sees "calendar" when the object is a website element (section, page, widget, booking form on the site).
 */
final class CalendarPromotion
{
    private const MY_CALENDAR = '/\b(on|in|to|into)\s+(my|our|the)\s+(calendar|diary|schedule|agenda)\b/i';
    private const SCHED_VERB = '/\b(schedule|book|pencil in|block( out)?|put|add|set up|arrange|remind me)\b/i';
    private const WEBSITE_OBJECT = '/\b(website|site|page|section|widget|booking form|booking page|embed|homepage|home page)\b/i';
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    /** Is this a request to put something on the owner's own calendar? */
    public static function isScheduling(string $m): bool
    {
        if (preg_match(self::WEBSITE_OBJECT, $m) && ! preg_match(self::MY_CALENDAR, $m)) return false;
        if (preg_match(self::MY_CALENDAR, $m)) return true;
        return (bool) (preg_match(self::SCHED_VERB, $m) && self::when($m, 'UTC') !== null && preg_match('/\b(at|@)\s*\d{1,2}(:\d{2})?\s*(am|pm)?\b|\b\d{1,2}(:\d{2})?\s*(am|pm)\b/i', $m));
    }

    /** The date and time in the message, in the owner's timezone; null when there is no resolvable date. */
    public static function when(string $m, string $tz): ?Carbon
    {
        $now = Carbon::now($tz);
        $date = null;
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(' . self::MONTHS . ')\b/i', $m, $d) || preg_match('/\b(' . self::MONTHS . ')\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $m, $d2)) {
            $day = isset($d[1]) ? (int) $d[1] : (int) $d2[2]; $mon = isset($d[2]) ? $d[2] : $d2[1];
            try { $date = Carbon::parse($day . ' ' . $mon . ' ' . $now->year, $tz); if ($date->lt($now->copy()->startOfDay())) $date->addYear(); } catch (\Throwable) { $date = null; }
        } elseif (preg_match('/\b(today|tonight|tomorrow)\b/i', $m, $r)) {
            $date = strtolower($r[1]) === 'tomorrow' ? $now->copy()->addDay() : $now->copy();
        } elseif (preg_match('/\b(?:this|next|on)?\s*(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $m, $w)) {
            $date = $now->copy()->next(ucfirst(strtolower($w[1])));
        }
        if (! $date) return null;
        $h = 9; $min = 0;
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $m, $t) || preg_match('/\bat\s+(\d{1,2})(?::(\d{2}))\b/i', $m, $t)) {
            $h = (int) $t[1]; $min = isset($t[2]) && $t[2] !== '' ? (int) $t[2] : 0; $ap = strtolower($t[3] ?? '');
            if ($ap === 'pm' && $h < 12) $h += 12; if ($ap === 'am' && $h === 12) $h = 0;
        }
        return $date->setTime($h, $min);
    }

    /** A short title from the owner's words: what the event is, and who it is with. */
    public static function title(string $m): string
    {
        $t = preg_replace('/^\s*(please\s+)?(can you\s+|could you\s+)?(schedule|book|pencil in|block out|block|put|add|set up|arrange|remind me (about|of|to))\s+(a|an|the)?\s*/i', '', trim($m));
        $t = preg_replace(self::MY_CALENDAR, ' ', $t);
        $t = preg_replace('/\b(for|on)\s+(this |next )?(monday|tuesday|wednesday|thursday|friday|saturday|sunday|today|tonight|tomorrow)\b/i', ' ', $t);
        $t = preg_replace('/\b(\d{1,2})(st|nd|rd|th)?\s+(' . self::MONTHS . ')\b|\b(' . self::MONTHS . ')\s+\d{1,2}(st|nd|rd|th)?\b/i', ' ', $t);
        $t = preg_replace('/\b(at|@)\s*\d{1,2}(:\d{2})?\s*(am|pm)?\b|\b\d{1,2}(:\d{2})?\s*(am|pm)\b/i', ' ', $t);
        $t = trim(preg_replace(['/\s+,/', '/,\s*,/', '/\s{2,}/', '/^[\s,.-]+|[\s,.-]+$/'], [',', ',', ' ', ''], $t));
        return mb_substr(ucfirst($t !== '' ? $t : 'Appointment'), 0, 140);
    }

    /** @return array{handled:bool, reply:string, event_id:?int}|null null = not a scheduling request with a date (Sarah asks) */
    public static function promote(ToolSchemaService $svc, int $wsId, string $ownerMessage, string $slug, ?int $businessId = null): ?array
    {
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        $at = self::when($ownerMessage, $tz);
        if (! $at) return null;
        $title = self::title($ownerMessage);
        $r = $svc->executeToolCall('calendar.create_event', array_filter(['title' => $title, 'start_at' => $at->format('Y-m-d H:i:s'), 'starts_at' => $at->format('Y-m-d H:i:s'),
            'notes' => $ownerMessage, 'description' => $ownerMessage, 'business_id' => $businessId]), $wsId, $slug, ['workspace_id' => $wsId]);
        $r = is_array($r) ? $r : [];
        $when = $at->format('l j F') . ' at ' . $at->format('g:ia');
        Log::info('[CAL-PROMO] scheduling request routed to calendar', ['ws' => $wsId, 'title' => $title, 'at' => $at->toDateTimeString(), 'result' => array_intersect_key($r, array_flip(['success', 'status', 'approval_id', 'event_id', 'error']))]);
        $eventId = (int) ($r['data']['entity_id'] ?? $r['entity_id'] ?? $r['event_id'] ?? 0) ?: null;
        $pending = ! empty($r['pending_approval']) || ($r['code'] ?? '') === 'AWAITING_APPROVAL';
        if (($r['success'] ?? false) === true && $pending) {
            return ['handled' => true, 'event_id' => null, 'reply' => "Ready to go on your calendar: \"{$title}\", {$when}. It's waiting for your OK in the review queue; approve it and it's in, with a reminder."];
        }
        if (($r['success'] ?? false) === true && ! $pending) {
            return ['handled' => true, 'event_id' => $eventId, 'reply' => "Done. \"{$title}\" is on your calendar for {$when}, and you'll get a reminder before it."];
        }
        return ['handled' => true, 'event_id' => null, 'reply' => "I couldn't put \"{$title}\" on your calendar just now. Nothing was saved. Tell me to try again, or add it from the Calendar page."];
    }
}

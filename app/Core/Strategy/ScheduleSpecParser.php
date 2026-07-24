<?php

namespace App\Core\Strategy;

use Carbon\Carbon;

/**
 * ScheduleSpecParser — extract a calendar-plottable schedule spec from
 * free-form goal text.
 *
 * Designed to feed PlanSchedulerService::plotSchedule() automatically.
 * Returns the same shape that PlanSchedulerService expects, OR null if
 * the text contains no detectable schedule pattern (callers fall through
 * to non-scheduled behavior).
 *
 * Algorithm: extract three independent pieces — frequency (items/period),
 * duration (D periods or end_date), start_date — then assemble. Each
 * piece is optional; if neither duration NOR end_date is detected,
 * returns null (a schedule without a duration is meaningless).
 *
 * Examples that parse:
 *   "write 3 articles per day for 2 weeks"   → {items_per_day:3, days:14}
 *   "5 posts a week for a month"             → {items_per_day:5/7≈1, days:30}
 *   "daily for 10 days"                      → {items_per_day:1, days:10}
 *   "every day for 3 weeks"                  → {items_per_day:1, days:21}
 *   "10 emails over the next 30 days"        → {items_per_day:1, days:30}
 *                                              (10/30≈0.33, rounded to 1/day)
 *   "twice weekly for 2 months"              → {items_per_day:~0.28, days:60}
 *                                              (2*8/60≈0.27, rounded up)
 *
 * Defaults applied when needed:
 *   - start_date: tomorrow (today is risky; tasks may already be planned)
 *   - time_of_day: "09:00"
 *   - duration_minutes: 60
 */
class ScheduleSpecParser
{
    /**
     * b15 (2026-07-24) — SUB-DAILY CADENCE + OPEN-ENDED RECURRENCE.
     *
     * Two gaps closed:
     *  1. "every 5 minutes" / "every 2 hours" had no representation at all —
     *     extractFrequency only understood day/week/month, so an intra-day
     *     cadence silently produced NO schedule.
     *  2. parse() bailed out (null) unless the goal ALSO carried an explicit
     *     duration ("for 10 days") or end date. A standing instruction like
     *     "publish 2 articles every 5 minutes" is a perfectly valid recurring
     *     schedule — it just has no end — so it now gets a default horizon.
     *
     * Guardrails (enterprise): a floor on the interval, a cap on how many
     * occurrences one plot may create, and a bounded default horizon so an
     * open-ended instruction can never plot an unbounded calendar. Every
     * clamp applied is reported back in `adjustments` so Sarah can tell the
     * user exactly what was changed and why.
     */
    public const MIN_INTERVAL_MINUTES = 5;    // floor — faster cadences clamp up
    public const MAX_OCCURRENCES      = 100;  // hard cap per plot (runaway guard)
    public const DEFAULT_INTERVAL_HORIZON_DAYS = 1;   // open-ended intra-day cadence
    public const DEFAULT_DAILY_HORIZON_DAYS    = 14;  // open-ended daily/weekly cadence

    public function parse(string $goal): ?array
    {
        $g = strtolower(trim($goal));
        if ($g === '') return null;

        $adjustments     = [];
        $intervalMinutes = $this->extractIntervalMinutes($g);
        $itemsPerDay     = $this->extractFrequency($g);
        $durationDays    = $this->extractDuration($g);
        $startDate       = $this->extractStartDate($g);
        $endDate         = $this->extractEndDate($g);

        // A goal is a SCHEDULE if it carries any recurrence signal.
        $hasRecurrence = ($intervalMinutes !== null) || ($itemsPerDay !== null);
        if (!$hasRecurrence && $durationDays === null && $endDate === null) {
            return null; // genuinely not a schedule
        }

        // Open-ended recurrence → bounded default horizon (was: return null).
        if ($durationDays === null && $endDate === null) {
            $durationDays = $intervalMinutes !== null
                ? self::DEFAULT_INTERVAL_HORIZON_DAYS
                : self::DEFAULT_DAILY_HORIZON_DAYS;
            $adjustments[] = "No end date given — scheduled for the next {$durationDays} day(s); re-run to extend.";
        }

        // Duration present but no frequency → 1/day (existing behaviour).
        if ($itemsPerDay === null && $intervalMinutes === null) {
            $itemsPerDay = 1;
        }

        $spec = [
            'duration_minutes' => 60,
            'max_occurrences'  => self::MAX_OCCURRENCES,
        ];

        if ($intervalMinutes !== null) {
            // ── Intra-day cadence ────────────────────────────────────────
            if ($intervalMinutes < self::MIN_INTERVAL_MINUTES) {
                $adjustments[] = "Interval raised from {$intervalMinutes} to " . self::MIN_INTERVAL_MINUTES . " minutes (minimum safe cadence).";
                $intervalMinutes = self::MIN_INTERVAL_MINUTES;
            }
            // Start almost immediately — a standing "every N minutes" means now,
            // not tomorrow morning.
            $startAt = $startDate ? Carbon::parse($startDate) : Carbon::now()->addMinutes(2);
            $spec['start_date']       = $startAt->toDateString();
            $spec['time_of_day']      = $startAt->format('H:i');
            $spec['interval_minutes'] = $intervalMinutes;
            $spec['items_per_slot']   = $this->extractItemsPerSlot($g);
            $spec['items_per_day']    = $spec['items_per_slot'];
        } else {
            // ── Daily / weekly / monthly cadence (unchanged) ─────────────
            $spec['start_date']    = $startDate ?? Carbon::tomorrow()->toDateString();
            $spec['items_per_day'] = max(1, (int) round($itemsPerDay));
            $spec['time_of_day']   = '09:00';
        }

        if ($durationDays !== null) $spec['days']     = $durationDays;
        if ($endDate !== null)      $spec['end_date'] = $endDate;
        if ($adjustments)           $spec['adjustments'] = $adjustments;

        return $spec;
    }

    /**
     * "every 5 minutes" / "every 5 mins" / "every 2 hours" / "every hour".
     * Returns the cadence in MINUTES, or null when the goal has no intra-day
     * cadence (in which case the day/week/month extractor takes over).
     */
    private function extractIntervalMinutes(string $g): ?int
    {
        if (preg_match('/\b(?:every|each|once\s+every)\s+(\d+)\s*(minute|min|hour|hr)s?\b/i', $g, $m)) {
            $n    = max(1, (int) $m[1]);
            $unit = strtolower($m[2]);
            return str_starts_with($unit, 'h') ? $n * 60 : $n;
        }
        if (preg_match('/\b(?:every|each)\s+(minute|hour)\b/i', $g, $m)) {
            return strtolower($m[1]) === 'hour' ? 60 : 1;
        }
        return null;
    }

    /** "publish 2 articles every 5 minutes" → 2 items per occurrence (default 1). */
    private function extractItemsPerSlot(string $g): int
    {
        $itemNouns = 'articles?|posts?|blogs?|items?|pieces?|emails?|videos?';
        if (preg_match("/(\d+)\s+(?:\w+\s+){0,3}(?:{$itemNouns})\b/i", $g, $m)) {
            return max(1, min(20, (int) $m[1])); // cap per-slot burst
        }
        return 1;
    }

    // ─── Frequency extractors ────────────────────────────────────────

    private function extractFrequency(string $g): ?float
    {
        /* b14b-parser-fix */
        // "N items per/a/each day/week/month" — allow optional adjective
        // words between digit and noun ("4 social media items" → matches).
        // The (?:\w+\s+){0,3} segment allows up to 3 modifier words.
        $itemNouns = 'articles?|posts?|emails?|designs?|items?|videos?|drafts?|pieces?|ads?|leads?|outreaches?|campaigns?|contents?|stories?|reels?|episodes?|threads?';
        if (preg_match(
            "/(\d+)\s+(?:\w+\s+){0,3}(?:{$itemNouns})\s+(?:per|a|each|every)\s+(day|week|month)/i",
            $g, $m
        )) {
            $n = (int) $m[1];
            return $this->perPeriodToPerDay($n, $m[2]);
        }

        // "N times daily/weekly/monthly" or "N times per day/week"
        $numWords = ['once' => 1, 'twice' => 2, 'thrice' => 3];
        if (preg_match(
            '/(once|twice|thrice|(\d+)\s+times?)\s+(?:per\s+|a\s+)?(daily|weekly|monthly|day|week|month)/i',
            $g, $m
        )) {
            $word = strtolower($m[1]);
            if (preg_match('/(\d+)/', $word, $nm)) {
                $n = (int) $nm[1];
            } else {
                $n = $numWords[$word] ?? 1;
            }
            $period = strtolower($m[3]);
            $period = match ($period) {
                'daily'   => 'day',
                'weekly'  => 'week',
                'monthly' => 'month',
                default   => $period,
            };
            return $this->perPeriodToPerDay($n, $period);
        }

        // "every/each day/week/month" → 1 per period
        if (preg_match('/\b(?:every|each)\s+(day|week|month)\b/i', $g, $m)) {
            return $this->perPeriodToPerDay(1, $m[1]);
        }

        // b15 — "N items daily/weekly/monthly" (e.g. "publish 2 articles daily").
        // Previously only the BARE "daily" pattern below matched, so the count
        // was silently dropped and 2/day became 1/day.
        $itemNouns = 'articles?|posts?|blogs?|items?|pieces?|emails?|videos?';
        if (preg_match("/(\d+)\s+(?:\w+\s+){0,3}(?:{$itemNouns})\s+(?:\w+\s+){0,2}(daily|weekly|monthly)\b/i", $g, $m)) {
            return $this->perPeriodToPerDay((int) $m[1], match (strtolower($m[2])) {
                'daily' => 'day', 'weekly' => 'week', 'monthly' => 'month',
            });
        }

        // b15 — "N items every day/week/month" (count + explicit period).
        if (preg_match("/(\d+)\s+(?:\w+\s+){0,3}(?:{$itemNouns})\s+(?:\w+\s+){0,2}(?:every|each)\s+(day|week|month)\b/i", $g, $m)) {
            return $this->perPeriodToPerDay((int) $m[1], strtolower($m[2]));
        }

        // Bare "daily" / "weekly" / "monthly"
        if (preg_match('/\b(daily|weekly|monthly)\b/i', $g, $m)) {
            $period = strtolower($m[1]);
            return $this->perPeriodToPerDay(1, match ($period) {
                'daily' => 'day', 'weekly' => 'week', 'monthly' => 'month',
            });
        }

        return null;
    }

    private function perPeriodToPerDay(int $items, string $period): float
    {
        return match ($period) {
            'day'   => (float) $items,
            'week'  => $items / 7.0,
            'month' => $items / 30.0,
            default => (float) $items,
        };
    }

    // ─── Duration extractor ──────────────────────────────────────────

    private function extractDuration(string $g): ?int
    {
        /* b14b-parser-fix */
        // "for/over/across/in [the] [next/coming] D days/weeks/months".
        // All prepositions now allow the same optional "the next/coming" modifier.
        if (preg_match(
            '/(?:for|over|across|in)\s+(?:the\s+)?(?:next\s+|coming\s+)?(\d+)\s+(day|week|month)s?/i',
            $g, $m
        )) {
            return $this->periodsToDays((int) $m[1], $m[2]);
        }

        // English number words ("one week", "two months", etc.)
        $numWords = ['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,
                      'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12];
        if (preg_match(
            '/(?:for|over|across|in)\s+(?:the\s+)?(?:next\s+|coming\s+)?(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)\s+(day|week|month)s?/i',
            $g, $m
        )) {
            $n = $numWords[strtolower($m[1])] ?? 1;
            return $this->periodsToDays($n, $m[2]);
        }

        // "for a (day|week|month|fortnight)" → 1 unit
        if (preg_match('/\bfor\s+a\s+(day|week|month|fortnight)\b/i', $g, $m)) {
            return $this->periodsToDays(1, $m[1]);
        }

        // "fortnight" alone
        if (preg_match('/\bfortnight\b/i', $g)) {
            return 14;
        }

        return null;
    }

    private function periodsToDays(int $n, string $period): int
    {
        return match (strtolower($period)) {
            'day', 'days'         => $n,
            'week', 'weeks'       => $n * 7,
            'month', 'months'     => $n * 30,
            'fortnight'           => 14,
            default               => $n,
        };
    }

    // ─── Start date extractor ────────────────────────────────────────

    private function extractStartDate(string $g): ?string
    {
        // ISO date: "starting/from/begin(ning) YYYY-MM-DD"
        if (preg_match(
            '/(?:starting|from|begin(?:ning)?)\s+(\d{4}-\d{2}-\d{2})/i',
            $g, $m
        )) {
            return $m[1];
        }

        // "tomorrow"
        if (preg_match('/\b(?:starting|from|begin(?:ning)?)\s+tomorrow\b/i', $g)) {
            return Carbon::tomorrow()->toDateString();
        }

        // "next monday/tuesday/..."
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        if (preg_match('/\bnext\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $g, $m)) {
            return Carbon::parse('next ' . $m[1])->toDateString();
        }

        // "next week" / "next month"
        if (preg_match('/\b(?:starting|from)\s+next\s+(week|month)\b/i', $g, $m)) {
            return $m[1] === 'week'
                ? Carbon::now()->next(Carbon::MONDAY)->toDateString()
                : Carbon::now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        }

        return null;
    }

    // ─── End date extractor ──────────────────────────────────────────

    private function extractEndDate(string $g): ?string
    {
        // "until/by YYYY-MM-DD"
        if (preg_match(
            '/(?:until|by)\s+(\d{4}-\d{2}-\d{2})/i',
            $g, $m
        )) {
            return $m[1];
        }

        // "until/by end of month"
        if (preg_match('/(?:until|by)\s+(?:the\s+)?end\s+of\s+(?:the\s+)?month/i', $g)) {
            return Carbon::now()->endOfMonth()->toDateString();
        }

        // "until/by end of week"
        if (preg_match('/(?:until|by)\s+(?:the\s+)?end\s+of\s+(?:the\s+)?week/i', $g)) {
            return Carbon::now()->endOfWeek()->toDateString();
        }

        return null;
    }
}
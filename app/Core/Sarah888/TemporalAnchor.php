<?php

namespace App\Core\Sarah888;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SARAH888 — the temporal anchor.
 *
 * Sarah was never told what day it is. CognitiveFrame assembled four blocks —
 * horizon, commitments, ledger, delegations — and not one of them carried a
 * date, so every question involving "today", "next week" or "how long until"
 * was answered from whatever the model believes the date to be, which is a
 * property of its training data rather than of this workspace. Measured on the
 * forensic tenant the rendered frame contained no ISO date and no "Today is"
 * anywhere in 9,957 characters.
 *
 * Two things follow from that, and this class provides both.
 *
 * 1. THE ANCHOR. Every frame states the current instant in UTC and in the
 *    workspace's own timezone, plus the timezone itself. A workspace in
 *    Auckland and one in Bristol disagree about what "today" means for up to
 *    thirteen hours a day, and a deadline that reads "due today" is either
 *    urgent or already missed depending on which one you are standing in.
 *
 * 2. THE ARITHMETIC. "24 days before 17 September" is computed here, in code,
 *    and handed to the model as a fact. Language models do calendar arithmetic
 *    by pattern rather than by counting; they are wrong often enough that a
 *    date arrived at by generation cannot be put in front of an executive.
 *    Carbon knows about month lengths and leap years. The model does not need
 *    to.
 *
 * Nothing here invents a business fact. It reports the clock, and it counts
 * days between dates that already exist in the authoritative record.
 */
class TemporalAnchor
{
    public const CALC_VERSION = 'ta-v1';

    /** Cache of workspace timezones for the life of the request. */
    private array $tzCache = [];

    /**
     * The workspace's timezone, falling back to UTC.
     *
     * An invalid or empty value in the column must not throw — a bad timezone
     * string is a data problem, and losing the whole frame over it would turn
     * a cosmetic fault into an outage.
     */
    public function timezone(int $wsId): string
    {
        if (isset($this->tzCache[$wsId])) return $this->tzCache[$wsId];

        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?? '');
        if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }
        return $this->tzCache[$wsId] = $tz;
    }

    /** @return array{utc:CarbonImmutable, local:CarbonImmutable, tz:string} */
    public function now(int $wsId): array
    {
        $utc = CarbonImmutable::now('UTC');
        $tz  = $this->timezone($wsId);
        return ['utc' => $utc, 'local' => $utc->setTimezone($tz), 'tz' => $tz];
    }

    /**
     * The prompt block. Short by design — it is paid for on every single turn,
     * and it is the one block that must never be dropped to save budget.
     */
    public function render(int $wsId): string
    {
        $n = $this->now($wsId);
        $l = $n['local'];

        $s  = "CURRENT TIME (authoritative — computed by the platform, not recalled).\n";
        $s .= "  Today is {$l->format('l, j F Y')} in this workspace.\n";
        $s .= "  Workspace local : {$l->format('Y-m-d H:i')} ({$n['tz']})\n";
        $s .= "  UTC             : {$n['utc']->format('Y-m-d H:i')}Z\n";
        $s .= "  Use these dates. Do NOT infer today's date from anything you\n"
            . "  learned in training — it will be wrong. Any date arithmetic\n"
            . "  already computed for you below is exact; prefer it to your own\n"
            . "  counting.\n\n";
        return $s;
    }

    /**
     * Deterministic relative-date arithmetic.
     *
     *   "24 days before 17 September"  -> 2026-08-24
     *   "90 days after 1 Oct"          -> 2026-12-30
     *   "3 weeks before 1 March 2027"  -> 2027-02-08
     *
     * Returns a derived fact, or null when the expression is not one of these
     * shapes. Returning null matters: a half-understood date is worse than an
     * admission that the question was not understood, because the owner cannot
     * tell the difference between a computed answer and a guessed one.
     *
     * @return array<string,mixed>|null
     */
    public function resolveExpression(string $text, int $wsId): ?array
    {
        $t = $this->normalise($text);
        $fullText = $t;

        if (!preg_match(
            '/\b(\d{1,4})\s+(day|days|week|weeks|month|months|year|years)\s+'
            . '(before|after|from|prior\s+to|ahead\s+of)\s+'
            . '(.{2,60}?)\s*[.?!]?$/i', $t, $m)) {
            return null;
        }

        $n    = (int) $m[1];
        // rtrim before strtolower left "DAYS" as "DAY", which matched no arm
        // of the match() below and threw UnhandledMatchError — a 500 on a turn
        // for the sole offence of typing in capitals. The pattern is
        // case-insensitive, so every capture from it must be normalised before
        // it is compared against lower-case literals.
        $unit = rtrim(strtolower($m[2]), 's');
        $dir  = preg_match('/^(before|prior|ahead)/i', trim($m[3])) ? -1 : 1;
        $rawAnchor = trim($m[4]);

        $anchor = $this->resolveAnchorDate($rawAnchor, $wsId);

        // The date does not always follow the arithmetic. "If something is due
        // 17 September, what is 24 days before that?" leaves the anchor as the
        // bare word "that", and the date sits earlier in the sentence. Rather
        // than refuse a perfectly ordinary phrasing, a pronoun anchor is
        // resolved against the rest of the turn — but ONLY against a literal
        // date found there. A pronoun that resolves to nothing stays
        // unresolved, because inventing the referent is how a wrong date gets
        // stated with confidence.
        if ($anchor === null && preg_match('/^(?:that|this|it|then)\b/i', $rawAnchor)) {
            $anchor = $this->resolveAnchorDate(str_replace($rawAnchor, '', $fullText), $wsId);
        }
        if ($anchor === null) return null;

        $base = $anchor['date'];
        $out  = match ($unit) {
            'day'   => $dir < 0 ? $base->subDays($n)   : $base->addDays($n),
            'week'  => $dir < 0 ? $base->subWeeks($n)  : $base->addWeeks($n),
            'month' => $dir < 0 ? $base->subMonths($n) : $base->addMonths($n),
            'year'  => $dir < 0 ? $base->subYears($n)  : $base->addYears($n),
            // Unreachable given the pattern above, but a date routine must
            // degrade to "I could not work it out" rather than take the
            // request down if that ever stops being true.
            default => null,
        };
        if ($out === null) return null;

        return $this->fact(
            key: 'derived_date',
            value: $out->format('Y-m-d'),
            wsId: $wsId,
            domain: $anchor['domain'],
            sourceIds: $anchor['source_ids'],
            rule: sprintf('%s %s %s %s (%s)',
                $n, $unit . ($n === 1 ? '' : 's'),
                $dir < 0 ? 'before' : 'after',
                $anchor['date']->format('Y-m-d'), $anchor['label']),
            extra: [
                'formatted'    => $out->format('l, j F Y'),
                'anchor_date'  => $anchor['date']->format('Y-m-d'),
                'anchor_label' => $anchor['label'],
            ],
        );
    }

    /**
     * Resolve the thing being counted from. Either a literal date, or the name
     * of something in this workspace that HAS a date — "24 days before launch"
     * only means something if the workspace knows when the launch is.
     *
     * @return array{date:CarbonImmutable, label:string, domain:string, source_ids:array}|null
     */
    private function resolveAnchorDate(string $raw, int $wsId): ?array
    {
        $raw = trim(preg_replace('/^(?:the\s+)/i', '', $raw));

        if ($d = $this->parseLiteralDate($raw, $wsId)) {
            return ['date' => $d, 'label' => $raw, 'domain' => 'literal', 'source_ids' => []];
        }

        // People do not stop talking at the end of the date. "90 days after 1
        // Oct works out as what?" left the anchor as "1 Oct works out as",
        // which parses as nothing, and the whole expression fell through
        // unresolved — the model then answered the arithmetic itself, which is
        // the outcome this class exists to prevent. Requiring the tail to
        // consist of exactly a date is a bet that the owner phrases the
        // question the way the test script did.
        //
        // So the date is extracted from WITHIN the tail instead. Trailing words
        // are ignored rather than treated as part of the date.
        $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec';
        if (preg_match('/\b(\d{4}-\d{2}-\d{2}'
                     . '|\d{1,2}(?:st|nd|rd|th)?\s+(?:' . $months . ')[a-z]*(?:\s+\d{4})?'
                     . '|(?:' . $months . ')[a-z]*\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?)\b/i',
                       $raw, $m)) {
            if ($d = $this->parseLiteralDate(trim($m[1]), $wsId)) {
                return ['date' => $d, 'label' => trim($m[1]), 'domain' => 'literal', 'source_ids' => []];
            }
        }

        // Not a literal date — look for a commitment in THIS workspace whose
        // title contains it and which carries a deadline. Scoped to the
        // workspace so one tenant can never resolve an anchor from another.
        $needle = trim($raw, " \t.?!\"'");
        if (mb_strlen($needle) < 3) return null;

        $row = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)
            ->where('status', 'active')
            ->whereNotNull('deadline')
            ->where('title', 'like', '%' . $needle . '%')
            ->orderBy('deadline')
            ->first(['id', 'title', 'deadline']);

        if (!$row) return null;

        return [
            'date'       => CarbonImmutable::parse($row->deadline, $this->timezone($wsId)),
            'label'      => $row->title,
            'domain'     => 'sarah_commitments',
            'source_ids' => [(int) $row->id],
        ];
    }

    /**
     * A literal date, with or without a year.
     *
     * When the year is absent the reading is the NEXT occurrence: "17
     * September" said in August 2026 means 2026, said in October 2026 it means
     * 2027. Picking the current year unconditionally would silently produce a
     * date in the past, and a deadline in the past reads as "already missed"
     * rather than "next year", which is the more damaging error of the two.
     */
    private function parseLiteralDate(string $raw, int $wsId): ?CarbonImmutable
    {
        $tz  = $this->timezone($wsId);
        $raw = trim($raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try { return CarbonImmutable::parse($raw, $tz)->startOfDay(); }
            catch (\Throwable) { return null; }
        }

        $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec';
        $hasYear = (bool) preg_match('/\b\d{4}\b/', $raw);

        $ok = preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s+(' . $months . ')[a-z]*(?:\s+(\d{4}))?$/i', $raw)
           || preg_match('/^(' . $months . ')[a-z]*\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?$/i', $raw);
        if (!$ok) return null;

        try {
            $d = CarbonImmutable::parse($raw, $tz)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if (!$hasYear) {
            $today = CarbonImmutable::now($tz)->startOfDay();
            if ($d->lt($today)) $d = $d->addYear();
        }
        return $d;
    }

    /** Whole days from the workspace's today to $date. Negative = in the past. */
    public function daysUntil(string|CarbonImmutable $date, int $wsId): ?int
    {
        try {
            $tz = $this->timezone($wsId);
            $d  = $date instanceof CarbonImmutable ? $date->setTimezone($tz) : CarbonImmutable::parse($date, $tz);
            return CarbonImmutable::now($tz)->startOfDay()->diffInDays($d->startOfDay(), false);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Typographic apostrophes and non-breaking spaces have broken matchers here before. */
    private function normalise(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ',
            str_replace(["\u{2019}", "\u{2018}", "\u{00A0}", "\u{2013}", "\u{2014}"],
                        ["'", "'", ' ', '-', '-'], $s)));
    }

    /** The derived-fact envelope. Every field of the contract, every time. */
    public function fact(string $key, mixed $value, int $wsId, string $domain,
                         array $sourceIds, string $rule, array $extra = []): array
    {
        return array_merge([
            'key'            => $key,
            'value'          => $value,
            'source_domain'  => $domain,
            'source_ids'     => $sourceIds,
            'computed_at'    => CarbonImmutable::now('UTC')->toIso8601String(),
            'rule'           => $rule,
            'workspace_id'   => $wsId,
            'confidence'     => 1.0,     // deterministic computation, never a guess
            'stale_after'    => CarbonImmutable::now('UTC')->addMinutes(5)->toIso8601String(),
            'calc_version'   => self::CALC_VERSION,
        ], $extra);
    }
}

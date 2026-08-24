<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 - finish a plan that is only a list.
 *
 * MEASURED. Executive Planning 25%, with dependencies, critical_path,
 * contingency and completion_criteria all 0/4 across two architectures. The
 * plans she writes are grounded and quantified - "24 drafts", "6 per week clears
 * it in four weeks", "52 recoverable write failures" - but they are numbered
 * ACTION LISTS. No step says what it waits on, none names what cannot slip,
 * none says what happens if a step fails, and none states a finish line anyone
 * could later check.
 *
 * Supplying the material did not fix it: the ordering, predecessors, blockers,
 * throughput and checkable finish lines are all in ExecutiveFrame already and
 * verified present. Restructuring the whole output contract into a typed
 * artifact made things worse (Crisis Command 26% -> 19%, rolled back).
 *
 * So this is a completion pass, not a generation change. It detects STRUCTURAL
 * ABSENCE in a plan and asks for one revision that adds the missing parts from
 * the material already given. Detection is of absence only - it never scores
 * quality, and it never supplies the content itself.
 *
 * COST. One extra call, and only on turns that actually produced a plan. Every
 * other turn is untouched.
 */
class PlanCompletion
{
    /** A reply is a plan if it enumerates steps. Cheap structural test. */
    public function looksLikeAPlan(string $reply): bool
    {
        if (mb_strlen($reply) < 300) return false;
        $numbered = preg_match_all('/(?:^|\s)(?:\d\.|\d\)|step\s+\d)/i', $reply);
        return $numbered >= 3;
    }

    /**
     * Which structural elements are absent.
     *
     * Absence, not quality: each probe asks "is there any sentence doing this
     * job at all?", which is what a completion pass needs to know. Judging how
     * well it is done is the measurement instrument's business, not this class's.
     *
     * @return array<int,string>
     */
    public function missing(string $reply): array
    {
        $r = mb_strtolower($reply);
        $gaps = [];

        if (!preg_match('/\b(depends on|dependent on|once .{0,40} is|after .{0,40} (?:is|are) (?:done|complete|approved)|cannot start until|waits on|prerequisite|blocked by)\b/', $r))
            $gaps[] = 'what each step depends on (no step states a predecessor)';

        if (!preg_match('/\b(critical path|cannot slip|determines the (?:timeline|duration)|long pole|gating|bottleneck that sets)\b/', $r))
            $gaps[] = 'what determines the overall timeline and cannot slip';

        if (!preg_match('/\b(if (?:that|this|it) (?:fails|does not|doesn.t|slips)|if we cannot|fallback|contingency|should that not|plan b|if the retry fails)\b/', $r))
            $gaps[] = 'what happens if a step fails';

        if (!preg_match('/\b(done when|complete when|finished when|success (?:is|means)|reaches 0|reaches zero|measured by .{0,30}(?:count|number)|criterion)\b/', $r))
            $gaps[] = 'a finish line anyone could later check';

        return $gaps;
    }

    /**
     * The length limit the owner actually asked for, or null.
     *
     * Only explicit, checkable limits count. "Briefly" is a preference and is
     * left alone; "under 80 words" is a constraint and can be measured.
     */
    public function requestedWordLimit(string $question): ?int
    {
        $q = mb_strtolower($question);
        if (preg_match('/\b(?:under|below|less than|no more than|max(?:imum)? of|within)\s+(\d{2,4})\s*words?\b/', $q, $m))
            return (int) $m[1];
        if (preg_match('/\b(\d{2,4})\s*words?\s+or\s+(?:less|fewer)\b/', $q, $m))
            return (int) $m[1];
        if (preg_match('/\bone[- ]paragraph\b|\ba single paragraph\b/', $q)) return 120;
        if (preg_match('/\bone[- ]line\b|\bone sentence\b/', $q)) return 30;
        return null;
    }

    /** Words in the reply, counted the way a reader would. */
    public function wordCount(string $reply): int
    {
        return count(preg_split('/\s+/', trim(strip_tags($reply)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Cut an over-long answer to the limit the owner set.
     *
     * Separate from complete() because this is not a missing element - the
     * answer is complete and simply too long, and the revision must remove
     * rather than add. Returns the original on any failure or if the revision
     * still overruns, since a wrong-length correct answer beats a mangled one.
     */
    public function compressToLimit(string $reply, string $question, int $limit, int $wsId = 0): string
    {
        $have = $this->wordCount($reply);
        if ($have <= $limit) return $reply;

        $system = "The owner asked for an answer of at most {$limit} words and received {$have}. "
                . "Rewrite it to fit, in plain prose.\n"
                . "Keep the single most decision-relevant fact and its number; drop supporting detail, "
                . "background and anything they did not ask for. Do not add anything new. Do not use "
                . "headings, bullets or an ellipsis to cheat the count.\n"
                . "Reply with JSON only: {\"reply\":\"<the shortened answer>\"}";

        try {
            $res = app(\App\Connectors\RuntimeClient::class)
                    ->chatJson($system, "QUESTION: {$question}\n\nANSWER TO SHORTEN:\n{$reply}",
                               ['workspace_id' => $wsId], 900);
            if (!($res['success'] ?? false)) return $reply;

            $p = is_array($res['parsed'] ?? null) ? $res['parsed'] : null;
            if ($p === null && preg_match('/\{.*\}/s', (string) ($res['text'] ?? ''), $m)) $p = json_decode($m[0], true);
            $short = trim((string) ($p['reply'] ?? ''));
            if ($short === '') return $reply;

            $now = $this->wordCount($short);
            if ($now > $limit) return $reply;      // no better; keep the correct one
            // MEASURED: a 173-word answer came back as 23 against a 120 limit and lost
            // its actionable close. Undershooting the limit that far is not compression,
            // it is deletion - the owner asked for a shorter answer, not an emptier one.
            if ($now < (int) floor($limit / 2)) {
                Log::info('[Sarah888] compression rejected as over-aggressive', [
                    'ws' => $wsId, 'limit' => $limit, 'before' => $have, 'proposed' => $now,
                ]);
                return $reply;
            }

            Log::info('[Sarah888] answer compressed to the owner\'s limit', [
                'ws' => $wsId, 'limit' => $limit, 'before' => $have, 'after' => $now,
            ]);
            return $short;
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] compression failed: ' . $e->getMessage(), ['ws' => $wsId]);
            return $reply;
        }
    }
    /** An incident answer of any substance, whether or not it enumerates steps. */
    public function looksLikeAnIncidentResponse(string $reply): bool
    {
        return mb_strlen($reply) >= 250;
    }

    /**
     * Structural absences specific to an incident response.
     *
     * Different job from a plan: a plan needs predecessors and a finish line, an
     * incident needs someone on it, a fallback, a route back to normal, and
     * whoever has to be told.
     *
     * @return array<int,string>
     */
    public function missingIncident(string $reply): array
    {
        $r = mb_strtolower($reply);
        $gaps = [];

        if (!preg_match('/\b(james|priya|alex|diana|ryan|sofia|leo|maya|chris|nora|marcus|elena|i will take|i am taking|assign)\b/', $r))
            $gaps[] = 'who specifically is on it, and what they stop doing to take it on';

        if (!preg_match('/\b(if (?:that|this|it) (?:fails|does not|doesn.t)|if the retry|fallback|contingency|should that not work|if we still)\b/', $r))
            $gaps[] = 'what is done if the first response does not work';

        if (!preg_match('/\b(back to normal|normal service|resume|restore(?:d)? (?:output|service)|steady state|recovered when|until .{0,30} clears)\b/', $r))
            $gaps[] = 'how normal operation is restored, not just how the immediate fire is out';

        if (!preg_match('/\b(tell|inform|notify|escalate|let .{0,20} know|the board|the owner|no escalation owner)\b/', $r))
            $gaps[] = 'who has to be told, or that no escalation owner is configured';

        // A response with no rejected alternative is an instruction, not a decision.
        if (!preg_match('/\b(rather than|instead of|as opposed to|the alternative|we could .{0,40} but|considered .{0,30} (?:but|however)|not worth|in preference to|over (?:retrying|publishing|commissioning))\b/', $r))
            $gaps[] = 'what was considered and rejected, and why this response beats it';

        return $gaps;
    }
    /**
     * One revision pass. Returns the original reply unchanged on any failure -
     * a completion that cannot be produced must never cost the owner the answer
     * they already had.
     */
    public function complete(string $reply, string $question, string $material, int $wsId = 0,
                             ?array $gaps = null): string
    {
        $gaps = $gaps ?? $this->missing($reply);
        if (!$gaps) return $reply;

        $system = "You are revising an answer you already wrote. Keep everything that is there: the same steps, "
                . "the same numbers, the same recommendation. Do not restate it differently and do not pad it.\n"
                . "It is missing the following, and each one must come from the MATERIAL below - never invented:\n"
                . "  - " . implode("\n  - ", $gaps) . "\n"
                . "Add them into the existing steps in plain prose. If the material genuinely does not support "
                . "one of them, say so in a short clause rather than inventing it.\n"
                . "Reply with JSON only: {\"reply\":\"<the revised plan>\"}\n\n"
                . "MATERIAL:\n" . $material;

        try {
            $res = app(\App\Connectors\RuntimeClient::class)
                    ->chatJson($system, "QUESTION: {$question}\n\nYOUR ANSWER:\n{$reply}", ['workspace_id' => $wsId], 1800);
            if (!($res['success'] ?? false)) return $reply;

            $p = is_array($res['parsed'] ?? null) ? $res['parsed'] : null;
            if ($p === null && preg_match('/\{.*\}/s', (string) ($res['text'] ?? ''), $m)) $p = json_decode($m[0], true);
            $revised = trim((string) ($p['reply'] ?? ''));

            // A revision that lost material is not a revision.
            if ($revised === '' || mb_strlen($revised) < mb_strlen($reply) * 0.6) return $reply;

            Log::info('[Sarah888] plan completed', [
                'ws' => $wsId, 'gaps' => count($gaps),
                'before' => mb_strlen($reply), 'after' => mb_strlen($revised),
            ]);
            return $revised;
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] plan completion failed: ' . $e->getMessage(), ['ws' => $wsId]);
            return $reply;
        }
    }
}

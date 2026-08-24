<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1G — the Cognitive Frame.
 *
 * Phases 1B–1F each added a block to Sarah's system prompt, and each was
 * written as if it were the only one. Measured on Chef Red they already total
 * ~1,700 tokens with the commitment record EMPTY, and every one of them grows
 * with the workspace: the ledger renders up to 48 task lines, the commitment
 * record up to 40 live items plus cancellations plus full rename and ownership
 * history. On a busy executive workspace that is several thousand tokens paid
 * on every single turn, competing with the instructions and the conversation.
 *
 * This composes them into one frame with a real budget.
 *
 * TRUNCATION IS ALWAYS DISCLOSED. Silently dropping the tail of a list is how
 * "here is everything" becomes a lie — the same failure shape as F1-D01 and
 * F1-D09, arriving by a different route. When something is cut, the frame says
 * what was cut and how much, so Sarah can tell the owner her view is partial
 * rather than presenting a truncated list as complete.
 *
 * ORDER IS THE LOOP'S PRIORITY ORDER, not convenience:
 *   1. horizon      — safety. Prevents denying the owner's reality. Never cut.
 *   2. commitments  — the executive record. The reason this system exists.
 *   3. ledger       — what was actually done, and what it cost.
 *   4. delegations  — who was genuinely consulted.
 */
class CognitiveFrame
{
    /**
     * Total character budget for the Sarah888 frame. ~2,900 tokens.
     *
     * This was 10,000, sized for four blocks. Two mandatory blocks were added
     * — the temporal anchor and derived state, together about 1,200 characters
     * — and at 101 live commitments the frame promptly saturated: the ledger
     * lost 1,362 characters and the delegation block was dropped in full.
     *
     * The cap is raised by roughly what the new blocks cost, and no further.
     * The discretionary space available to commitments, ledger and delegations
     * is therefore unchanged from before; the increase buys exactly the two
     * sections that must be exact on every turn and nothing else. Raising it
     * further would be paying tokens on every turn to avoid choosing what
     * matters, which is what relevance-aware compaction is for.
     */
    public const BUDGET_CHARS = 11_500;

    /**
     * Held back so an omission notice can always be written. Without it the
     * notice was appended after the budget was already spent and the frame
     * overran its own cap by the length of the warning.
     */
    private const NOTICE_RESERVE = 260;

    /** The horizon is never truncated — it is the anti-denial safety rail. */
    // The clock and the computed numbers are the two things that must be
    // exact on every turn. Truncating either would leave the model to infer
    // a date or recount a list — the two failures this frame exists to stop.
    private const NEVER_TRUNCATE = ['temporal', 'derived', 'horizon'];

    public function __construct(
        private TemporalAnchor $temporal,
        private DerivedState $derived,
        private MemoryHorizon $horizon,
        private CommitmentStore $commitments,
        private ActionLedger $ledger,
        private DelegationRecorder $delegations,
    ) {}

    /**
     * @return array{frame:string, blocks:array, truncated:array, chars:int}
     */
    public function build(int $wsId, string $slug = 'sarah', int $budget = self::BUDGET_CHARS, ?string $turnText = null): array
    {
        $blocks = [];
        $blocks['temporal'] = $this->safe(fn () => $this->temporal->render($wsId)
            . $this->turnDateFact($wsId, $turnText), 'temporal', $wsId);
        $blocks['derived']  = $this->safe(fn () => $this->derived->render($wsId), 'derived', $wsId);
        // Built in priority order so the budget is spent on what matters first.
        $blocks['commitments'] = $this->safe(fn () => $this->commitments->renderForPrompt($wsId, 60, $turnText), 'commitments', $wsId);
        $blocks['horizon']     = $this->safe(fn () => $this->horizon->render($wsId, $slug, $blocks['commitments'] !== ''), 'horizon', $wsId);
        $blocks['ledger']      = $this->safe(fn () => $this->ledger->renderForPrompt($wsId), 'ledger', $wsId);
        $blocks['delegations'] = $this->safe(fn () => $this->delegations->recentForPrompt($wsId), 'delegations', $wsId);

        $order = ['temporal', 'derived', 'horizon', 'commitments', 'ledger', 'delegations'];
        $truncated = [];
        $out = '';
        $spent = 0;

        // Reserve what the never-truncate blocks need before spending anything.
        $reserved = 0;
        foreach (self::NEVER_TRUNCATE as $k) $reserved += mb_strlen($blocks[$k] ?? '');

        foreach ($order as $key) {
            $b = $blocks[$key] ?? '';
            if ($b === '') continue;

            if (in_array($key, self::NEVER_TRUNCATE, true)) {
                $out .= $b; $spent += mb_strlen($b); $reserved -= mb_strlen($b);
                continue;
            }

            $available = $budget - $spent - $reserved - self::NOTICE_RESERVE;
            if ($available <= 200) {
                // A block that does not fit is still a block that EXISTS. The
                // original code recorded the omission in the return array and
                // wrote nothing into the frame, so the model was never told.
                // Adding the temporal and derived blocks pushed delegations
                // over the edge and it vanished in silence — which is the exact
                // failure this class documents itself as preventing. An absent
                // section that announces its own absence costs one line; one
                // that does not turns "I have no record of any consultation"
                // into a confident falsehood.
                $truncated[$key] = ['kept' => 0, 'dropped' => mb_strlen($b)];
                $notice = "[" . strtoupper($key) . " OMITTED THIS TURN — "
                      . number_format(mb_strlen($b)) . " characters of real records exist in this\n"
                      . "section and could not fit. Do NOT treat this as empty. Say you cannot see it\n"
                      . "this turn and offer to look.]\n\n";
                $out .= $notice;
                $spent += mb_strlen($notice);   // charge what was written, not a guess
                continue;
            }
            if (mb_strlen($b) <= $available) {
                $out .= $b; $spent += mb_strlen($b);
                continue;
            }

            // Cut on a line boundary so a half-rendered commitment never reads
            // as a whole one, then say plainly that it was cut.
            $kept = mb_substr($b, 0, $available - 180);
            $lastNl = mb_strrpos($kept, "\n");
            if ($lastNl !== false && $lastNl > 200) $kept = mb_substr($kept, 0, $lastNl);
            $dropped = mb_strlen($b) - mb_strlen($kept);
            $kept .= "\n  … this section was TRUNCATED to fit the context budget ("
                   . number_format($dropped) . " characters not shown).\n"
                   . "  Your view of it is PARTIAL. Say so rather than presenting this as the complete list.\n\n";
            $out .= $kept;
            $spent += mb_strlen($kept);
            $truncated[$key] = ['kept' => mb_strlen($kept), 'dropped' => $dropped];
        }

        if ($truncated) {
            Log::info('[Sarah888] cognitive frame truncated', [
                'ws' => $wsId, 'budget' => $budget, 'chars' => $spent, 'truncated' => $truncated,
            ]);
        }

        return [
            'omitted' => $this->commitments->lastRenderStats ?? [],
            'frame' => $out,
            'blocks' => array_map(fn ($b) => mb_strlen($b), $blocks),
            'truncated' => $truncated,
            'chars' => mb_strlen($out),
        ];
    }

    /**
     * Calendar arithmetic the owner asked for, done in code.
     *
     * "What date is 24 days before 17 September?" is a question with exactly
     * one right answer, and a language model reaches it by pattern-matching
     * rather than by counting days. It is right often enough to be trusted and
     * wrong often enough to be dangerous, which is the worst combination for a
     * number an executive will act on.
     *
     * So the expression is detected and computed before the model sees the
     * turn, and the answer is handed to it as a fact. When no expression is
     * present this returns an empty string and costs nothing. When one is
     * present but cannot be parsed it ALSO returns nothing — an unresolved
     * expression must fall through to the model rather than be answered with a
     * date that was guessed by a different mechanism.
     */
    private function turnDateFact(int $wsId, ?string $turnText): string
    {
        if (!$turnText || !preg_match('/\b\d{1,4}\s+(?:day|week|month|year)s?\s+(?:before|after|from|prior|ahead)\b/i', $turnText)) {
            return '';
        }
        $fact = $this->temporal->resolveExpression($turnText, $wsId);
        if (!$fact) return '';

        return "  COMPUTED FOR THIS TURN: {$fact['rule']} = {$fact['value']} ({$fact['formatted']}).\n"
             . "  This was calculated, not recalled. State it exactly.\n\n";
    }
    /** One block failing must never cost Sarah the other three. */
    private function safe(callable $fn, string $name, int $wsId): string
    {
        try {
            return (string) $fn();
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] frame block failed', [
                'ws' => $wsId, 'block' => $name, 'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}

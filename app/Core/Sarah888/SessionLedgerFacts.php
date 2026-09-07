<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * RISK-0143 (2026-09-07, DEC-0042) — Sarah's ONE authoritative source for "did the strategy session run, and what did
 * it cost me". Before this, her read-back reasoned from the plan tasks a completed session had produced (five pending
 * rows worth 8 credits) and told a customer "there's no completed strategy session on record … the 8 credits won't be
 * charged until the tasks execute" while the ledger held the commit and the meeting was closed (EV-0922).
 *
 * Authority order, fixed: CREDIT LEDGER (money) → MEETING / PROPOSAL rows (whether the session ran) → TASK table.
 * The plan tasks a session creates are separate, later items; their pending state is never evidence about the session
 * or its charge. A genuine conflict between the ledger and the meeting row is reported as RECONCILING, never guessed.
 *
 * No parallel financial authority: every figure here is read from credit_transactions / credits (via CreditService),
 * meetings and strategy_proposals. Nothing is written.
 */
final class SessionLedgerFacts
{
    public const NOTE = 'Correction from the record';

    private const PROACTIVE_TYPES = ['initial_strategy', 'discovery_strategy_meeting', 'monthly_30_day_plan'];

    /** The turn is about a session, its outcome, or money — the facts block is rendered only then. */
    public static function relevant(string $message): bool
    {
        return (bool) preg_match('/\b(strateg\w*|sessions?|meetings?|decided?|decision|plans?|proposals?|costs?|costing|charged?|charges|credits?|spend|spent|spending|bill\w*|paid|pay|invoice|approved?|pending|tasks?|queue)\b/iu', $message);
    }

    /**
     * One row per strategy session (a proposal that has a meeting, or a proactive proposal that will get one), newest
     * first. Every field is read from the authoritative rows; `state` is derived in authority order.
     *
     * @return list<array<string,mixed>>
     */
    public static function sessions(int $wsId, int $limit = 6): array
    {
        $props = DB::table('strategy_proposals')->where('workspace_id', $wsId)
            ->where(function ($q) { $q->whereNotNull('meeting_id')->orWhereIn('type', self::PROACTIVE_TYPES); })
            ->whereNotIn('status', ['superseded', 'declined', 'rejected', 'expired'])
            ->orderByDesc('id')->limit($limit)->get();

        $out = [];
        foreach ($props as $p) {
            $meeting = $p->meeting_id
                ? DB::table('meetings')->where('id', (int) $p->meeting_id)->where('workspace_id', $wsId)->first()
                : null;
            $meta  = $meeting ? (json_decode((string) ($meeting->metadata_json ?? '{}'), true) ?: []) : [];
            $phase = (string) ($meta['phase'] ?? '');
            $ref   = trim((string) ($p->reservation_ref ?? ''));

            $ledger = DB::table('credit_transactions')->where('workspace_id', $wsId)
                ->where(function ($q) use ($ref, $p) {
                    $q->where('reference_type', 'proposal:' . (int) $p->id);
                    if ($ref !== '') $q->orWhere('reservation_reference', $ref);
                })->orderBy('id')->get();

            $reserved = 0; $committed = 0; $released = 0; $commits = 0;
            $reservedAt = null; $chargedAt = null; $releasedAt = null;
            foreach ($ledger as $t) {
                $amt = (int) $t->amount;
                switch ((string) $t->type) {
                    case 'reserve': $reserved += $amt; $reservedAt = $reservedAt ?? (string) $t->created_at; break;
                    case 'commit':
                    case 'debit':   $committed += $amt; $commits++; $chargedAt = (string) ($t->finalized_at ?? $t->created_at); break;
                    case 'release': $released += $amt; $releasedAt = (string) ($t->released_at ?? $t->created_at); break;
                }
            }
            $outstanding = max(0, $reserved - $committed - $released);

            $planTotal = 0; $planPending = 0; $planPendingCredits = 0; $planDone = 0;
            if ($meeting) {
                foreach (DB::table('meeting_tasks')->join('tasks', 'tasks.id', '=', 'meeting_tasks.task_id')
                    ->where('meeting_tasks.meeting_id', (int) $meeting->id)->where('tasks.workspace_id', $wsId)
                    ->get(['tasks.status', 'tasks.credit_cost']) as $t) {
                    $planTotal++;
                    if (in_array((string) $t->status, ['pending', 'queued', 'awaiting_approval', 'running', 'blocked'], true)) {
                        $planPending++; $planPendingCredits += (int) $t->credit_cost;
                    } elseif ((string) $t->status === 'completed') {
                        $planDone++;
                    }
                }
            }

            $meetingDone = $meeting && ((string) $meeting->status === 'closed' || $phase === 'complete');
            $meetingCost = (int) ($meta['credit_cost'] ?? 0);
            if ($committed > 0 && $meetingDone)                                   $state = 'COMPLETED_CHARGED';
            elseif ($committed > 0 && $meeting && !$meetingDone)                  $state = 'RECONCILING';          // charged, meeting not closed
            elseif ($meetingDone && $released > 0 && $committed === 0)             $state = 'COMPLETED_NOT_CHARGED'; // released, nothing charged
            elseif ($meetingDone && $committed === 0 && ($reserved > 0 || $meetingCost > 0)) $state = 'RECONCILING'; // ran, charge not recorded
            elseif ($meetingDone)                                                  $state = 'COMPLETED_FREE';
            elseif ($meeting)                                                      $state = 'IN_PROGRESS';
            elseif ((string) $p->status === 'pending_approval')                    $state = 'AWAITING_APPROVAL';
            elseif (in_array((string) $p->status, ['approved', 'executing'], true)) $state = 'APPROVED_NOT_STARTED';
            elseif ((string) $p->status === 'failed')                              $state = 'FAILED';
            else                                                                   $state = 'NOT_STARTED';

            $out[] = [
                'proposal_id'      => (int) $p->id,
                'title'            => (string) ($p->title ?? 'Strategy session'),
                'type'             => (string) $p->type,
                'proposal_status'  => (string) $p->status,
                'approved_at'      => $p->approved_at ? (string) $p->approved_at : null,
                'quoted_credits'   => (int) ($p->total_credits ?? 0),
                'meeting_id'       => $meeting ? (int) $meeting->id : null,
                'meeting_status'   => $meeting ? (string) $meeting->status : null,
                'phase'            => $phase,
                'closed_at'        => $meetingDone ? (string) ($meeting->updated_at ?? '') : null,
                'reserved'         => $reserved,
                'reserved_at'      => $reservedAt,
                'committed'        => $committed,
                'commit_count'     => $commits,
                'charged_at'       => $chargedAt,
                'released'         => $released,
                'released_at'      => $releasedAt,
                'outstanding'      => $outstanding,
                'plan_tasks'       => $planTotal,
                'plan_pending'     => $planPending,
                'plan_pending_credits' => $planPendingCredits,
                'plan_done'        => $planDone,
                'state'            => $state,
            ];
        }
        return $out;
    }

    /** Ledger-level spend for the workspace: pool-resolved balance, what is held, and every charge (newest first). */
    public static function spend(int $wsId, int $limit = 10): array
    {
        $balance = 0; $held = 0;
        try {
            $b = app(\App\Core\Billing\CreditService::class)->getBalance($wsId);
            $balance = (int) ($b['balance'] ?? 0);
            $held    = (int) ($b['reserved'] ?? ($b['reserved_balance'] ?? 0));
        } catch (\Throwable $e) {
            $row = DB::table('credits')->where('workspace_id', $wsId)->first();
            $balance = (int) ($row->balance ?? 0); $held = (int) ($row->reserved_balance ?? 0);
        }
        $q = DB::table('credit_transactions')->where('workspace_id', $wsId)->whereIn('type', ['commit', 'debit']);
        $total = (int) (clone $q)->sum('amount');
        $today = (int) (clone $q)->where('created_at', '>=', now()->startOfDay())->sum('amount');
        $charges = [];
        foreach ((clone $q)->orderByDesc('id')->limit($limit)->get() as $t) {
            $charges[] = ['amount' => (int) $t->amount, 'label' => self::label($t), 'at' => (string) ($t->finalized_at ?? $t->created_at)];
        }
        $open = [];
        foreach (DB::table('credit_transactions')->where('workspace_id', $wsId)->where('type', 'reserve')
            ->whereIn('reservation_status', ['pending', 'reserved', 'held'])->orderByDesc('id')->limit($limit)->get() as $t) {
            $open[] = ['amount' => (int) $t->amount, 'label' => self::label($t), 'at' => (string) $t->created_at];
        }
        return ['balance' => $balance, 'held' => $held, 'charged_total' => $total, 'charged_today' => $today, 'charges' => $charges, 'open_reservations' => $open];
    }

    /** Plain-English label of a ledger row from its own reference, never from a guess. */
    private static function label(object $t): string
    {
        $rt = (string) ($t->reference_type ?? '');
        if (preg_match('/^proposal:(\d+)$/', $rt, $m)) {
            $title = (string) (DB::table('strategy_proposals')->where('id', (int) $m[1])->value('title') ?? 'strategy session');
            return "{$title} (proposal #{$m[1]})";
        }
        if (strcasecmp($rt, 'Task') === 0 && !empty($t->reference_id)) {
            $task = DB::table('tasks')->where('id', (int) $t->reference_id)->first(['action', 'engine']);
            $what = $task ? str_replace('_', ' ', (string) $task->action) : 'task';
            return "{$what} (task #{$t->reference_id})";
        }
        return $rt !== '' ? str_replace('_', ' ', $rt) : (string) $t->type;
    }

    /** The prompt block. Empty when the turn is not about sessions or money, or the workspace has nothing to say. */
    public static function render(int $wsId, string $message): string
    {
        if (!self::relevant($message)) return '';
        $sessions = self::sessions($wsId);
        $spend    = self::spend($wsId);
        if ($sessions === [] && $spend['charged_total'] === 0 && $spend['open_reservations'] === []) return '';

        $lines   = [];
        $lines[] = 'AUTHORITATIVE SESSION AND SPEND FACTS (computed this turn from the credit ledger and the meeting/proposal records).';
        $lines[] = 'Authority order: LEDGER (money) -> MEETING/PROPOSAL (whether the session ran) -> TASKS. A session\'s plan tasks are separate,';
        $lines[] = 'later items: their pending state NEVER means the session did not run or was not charged. Answer "did the session complete",';
        $lines[] = '"what did it cost", "what have I spent" ONLY from these lines, and quote these figures exactly. Where a line says RECONCILING,';
        $lines[] = 'say the record is being reconciled and do not guess either way.';
        foreach ($sessions as $s) {
            $lines[] = '- ' . self::sentence($s);
        }
        $lines[] = sprintf('Spend: balance %d credits, %d reserved (held, not charged); charged so far %d credits in total, %d today.',
            $spend['balance'], $spend['held'], $spend['charged_total'], $spend['charged_today']);
        if ($spend['charges'] !== []) {
            $lines[] = 'Charges (newest first): ' . implode('; ', array_map(
                fn ($c) => "{$c['amount']} credit" . ($c['amount'] === 1 ? '' : 's') . " - {$c['label']} at " . self::hm($c['at']), $spend['charges'])) . '.';
        }
        if ($spend['open_reservations'] !== []) {
            $lines[] = 'Open reservations (held, not charged): ' . implode('; ', array_map(
                fn ($c) => "{$c['amount']} credit" . ($c['amount'] === 1 ? '' : 's') . " - {$c['label']}", $spend['open_reservations'])) . '.';
        }
        return implode("\n", $lines);
    }

    /** One truthful sentence per session, in the same words the guard uses. */
    public static function sentence(array $s): string
    {
        $head = "Strategy session \"{$s['title']}\" (proposal #{$s['proposal_id']}, proposal status {$s['proposal_status']}"
              . ($s['approved_at'] ? ', approved ' . self::hm($s['approved_at']) : ', not approved') . '): ';
        $plan = $s['plan_tasks'] > 0
            ? " It produced {$s['plan_tasks']} plan task" . ($s['plan_tasks'] === 1 ? '' : 's') . " ({$s['plan_pending']} still pending, worth {$s['plan_pending_credits']} credit"
              . ($s['plan_pending_credits'] === 1 ? '' : 's') . ' NOT yet charged - separate items, not part of the session charge).'
            : '';
        switch ($s['state']) {
            case 'COMPLETED_CHARGED':
                return $head . "COMPLETED - meeting #{$s['meeting_id']} closed " . self::hm($s['closed_at']) . "; CHARGED {$s['committed']} credit" . ($s['committed'] === 1 ? '' : 's')
                    . ' exactly once (ledger commit ' . self::hm($s['charged_at']) . '); nothing further is owed for it.' . $plan;
            case 'COMPLETED_NOT_CHARGED':
                return $head . "COMPLETED - meeting #{$s['meeting_id']} closed " . self::hm($s['closed_at']) . "; NOT charged (the {$s['released']}-credit reservation was released " . self::hm($s['released_at']) . ').' . $plan;
            case 'COMPLETED_FREE':
                return $head . "COMPLETED - meeting #{$s['meeting_id']} closed " . self::hm($s['closed_at']) . '; no credits were involved.' . $plan;
            case 'IN_PROGRESS':
                return $head . "IN PROGRESS, not completed - meeting #{$s['meeting_id']}" . ($s['phase'] !== '' ? " at phase '{$s['phase']}'" : '')
                    . "; {$s['outstanding']} credit" . ($s['outstanding'] === 1 ? '' : 's') . ' reserved (held), NOTHING charged for it yet; it is charged once when the meeting completes.';
            case 'RECONCILING':
                return $head . 'RECONCILING - the meeting record says ' . ($s['meeting_status'] ?? 'no meeting') . ($s['phase'] !== '' ? " (phase '{$s['phase']}')" : '')
                    . " while the ledger shows reserved {$s['reserved']}, charged {$s['committed']}, released {$s['released']}. Say the record is being reconciled; do not state it as completed or as not charged.";
            case 'AWAITING_APPROVAL':
                return $head . "AWAITING YOUR APPROVAL - not started, nothing reserved or charged (quoted {$s['quoted_credits']} credits).";
            case 'APPROVED_NOT_STARTED':
                return $head . 'APPROVED, meeting not started yet' . ($s['outstanding'] > 0 ? "; {$s['outstanding']} credits reserved (held), nothing charged" : '; nothing charged') . '.';
            case 'FAILED':
                return $head . 'FAILED to start' . ($s['released'] > 0 ? "; the {$s['released']}-credit reservation was released" : '') . '; nothing charged.';
            default:
                return $head . 'NOT STARTED; nothing reserved or charged.';
        }
    }

    /**
     * Post-reply check. A completed, charged session is never denied; a session that is still running is never called
     * done or charged; a RECONCILING record is never resolved by the model. The correction is appended in full view.
     */
    public static function guard(string $reply, int $wsId): string
    {
        if (!preg_match('/\b(session|meeting|strateg\w*|credits?|charged?|spend|spent|cost|costs)\b/iu', $reply)) return $reply;
        $sessions = self::sessions($wsId, 3);
        if ($sessions === []) return $reply;
        $s = $sessions[0];
        $spend = self::spend($wsId);

        $deniesCompletion = (bool) preg_match('/\b(no|not a|isn\'?t a|there\'?s no|there is no|hasn\'?t been a|has not been a|never had a|without a)\s+(completed|finished|concluded)\s+(strategy\s+)?(session|meeting)\b/iu', $reply)
            || (bool) preg_match('/\b(session|meeting)\b[^.!?\n]{0,90}\b(has ?n\'?t|has not|hasn\'?t|have not|haven\'?t|not yet|never|didn\'?t|did not)\s+(been\s+)?(run|ran|completed|complete|finished|happened|taken place|started|concluded|wrapped)\b/iu', $reply)
            || (bool) preg_match('/\b(no|nothing)\s+(completed|finished)\b[^.!?\n]{0,40}\b(session|meeting)\b/iu', $reply);
        $deniesCharge = (bool) preg_match('/\b(won\'?t|will not|wouldn\'?t|not going to)\s+be\s+charged\b/iu', $reply)
            || (bool) preg_match('/\b(has ?n\'?t|hasn\'?t|has not|have not|haven\'?t|not|nothing)\s+(yet\s+)?(been\s+)?(charged|deducted|billed|taken)\b/iu', $reply)
            || (bool) preg_match('/\b(until|once|when)\s+(the\s+)?(tasks?|work|items?)\s+(actually\s+)?(run|runs|execute|executes|complete|completes)\b/iu', $reply);
        $claimsDone = (bool) preg_match('/\b(session|meeting)\b[^.!?\n]{0,60}\b(is complete|has completed|completed|finished|concluded|is done|wrapped up)\b/iu', $reply)
            || (bool) preg_match('/\b(completed|finished|concluded)\s+(the\s+|your\s+)?(strategy\s+)?(session|meeting)\b/iu', $reply);
        $claimsCharged = (bool) preg_match('/\b(charged|deducted|billed)\s+(you\s+)?\d+\s+credits?\b/iu', $reply)
            || (bool) preg_match('/\b\d+\s+credits?\s+(were|was|has been|have been|got)\s+(charged|deducted|billed)\b/iu', $reply);
        $spendClaim = null;
        if (preg_match('/\b(spend|spent|cost|charged)[^.!?\n]{0,40}?\bis\s+(\d+)\s+credits?\b/iu', $reply, $m)) $spendClaim = (int) $m[2];
        elseif (preg_match('/\b(spent|charged)\s+(a\s+total\s+of\s+)?(\d+)\s+credits?\b/iu', $reply, $m)) $spendClaim = (int) $m[3];

        $tail = sprintf(' Charged spend so far: %d credit%s; balance %d credit%s, %d reserved.', $spend['charged_total'], $spend['charged_total'] === 1 ? '' : 's',
            $spend['balance'], $spend['balance'] === 1 ? '' : 's', $spend['held']);

        switch ($s['state']) {
            case 'COMPLETED_CHARGED':
                if ($deniesCompletion || $deniesCharge || ($spendClaim !== null && $spendClaim !== $spend['charged_total'])) {
                    return rtrim($reply) . "\n\n" . self::NOTE . ': ' . self::sentence($s) . $tail;
                }
                return $reply;
            case 'IN_PROGRESS':
            case 'APPROVED_NOT_STARTED':
            case 'AWAITING_APPROVAL':
                if ($claimsDone || $claimsCharged || ($spendClaim !== null && $spendClaim !== $spend['charged_total'])) {
                    return rtrim($reply) . "\n\n" . self::NOTE . ': ' . self::sentence($s) . $tail;
                }
                return $reply;
            case 'RECONCILING':
                if ($deniesCompletion || $deniesCharge || $claimsDone || $claimsCharged) {
                    return rtrim($reply) . "\n\n" . self::NOTE . ': ' . self::sentence($s) . $tail;
                }
                return $reply;
            default:
                if ($spendClaim !== null && $spendClaim !== $spend['charged_total']) {
                    return rtrim($reply) . "\n\n" . self::NOTE . ':' . $tail;
                }
                return $reply;
        }
    }

    private static function hm(?string $ts): string
    {
        if (!$ts) return 'time unknown';
        try { return \Carbon\Carbon::parse($ts)->format('H:i') . ' UTC ' . \Carbon\Carbon::parse($ts)->format('Y-m-d'); } catch (\Throwable $e) { return (string) $ts; }
    }
}

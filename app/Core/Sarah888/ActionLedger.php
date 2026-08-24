<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 Phase 1D slice 1D.2 — action ledger.
 *
 * Traces to F1-D09. Asked "has anything you've done today changed data in this
 * workspace?" Sarah answered: "No — nothing I've done today has changed any
 * data… The team did complete 4 featured-image generations and there's a
 * calendar event pending, but I didn't initiate or run those."
 *
 * All five rows carried source=agent and were created during that conversation.
 * She caused every one and denied every one, because she had no way to tell her
 * own writes from background activity and answered from recollection instead.
 *
 * WHY THIS IS A VIEW, NOT A NEW TABLE
 * The `tasks` table already records action, status, source, credit_cost,
 * approval state and — crucially — a `created_via` marker that already
 * distinguishes 'sarah_chat' and 'sarah_router' from 'fill_missing_images' and
 * 'auto_orphan_rescue'. A parallel ledger table would be a second source of
 * truth about the same events, and the two would drift. The real gap was never
 * missing data; it was that nothing ever showed her the data she already had.
 */
class ActionLedger
{
    // The marker vocabulary below is taken from what production actually
    // writes, not from what the code appears to write. Counting the live rows
    // on Chef Red turned up sarah_proposal with 200 rows — the daily-cycle
    // path — which an assumed list would have silently mis-filed as her own
    // conversational work, reintroducing the very confusion this fixes.

    /** "Sarah did this because the owner asked her to, in conversation." */
    private const CONVERSATIONAL = ['sarah_chat', 'sarah_router', 'sarah_reply'];

    /** "An automation did this. Real work in this workspace, nobody asked in chat." */
    private const AUTOMATED = [
        'sarah_proposal', 'fill_missing_images', 'auto_orphan_rescue',
        'proactive', 'daily_cycle', 'goal_lifecycle', 'scheduled', 'strategy_proposal',
    ];

    /** "The owner or another surface did this." */
    private const OWNER_INITIATED = [
        'manual_direct_assign', 'wsv2_direct_assign', 'review_publish', 'manual',
    ];

    /**
     * @return array{mine:array, automated:array, other:array, pending:array, totals:array}
     */
    /**
     * @param array<string>|null $onlyExecutionIds Restrict to work created by
     *        these executions. Used to answer "what did you change in THIS
     *        conversation", which a 24h window cannot answer honestly.
     */
    public function forWorkspace(
        int $wsId,
        int $sinceMinutes = 1440,
        ?string $executionId = null,
        ?array $onlyExecutionIds = null,
    ): array {
        $rows = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', now()->subMinutes($sinceMinutes))
            ->orderByDesc('id')
            ->limit(120)
            ->get(['id', 'action', 'status', 'source', 'approval_status', 'requires_approval',
                   'credit_cost', 'payload_json', 'created_at', 'error_text']);

        $scope = $onlyExecutionIds === null ? null : array_flip($onlyExecutionIds);

        $mine = []; $automated = []; $other = []; $pending = [];
        $creditsSpent = 0;

        foreach ($rows as $row) {
            $p = is_string($row->payload_json) ? json_decode($row->payload_json, true) : ($row->payload_json ?? []);
            $p = is_array($p) ? $p : [];
            $via   = (string) ($p['created_via'] ?? '');
            $title = (string) ($p['title'] ?? '');
            $cost  = (int) ($row->credit_cost ?? 0);

            if ($scope !== null && !isset($scope[(string) ($p['execution_id'] ?? '')])) continue;

            $entry = [
                'id'      => (int) $row->id,
                'action'  => (string) $row->action,
                'status'  => (string) $row->status,
                'title'   => $title,
                'credits' => $cost,
                'via'     => $via,
                'at'      => (string) $row->created_at,
                'error'   => $row->status === 'failed' ? mb_substr((string) $row->error_text, 0, 120) : null,
                'this_turn' => $executionId !== null && ($p['execution_id'] ?? null) === $executionId,
            ];

            if (in_array($row->status, ['pending', 'awaiting_approval'], true)
                && ((int) ($row->requires_approval ?? 0) === 1 || $row->approval_status === 'pending')) {
                $pending[] = $entry;
                continue;
            }
            if ($row->status === 'completed') $creditsSpent += $cost;

            // Explicit markers win; source is the fallback. An unrecognised
            // marker on an agent row is filed as automation rather than as her
            // own work — attributing an unknown origin to her is the mistake
            // that produced F1-D09 in reverse.
            if ($via !== '' && in_array($via, self::CONVERSATIONAL, true))       $mine[] = $entry;
            elseif ($via !== '' && in_array($via, self::OWNER_INITIATED, true))  $other[] = $entry;
            elseif ($via !== '' && in_array($via, self::AUTOMATED, true))        $automated[] = $entry;
            elseif ((string) $row->source === 'manual')                          $other[] = $entry;
            elseif ((string) $row->source === 'agent')                           $automated[] = $entry;
            else                                                                $other[] = $entry;
        }

        return [
            'mine' => $mine, 'automated' => $automated, 'other' => $other, 'pending' => $pending,
            'totals' => [
                // Counted from what survived the scope filter, not from the raw
                // row set — otherwise a conversation-scoped ledger reports the
                // whole 24h window as its total and the guard below would call
                // a truthful "nothing" a lie.
                'all'            => count($mine) + count($automated) + count($other) + count($pending),
                'mine'           => count($mine),
                'automated'      => count($automated),
                'other'          => count($other),
                'pending'        => count($pending),
                'credits_spent'  => $creditsSpent,
            ],
        ];
    }

    /**
     * The same ledger, restricted to one conversation.
     *
     * "Has anything you've done TODAY changed data?" and "what did you change
     * DURING THIS CONVERSATION?" are different questions, and answering the
     * second from a 24h window is how a truthful ledger still produces a false
     * answer — every background job in the workspace gets swept in.
     *
     * The link is the correlation envelope: every execution stamps its id on
     * both the agent_messages row and the tasks it queues, so the conversation's
     * executions are recoverable without a new column.
     */
    public function forConversation(int $wsId, string $conversationId, int $sinceMinutes = 1440): array
    {
        $execIds = [];
        foreach (DB::table('agent_messages')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', now()->subMinutes($sinceMinutes))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.conversation_id')) = ?", [$conversationId])
            ->orderByDesc('id')->limit(600)
            ->pluck('metadata_json') as $meta) {
            $m = is_string($meta) ? json_decode($meta, true) : $meta;
            $id = is_array($m) ? ($m['execution_id'] ?? null) : null;
            if (is_string($id) && $id !== '') $execIds[$id] = true;
        }
        if (!$execIds) {
            return ['mine' => [], 'automated' => [], 'other' => [], 'pending' => [],
                    'totals' => ['all' => 0, 'mine' => 0, 'automated' => 0, 'other' => 0,
                                 'pending' => 0, 'credits_spent' => 0]];
        }
        return $this->forWorkspace($wsId, $sinceMinutes, null, array_keys($execIds));
    }

    /**
     * The prompt block. Answers "what did you change?" from evidence.
     *
     * Deliberately states the negative case too: an empty ledger must be
     * reportable as "nothing", but a NON-empty ledger must make "nothing"
     * impossible to say — that was the exact shape of F1-D09.
     */
    public function renderForPrompt(int $wsId, int $sinceMinutes = 1440): string
    {
        $l = $this->forWorkspace($wsId, $sinceMinutes);
        $t = $l['totals'];
        if ($t['all'] === 0) {
            return "ACTION LEDGER (last 24h): no tasks were created in this workspace. "
                 . "If asked what you changed, the honest answer is nothing.\n\n";
        }

        $fmt = static function (array $e): string {
            $bits = [$e['status']];
            if ($e['credits'] > 0) $bits[] = $e['credits'] . ' credit' . ($e['credits'] === 1 ? '' : 's');
            if ($e['error']) $bits[] = 'error: ' . $e['error'];
            return '  - #' . $e['id'] . ' ' . $e['action']
                 . ($e['title'] !== '' ? ' "' . mb_substr($e['title'], 0, 60) . '"' : '')
                 . ' — ' . implode(', ', $bits);
        };

        $out = "ACTION LEDGER (last 24h) — when asked what you changed, answer from THIS, not from memory.\n"
             . "You previously told an owner \"nothing I've done today has changed any data\" while five rows you had created sat in this table. Do not do that again.\n";

        $out .= "\nQUEUED BY YOU FROM CONVERSATION (" . $t['mine'] . "):\n";
        $out .= $t['mine'] ? implode("\n", array_map($fmt, array_slice($l['mine'], 0, 15))) . "\n" : "  (none)\n";

        $out .= "\nRUN BY BACKGROUND AUTOMATION — still THIS workspace, but not asked for in chat (" . $t['automated'] . "):\n";
        $out .= $t['automated'] ? implode("\n", array_map($fmt, array_slice($l['automated'], 0, 15))) . "\n" : "  (none)\n";

        if ($t['pending']) {
            $out .= "\nAWAITING THE OWNER'S APPROVAL — created, NOT executed (" . $t['pending'] . "):\n"
                  . implode("\n", array_map($fmt, array_slice($l['pending'], 0, 10))) . "\n";
        }
        if ($t['other']) {
            $out .= "\nNOT YOURS — created by the owner or another surface (" . $t['other'] . "):\n"
                  . implode("\n", array_map($fmt, array_slice($l['other'], 0, 8))) . "\n";
        }

        $out .= "\nCredits spent on completed work in this window: " . $t['credits_spent'] . ".\n"
              . "RULES: never answer \"nothing changed\" while this ledger is non-empty. Never claim work that is not listed here. "
              . "Automation you did not ask for is still worth reporting — say who ran it rather than denying it happened.\n";

        return $out . "\n";
    }
}

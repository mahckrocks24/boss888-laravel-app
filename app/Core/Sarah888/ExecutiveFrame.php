<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 - the material an executive answer is made of.
 *
 * WHAT WAS MEASURED, 2026-08-09/10.
 * Across 29 executive scenarios the same dimensions were absent almost every
 * time: opportunity_cost (27 failures), risk (27), dependencies (25),
 * measurable_outcomes (25), contingencies (15), recovery_strategy (15),
 * operational_awareness (14), stakeholder_impact (13), escalation (12),
 * critical_path (12).
 *
 * THE ARCHITECTURAL CAUSE, COUNTED RATHER THAN SUPPOSED.
 * In the entire 3,100-line chat route, the words "risk", "opportunity cost",
 * "contingency", "critical path", "stakeholder" and "recovery" appear ZERO
 * times; "measurable" and "escalation" appear once each. Sarah was not failing
 * to follow an executive framework - no framework existed. CognitiveFrame owns
 * STATE (time, derived counts, conversation horizon, what absence means).
 * Nothing owned the OBLIGATIONS of an executive answer, so they were absent
 * systematically rather than occasionally.
 *
 * WHY THIS IS NOT A BLOCK OF CANNED TEXT.
 * Telling a model to "mention risks" produces the word "risk" attached to
 * nothing - the WORDING_TRAP control in the measurement instrument exists
 * precisely to catch that, and scores it zero. So this computes the MATERIAL:
 * the real overdue commitment and how late it is, the actual failure count and
 * its trend, the approvals genuinely blocking downstream work, the throughput
 * against the queue, the named owners, the metrics that exist and the metrics
 * that do not. Reasoning has something to bite on, and a reply that names a
 * dimension without using the material still fails the rubric.
 *
 * WHERE A DIMENSION HAS NO MATERIAL, IT SAYS SO. An executive who invents a
 * risk to satisfy a checklist is worse than one who says there is no material
 * risk in view, so absence is reported as absence rather than left blank for
 * the model to fill.
 *
 * COST. Pure aggregate queries against tables already indexed by workspace, no
 * model call, so it can run on every Sarah turn without a latency budget.
 */
class ExecutiveFrame
{
    /**
     * Keep the block bounded; the prompt has many other tenants.
     *
     * Raised from 3400 on 2026-08-10: the frame was emitting exactly 3401 chars
     * and being truncated mid-sentence, severing its own closing paragraph. A
     * budget that silently amputates the material it exists to supply is worse
     * than a slightly larger budget.
     */
    public const BUDGET_CHARS = 10600;

    /**
     * The systems this platform actually has, and what each one owns.
     *
     * MEASURED 2026-08-10. Cross-System Reasoning scored 0/8 dimensions on both
     * scenarios. Asked how work reaches a customer she answered with a textbook
     * marketing workflow - "Writers draft ... Review and Editing ... Approval
     * Process ... Promotion" - naming not one subsystem of the platform she runs.
     * She was not reasoning badly about the architecture; she had never been told
     * it exists. Nothing in the 3,100-line chat route describes the engines.
     *
     * Sourced from CapabilityMapService, the registry the executor itself
     * consults - NOT from tasks.engine, which is planner-supplied and was
     * measured wrong on 2.4% of executions.
     */
    private const SYSTEM_MAP = <<<'MAP'
THE SYSTEMS YOU RUN (authoritative - this is the platform's own capability registry).
  write   - writes and publishes articles: write_article, improve_draft, generate_outline,
            generate_meta, aeo_enrich, publish_article. Publishing is the ONLY step that
            makes anything customer-visible.
  creative- images only: generate_image, generate_image_mini. An article waits on it when
            a featured image is required.
  seo     - analysis and internal linking: deep_audit, link_suggestions, insert_link,
            fix_orphans, add_keyword, keyword_research, serp_analysis. Advisory: it changes
            what should be done, it does not publish.
  crm     - leads only: create_lead, update_lead, move_lead, list_leads, delete_lead.
  builder - website pages: ai_builder_action, update_page.
  marketing / social / calendar - campaigns and automations, posts, events.
  infrastructure - provision_hosting. tasks - retry_blocked.
  runtime - the external AI service every generative action calls. If it degrades,
            everything generative stalls; already-published content is unaffected.
  queue   - workers execute tasks asynchronously; a task can be pending, awaiting
            approval, running, completed, failed or blocked.
  Typical chain: write_article -> generate_meta / aeo_enrich -> generate_image ->
  publish_article. A destructive step (publish, delete) is held for the owner's approval
  before it runs. Reason about THESE systems by name, not about a generic workflow.
MAP;

    public function build(int $wsId): string
    {
        $m = $this->material($wsId);
        if (!$m) return '';

        $out  = "EXECUTIVE MATERIAL (computed from the workspace record this turn - exact, not estimated).\n";
        $out .= "These are the real conditions available to reason from. Use them; do not invent others.\n";
        // Measured 2026-08-10: six scenarios asserted "81 tasks failed" when the
        // count was 125. 81 was not invented - it was TRUE earlier in the same
        // conversation, and she carried it forward from her own previous replies
        // instead of reading the figures she is handed every turn. Accuracy
        // therefore decayed the longer the conversation ran, which is precisely
        // the wrong direction for a long-running executive assistant.
        $out .= "These figures SUPERSEDE any number you or anyone else gave earlier in this\n";
        $out .= "conversation. Workspace state changes between turns, so an earlier figure is\n";
        $out .= "stale by default. If a number you remember disagrees with one below, the one\n";
        $out .= "below is correct and the remembered one must not be repeated.\n\n";

        // Typed facts before prose: a name/unit/period triple cannot be
        // mislabelled by the words next to it, which prose repeatedly was.
        try {
            $typed = app(\App\Core\Sarah888\ExecutiveFacts::class)->render($wsId);
            if ($typed !== '') $out .= $typed . "\n";
        } catch (\Throwable) { /* typed facts are additive */ }

        foreach ($m as $heading => $lines) {
            if (!$lines) continue;
            $out .= strtoupper($heading) . ":\n";
            foreach ($lines as $l) $out .= "  - {$l}\n";
        }

        $out .= "\n" . self::SYSTEM_MAP . "\n";

        $out .= "\nWhat an answer at this level is expected to account for, WHERE THE MATERIAL SUPPORTS IT:\n"
              . "  what could go wrong, what depends on what, what is given up by choosing, how anyone\n"
              . "  would later verify the outcome, what happens if the first move fails, how normal\n"
              . "  operation resumes, who owns it, who must be told, and what determines the timeline.\n"
              . "  Where the material above gives you nothing for one of these, say so plainly -\n"
              . "  \"there is no ranking evidence to judge that\" is a real answer. Inventing a risk,\n"
              . "  a dependency or a number to appear thorough is worse than omitting it.\n";

        return mb_strlen($out) > self::BUDGET_CHARS
            ? mb_substr($out, 0, self::BUDGET_CHARS) . "\n" : $out;
    }

    /**
     * @return array<string, array<int,string>> heading => computed statements
     */
    public function material(int $wsId): array
    {
        $out = ['what the business is trying to achieve' => [],
                'performance this period vs last' => [],
                'decisions waiting on the owner' => [],
                'recovery and contingency' => [],
                'how work is ordered, and what finishes it' => [],
                'what each course of action costs' => [],
                'risks' => [], 'dependencies' => [], 'capacity and cost' => [],
                'ownership' => [], 'what can and cannot be measured' => []];

        // â”€â”€ DECISIONS AWAITING THE OWNER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // ask_clear failed 3/3 on Board-Level Communication. An executive closes
        // with a decision they need; Sarah had no model of what decisions were
        // outstanding, so she closed with nothing or with a procedural "shall I
        // proceed?". These are the real pending decisions, computed - not an
        // WHAT WOULD REVERSE THE DECISION. A recommendation without a condition
        // that retires it cannot be reviewed later; it can only be defended.
        try {
            $balR   = (int) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0);
            $done7R = (int) DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'completed')
                        ->where('created_at', '>', now()->subDays(7))->count();
            $failWR = (int) DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'failed')
                        ->where('created_at', '>', now()->subDays(7))->count();
            $openR  = (int) DB::table('tasks')->where('workspace_id', $wsId)
                        ->whereIn('status', ['pending', 'awaiting_approval'])->count();
            $draftR = (int) DB::table('articles')->where('workspace_id', $wsId)
                        ->where('status', 'draft')->count();

            $trig = [];
            $attempted = $done7R + $failWR;
            if ($attempted > 0) {
                $rate = (int) round(100 * $failWR / $attempted);
                $trig[] = "Failure rate is {$rate}% this week ({$failWR} of {$attempted} attempted). "
                        . 'If a retry does not bring that below half of what it is now, retrying is the '
                        . 'wrong route and the cause has to be found instead.';
            }
            if ($done7R > 0) {
                $trig[] = "Throughput is {$done7R} completions a week. If it drops below that while the "
                        . 'backlog is still ' . $openR . ' items, any date built on the current rate is void.';
            }
            if ($balR > 0) {
                $trig[] = "Credit balance is {$balR}. If it falls below the cost of the work already "
                        . 'committed, new commissioning stops being affordable regardless of which option was chosen.';
            }
            if ($draftR > 0) {
                $trig[] = "There are {$draftR} finished drafts. If they are published and output still does "
                        . 'not recover, the problem is not the publishing bottleneck and the diagnosis was wrong.';
            }

            if ($trig) {
                $out['what would make this the wrong call'] = [];
                $out['what would make this the wrong call'][] =
                    'What would make this the wrong call - state one of these with the recommendation, '
                  . 'so it can be checked later rather than defended:';
                foreach ($trig as $t) $out['what would make this the wrong call'][] = '  ' . $t;
            }
        } catch (\Throwable) { /* reversal triggers are additive */ }
        // instruction to "make an ask".
        try {
            // WHAT EACH COURSE OF ACTION COSTS - the real options in front of the
            // owner, priced from the actual backlog sizes and the platform's own
            // price list, so a trade-off can be decided rather than asserted.
            try {
                $map = app(\App\Core\EngineKernel\CapabilityMapService::class);
                $price = function (string $a) use ($map): int {
                    try { return (int) $map->getCreditCost($a); } catch (\Throwable) { return 0; }
                };
                $balance   = (int) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0);
                $drafts    = (int) DB::table('articles')->where('workspace_id', $wsId)->where('status','draft')->count();
                $awaiting  = (int) DB::table('tasks')->where('workspace_id', $wsId)->where('status','awaiting_approval')->count();
                $failedWk  = (int) DB::table('tasks')->where('workspace_id', $wsId)->where('status','failed')
                                ->where('created_at','>',now()->subDays(7))->count();

                if ($drafts > 0) {
                    $out['what each course of action costs'][] =
                        "Publish the {$drafts} existing drafts: 0 credits (already paid for). "
                      . "This is the only course that turns spent money into customer-visible output.";
                }
                if ($failedWk > 0) {
                    $retry = $failedWk * max(1, $price('write_article'));
                    $out['what each course of action costs'][] =
                        "Retry this week's {$failedWk} failed items: roughly {$retry} credits, and it "
                      . "buys nothing new - it only recovers work already attempted.";
                }
                $newBatch = 10 * max(1, $price('write_article'));
                $out['what each course of action costs'][] =
                    "Commission 10 new articles: about {$newBatch} credits, plus "
                  . (10 * max(1, $price('generate_image_mini'))) . " for their images, and they join a queue "
                  . "that is not currently clearing.";
                if ($awaiting > 0) {
                    $out['what each course of action costs'][] =
                        "Clear the {$awaiting} items awaiting approval: 0 credits, decision time only.";
                }
                $out['what each course of action costs'][] =
                    "Balance is {$balance} credits. Every course above is funded from the same balance, "
                  . "so choosing one is choosing not to fund another this month.";
            } catch (\Throwable) { /* costing is additive */ }

            // HOW WORK IS ORDERED - the skeleton a plan needs: sequence,
            // predecessors, what is blocking, throughput, and the finish line.
            try {
                $drafts    = (int) DB::table('articles')->where('workspace_id',$wsId)->where('status','draft')->count();
                $sched     = (int) DB::table('articles')->where('workspace_id',$wsId)->where('status','scheduled')->count();
                $awaiting  = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','awaiting_approval')->count();
                $blocked   = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','blocked')->count();
                $chained   = (int) DB::table('tasks')->where('workspace_id',$wsId)->whereNotNull('parent_task_id')
                                ->whereIn('status',['pending','awaiting_approval'])->count();
                $done7     = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','completed')
                                ->where('created_at','>',now()->subDays(7))->count();
                $open      = (int) DB::table('tasks')->where('workspace_id',$wsId)
                                ->whereIn('status',['pending','awaiting_approval'])->count();

                $out['how work is ordered, and what finishes it'][] =
                    'Fixed sequence: an article must be written, then have meta/AEO applied, then a featured '
                  . 'image attached, then be published. Nothing reaches a customer before the publish step, '
                  . 'and publish is held for approval. Steps cannot be reordered.';

                if ($chained > 0) {
                    $out['how work is ordered, and what finishes it'][] =
                        "{$chained} pending item(s) are children of another task and cannot start until their "
                      . 'parent completes - those are the real predecessors in any plan.';
                }

                $bl = [];
                if ($awaiting > 0) $bl[] = "{$awaiting} awaiting an approval decision";
                if ($blocked  > 0) $bl[] = "{$blocked} blocked needing intervention";
                if ($bl) {
                    $out['how work is ordered, and what finishes it'][] =
                        'Currently blocking: ' . implode('; ', $bl)
                      . '. Nothing downstream of these moves until they are cleared, so they sit on the critical path.';
                }

                if ($done7 > 0 && $open > 0) {
                    $weeks = round($open / $done7, 1);
                    $out['how work is ordered, and what finishes it'][] =
                        "Throughput is {$done7} completions in the last 7 days against {$open} open items - "
                      . "about {$weeks} weeks to clear at the current rate. Any date in a plan has to respect that, "
                      . 'or say what raises the rate.';
                }

                // Dated checkpoints. A milestone is a checkpoint with a date on it;
                // without one she can only name an end state or invent a date.
                if ($done7 > 0) {
                    $perDay = max(0.1, $done7 / 7);
                    $when   = function (int $items) use ($perDay): string {
                        return now()->addDays((int) ceil($items / $perDay))->toDateString();
                    };
                    $marks = [];
                    if ($awaiting > 0) $marks[] = "{$awaiting} approval decision(s) cleared - "
                        . 'no throughput needed, this is decision time only and can be done today ('
                        . now()->toDateString() . ')';
                    if ($drafts > 0)   $marks[] = "{$drafts} drafts published - " . $when($drafts)
                        . " at the current {$done7}/week";
                    if ($open > 0)     $marks[] = "backlog of {$open} open items cleared - " . $when($open)
                        . ' if nothing new is commissioned';

                    if ($marks) {
                        $out['how work is ordered, and what finishes it'][] =
                            'Dated checkpoints, computed from the measured rate of ' . $done7
                          . ' completions in the last 7 days - use these dates, do not estimate others:';
                        foreach ($marks as $mk)
                            $out['how work is ordered, and what finishes it'][] = '  ' . $mk;
                        $out['how work is ordered, and what finishes it'][] =
                            '  Every date above moves if the rate changes or new work is commissioned. '
                          . 'There is no committed delivery date in the record to check these against.';
                    }
                } else {
                    $out['how work is ordered, and what finishes it'][] =
                        'No completions in the last 7 days, so there is no measured rate and no honest '
                      . 'date can be put on any checkpoint. Say that rather than estimating one.';
                }

                $out['how work is ordered, and what finishes it'][] =
                    "Finish lines that can actually be checked: drafts outstanding reaches 0 (now {$drafts}), "
                  . "scheduled outstanding reaches 0 (now {$sched}), items awaiting approval reaches 0 (now {$awaiting}), "
                  . "blocked reaches 0 (now {$blocked}). Use one of these as the completion criterion - they are "
                  . 'the only ones that can be verified later.';
            } catch (\Throwable) { /* plan shape is additive */ }

            // RECOVERY AND CONTINGENCY - what can actually be done about it.
            $failing = DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'failed')
                ->where('created_at', '>', now()->subDays(7))
                ->select('action', DB::raw('count(*) c'))->groupBy('action')
                ->orderByDesc('c')->limit(4)->get();

            foreach ($failing as $f) {
                $recovered = (int) DB::table('tasks')->where('workspace_id', $wsId)
                    ->where('action', $f->action)->where('status', 'completed')
                    ->where('created_at', '>', now()->subDays(7))->count();
                $line = "{$f->action}: {$f->c} failure(s) this week";
                $line .= $recovered > 0
                    ? ", and {$recovered} of the same action completed in the same period - it is "
                      . "recoverable, so a retry is a legitimate first response."
                    : ", and NONE completed this week - retrying alone is unlikely to fix it; the "
                      . "cause has to be found first.";
                $out['recovery and contingency'][] = $line;
            }

            $blockedNow = (int) DB::table('tasks')->where('workspace_id', $wsId)
                            ->where('status', 'blocked')->count();
            if ($blockedNow > 0) {
                $out['recovery and contingency'][] = "The platform has a retry_blocked action (tasks "
                  . "engine) that can re-drive the {$blockedNow} blocked item(s). That is their recovery path.";
            }

            // Each recovery route priced and timed on the same sources the rest of
            // this frame uses, plus what it gives up. Without the sacrifice there is
            // no trade-off to state - only a preference.
            try {
                $map2   = app(\App\Core\EngineKernel\CapabilityMapService::class);
                $cost2  = function (string $a) use ($map2): int {
                    try { return max(1, (int) $map2->getCreditCost($a)); } catch (\Throwable) { return 1; }
                };
                $done7b = (int) DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'completed')
                            ->where('created_at', '>', now()->subDays(7))->count();
                $failWkB = (int) DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'failed')
                            ->where('created_at', '>', now()->subDays(7))->count();
                $draftsB = (int) DB::table('articles')->where('workspace_id', $wsId)
                            ->where('status', 'draft')->count();
                $blockedB = (int) DB::table('tasks')->where('workspace_id', $wsId)
                            ->where('status', 'blocked')->count();

                // Elapsed time from measured throughput, never asserted.
                $days = function (int $items) use ($done7b): string {
                    if ($items <= 0) return 'no elapsed time';
                    if ($done7b <= 0) return 'no measured throughput, so no honest duration';
                    $d = round($items / max(1, $done7b / 7), 1);
                    return "about {$d} day(s) at the current rate of {$done7b} completions a week";
                };

                $routes = [];
                if ($draftsB > 0) $routes[] =
                    "Publish the {$draftsB} finished drafts: 0 credits, " . $days($draftsB)
                  . ". Gives up nothing except the review time; it does NOT fix the cause of the failures.";
                if ($failWkB > 0) $routes[] =
                    "Retry this week's {$failWkB} failures: about " . ($failWkB * $cost2('write_article'))
                  . ' credits, ' . $days($failWkB) . '. Gives up the credits a second time, and buys nothing '
                  . 'new - and where an action has zero completions this week it is likely to fail again.';
                if ($blockedB > 0) $routes[] =
                    "Re-drive the {$blockedB} blocked item(s) with retry_blocked: 0 credits, "
                  . $days($blockedB) . '. Gives up nothing, but only moves items whose blocker is already gone.';
                $routes[] =
                    'Pause new commissioning until the queue drains: 0 credits, and it stops the backlog '
                  . 'growing. Gives up all new output in the meantime, so published volume falls further '
                  . 'before it recovers.';
                $routes[] =
                    'Commission replacement work instead: about ' . (10 * $cost2('write_article'))
                  . ' credits for ten articles plus ' . (10 * $cost2('generate_image_mini'))
                  . ' for images, ' . $days(10) . '. Gives up the credits AND joins the same queue that is '
                  . 'currently failing, so the same failure rate applies to it. Compare it against the credit figures above rather than assuming it is cheaper.';

                if ($routes) {
                    $out['recovery and contingency'][] = 'What each recovery route costs, how long it takes, '
                      . 'and what it gives up - decide between these rather than asserting one:';
                    foreach ($routes as $r2) $out['recovery and contingency'][] = '  ' . $r2;
                }
            } catch (\Throwable) { /* route costing is additive */ }
            $out['recovery and contingency'][] = "Recovery routes that exist here: retry a failed task, "
              . "re-run a chain from the failed step, publish already-written drafts to restore output, or "
              . "pause new commissioning to let the queue drain. There is NO rollback of a published "
              . "article and no external status page to point anyone at.";

            if (count($failing) === 0 && $blockedNow === 0) {
                $out['recovery and contingency'][] = "Nothing is failing or blocked right now, so there "
                  . "is no live incident to plan recovery for.";
            }

            $awaiting = (int) DB::table('tasks')->where('workspace_id', $wsId)
                          ->where('status', 'awaiting_approval')->count();
            if ($awaiting > 0) {
                $out['decisions waiting on the owner'][] =
                    "{$awaiting} task(s) are held awaiting an approval decision and cannot run until it is given.";
            }

            $props = DB::table('strategy_proposals')->where('workspace_id', $wsId)
                       ->where('status', 'pending_approval')->orderByDesc('id')->limit(4)->get();
            foreach ($props as $p) {
                $cost = (int) $p->total_credits;
                $out['decisions waiting on the owner'][] = 'Proposal #' . $p->id . ': '
                    . mb_substr((string) ($p->description ?: $p->title), 0, 90)
                    . ($cost > 0 ? " - {$cost} credits if approved." : ' - no credit cost.');
            }

            $blocked = (int) DB::table('tasks')->where('workspace_id', $wsId)
                         ->where('status', 'blocked')->count();
            if ($blocked > 0) {
                $out['decisions waiting on the owner'][] =
                    "{$blocked} task(s) are blocked and need an intervention decision before they can move.";
            }

            // Configuration gaps are decisions too - they are things only the
            // owner can settle, and naming them is a legitimate executive ask.
            $goalNoTarget = (int) DB::table('workspace_goals')->where('workspace_id', $wsId)
                              ->whereNull('deleted_at')->whereNull('target_json')->count();
            if ($goalNoTarget > 0) {
                $out['decisions waiting on the owner'][] =
                    "{$goalNoTarget} business objective(s) have no numeric target or deadline set - "
                  . 'without one, progress against them cannot be reported.';
            }
            if (!$out['decisions waiting on the owner']) {
                $out['decisions waiting on the owner'][] =
                    'Nothing is currently waiting on an owner decision. If a reply needs a closing ask, '
                  . 'say plainly that nothing needs deciding right now rather than inventing one.';
            }
        } catch (\Throwable) { /* decision model is additive */ }

        // â”€â”€ PERIOD PERFORMANCE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Board-Level Communication measured 0%: outcome_framing, materiality
        // and risk_disclosed all failed. The cause is structural - an outcome is
        // a CHANGE over a period, and nothing computed one. Without a period
        // model she can only describe the present, which reads as activity
        // rather than performance and is exactly what a board update must not be.
        //
        // Computed from the same tables everything else uses. Deltas only; no
        // interpretation, no target invention.
        try {
            $now = now();
            $thisFrom = (clone $now)->startOfMonth();
            $lastFrom = (clone $thisFrom)->subMonth();

            $count = function (string $table, ?string $status, $from, $to) use ($wsId) {
                $q = DB::table($table)->where('workspace_id', $wsId)
                        ->where('created_at', '>=', $from)->where('created_at', '<', $to);
                if ($status !== null) $q->where('status', $status);
                return (int) $q->count();
            };
            $delta = function (int $now, int $prev): string {
                if ($prev === 0) return $now === 0 ? 'flat (none either period)' : "up from none";
                $d = $now - $prev;
                $pc = (int) round(100 * $d / $prev);
                return ($d > 0 ? "up {$d} (+{$pc}%)" : ($d < 0 ? "down " . abs($d) . " ({$pc}%)" : 'flat'));
            };

            $pubNow  = $count('articles', 'published', $thisFrom, $now);
            $pubPrev = $count('articles', 'published', $lastFrom, $thisFrom);
            $doneNow = $count('tasks', 'completed', $thisFrom, $now);
            $donePrev= $count('tasks', 'completed', $lastFrom, $thisFrom);
            $failNow = $count('tasks', 'failed', $thisFrom, $now);
            $failPrev= $count('tasks', 'failed', $lastFrom, $thisFrom);

            // Published and completed deltas are typed facts above
            // (articles_published, tasks_completed, published_change). Narrating them
            // again is how a month figure became a quarter claim.
            $out['performance this period vs last'][] =
                'The published and completed movements are in the typed facts above - quote them '
              . 'from there with their period.';
            $out['performance this period vs last'][] =
                "Work failed: {$failNow} vs {$failPrev} - " . $delta($failNow, $failPrev)
                . ($failNow > $donePrev * 0.25 && $donePrev > 0 ? ' This is material at board level.' : '');

            try {
                $spendNow  = (float) DB::table('credit_transactions')->where('workspace_id', $wsId)
                    ->where('created_at', '>=', $thisFrom)->where('amount', '<', 0)->sum('amount');
                $spendPrev = (float) DB::table('credit_transactions')->where('workspace_id', $wsId)
                    ->where('created_at', '>=', $lastFrom)->where('created_at', '<', $thisFrom)
                    ->where('amount', '<', 0)->sum('amount');
                $out['performance this period vs last'][] = 'Credits spent: ' . abs((int) $spendNow)
                    . ' this month vs ' . abs((int) $spendPrev) . ' last month - '
                    . $delta((int) abs($spendNow), (int) abs($spendPrev)) . '.';
            } catch (\Throwable) { /* spend history is additive */ }

            $out['performance this period vs last'][] =
                'These are the only outcome deltas that exist. There is no traffic, ranking, '
              . 'conversion or revenue movement to report, so do not imply any.';
        } catch (\Throwable) { /* period model is additive */ }

        // â”€â”€ BUSINESS OBJECTIVES â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // workspace_goals is the authoritative source and it is real: audited
        // 2026-08-10, one active goal on this workspace. Without it Sarah
        // prioritises, trades off and plans against nothing, which is most of
        // what opportunity_cost and measurable_outcomes were failing on.
        //
        // Only fields that actually carry values are stated. target_json,
        // current_state_json and target_deadline are NULL here, and saying so
        // is the honest behaviour - an executive who invents a target is worse
        // than one who reports that no target is configured.
        try {
            $goals = DB::table('workspace_goals')->where('workspace_id', $wsId)
                        ->whereNull('deleted_at')->orderByDesc('priority')->limit(5)->get();
            foreach ($goals as $g) {
                $line = "#{$g->id} [" . ($g->goal_type ?: 'goal') . "] " . ($g->title ?: '(untitled)')
                      . ' - status ' . ($g->status ?: 'unknown');
                if ($g->target_deadline) $line .= ", deadline {$g->target_deadline}";
                $missing = [];
                if (!$g->target_json)        $missing[] = 'no numeric target';
                if (!$g->current_state_json) $missing[] = 'no measured current state';
                if (!$g->target_deadline)    $missing[] = 'no deadline';
                if ($missing) $line .= ' (' . implode('; ', $missing) . ' configured)';
                $out['what the business is trying to achieve'][] = $line;
            }
            if (!$goals->count()) {
                $out['what the business is trying to achieve'][] =
                    'No business objective is configured for this workspace. Say so if asked to '
                  . 'prioritise against one rather than assuming a goal.';
            }
        } catch (\Throwable) { /* objectives are additive */ }

        try {
            // ---- failure and its direction -------------------------------
            $failedAll = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','failed')->count();
            $failed7   = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','failed')
                            ->where('created_at','>',now()->subDays(7))->count();
            $failedPrev = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','failed')
                            ->whereBetween('created_at',[now()->subDays(14), now()->subDays(7)])->count();
            if ($failedAll > 0) {
                $dir = $failed7 > $failedPrev ? 'rising' : ($failed7 < $failedPrev ? 'falling' : 'flat');
                $out['risks'][] = "{$failedAll} tasks have failed in total; {$failed7} in the last 7 days "
                                . "against {$failedPrev} the week before ({$dir}).";
            }

            $blocked = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','blocked')->count();
            if ($blocked > 0) $out['risks'][] = "{$blocked} task(s) are blocked and cannot progress unaided.";

            // ---- the overdue commitment, named, with its age --------------
            try {
                $ds = app(DerivedState::class);
                $overdue = $ds->query('overdue_commitment_count', $wsId);
                $n = (int) ($overdue['value'] ?? 0);
                if ($n > 0) {
                    $first = $overdue['items'][0]['title'] ?? null;
                    $out['risks'][] = $first
                        ? "{$n} commitment(s) overdue; the oldest is \"{$first}\"."
                        : "{$n} commitment(s) are overdue.";
                }
                $active = (int) ($ds->query('active_commitment_count', $wsId)['value'] ?? 0);
                $owners = $ds->query('commitments_by_owner', $wsId);
                if (!empty($owners['top'])) {
                    $out['ownership'][] = "Commitments are held by named owners; {$owners['top']} carries the most.";
                }
                if ($active > 0) $out['ownership'][] = "{$active} commitments are active and attributable.";
            } catch (\Throwable) { /* derived state is additive here */ }

            // ---- what is genuinely blocking downstream work ---------------
            $awaiting = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','awaiting_approval')->count();
            if ($awaiting > 0) {
                $out['dependencies'][] = "{$awaiting} task(s) are waiting on an approval decision before "
                                       . "anything downstream of them can run.";
            }
            $chained = (int) DB::table('tasks')->where('workspace_id',$wsId)->whereNotNull('parent_task_id')
                          ->whereIn('status',['pending','awaiting_approval'])->count();
            if ($chained > 0) {
                $out['dependencies'][] = "{$chained} pending task(s) are children of another task and cannot "
                                       . "start until their parent completes.";
            }

            $drafts = (int) DB::table('articles')->where('workspace_id',$wsId)->where('status','draft')->count();
            $sched  = (int) DB::table('articles')->where('workspace_id',$wsId)->where('status','scheduled')->count();
            if ($drafts + $sched > 0) {
                $out['dependencies'][] = "{$drafts} article(s) sit in draft and {$sched} scheduled - written work "
                                       . "that produces nothing until it is published.";
            }

            // ---- capacity, throughput, and what spending forecloses -------
            $open      = (int) DB::table('tasks')->where('workspace_id',$wsId)
                            ->whereIn('status',['pending','awaiting_approval'])->count();
            $done7     = (int) DB::table('tasks')->where('workspace_id',$wsId)->where('status','completed')
                            ->where('created_at','>',now()->subDays(7))->count();
            if ($open > 0) {
                $weeks = $done7 > 0 ? round($open / $done7, 1) : null;
                $out['capacity and cost'][] = "{$open} task(s) are open against {$done7} completed in the last 7 days"
                    . ($weeks !== null ? " - roughly {$weeks} weeks of queue at current throughput." : '.');
            }
            $credits = (int) (DB::table('credits')->where('workspace_id',$wsId)->value('balance') ?? 0);
            $out['capacity and cost'][] = "Credit balance is {$credits}. Every credit spent on one thing is "
                                        . "not available for another.";

            // ---- the evidence that exists, and the evidence that does not -
            $kw = (int) DB::table('seo_keywords')->where('workspace_id',$wsId)->count();
            $pub = (int) DB::table('articles')->where('workspace_id',$wsId)->where('status','published')->count();
            $out['what can and cannot be measured'][] = "Verifiable now: published articles ({$pub}), task "
                . "completion and failure counts, credit spend, approval decisions.";
            if ($kw === 0) {
                $out['what can and cannot be measured'][] = "NOT available: rankings, impressions, clicks, traffic, "
                    . "conversions or revenue - no keywords are tracked and Search Console is not connected. "
                    . "Any claim about search performance would be unfounded.";
            }

            // MISSING AUTHORITATIVE SOURCES, stated as facts rather than filled in.
            // Audited 2026-08-10: project_kpis and project_milestones hold zero rows,
            // and workspace_users holds one (the owner). Naming the gap is the
            // enterprise behaviour; inventing an escalation path or a definition of
            // done to satisfy a question is not.
            $kpis = 0; $miles = 0; $members = 0;
            try { $kpis    = (int) DB::table('project_kpis')->count(); } catch (\Throwable) {}
            try { $miles   = (int) DB::table('project_milestones')->count(); } catch (\Throwable) {}
            try { $members = (int) DB::table('workspace_users')->where('workspace_id',$wsId)->count(); } catch (\Throwable) {}

            if ($kpis === 0 && $miles === 0) {
                $out['what can and cannot be measured'][] = "NO SUCCESS CRITERIA ARE CONFIGURED: there are no KPIs "
                    . "and no milestones recorded. If asked how success would be judged, say that no criteria are "
                    . "defined and what you would propose - do not invent a threshold.";
            }
            if ($members <= 1) {
                $out['ownership'][] = "NO ESCALATION HIERARCHY IS CONFIGURED: this workspace has "
                    . ($members === 1 ? 'a single user (the owner)' : 'no recorded users')
                    . " and no escalation path. If something needs escalating, say plainly that there is no "
                    . "configured escalation owner rather than naming one.";
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_filter($out, fn($v) => !empty($v));
    }
}

<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * Typed incident state, computed before any language is generated.
 *
 * WHY THIS EXISTS.
 * Crisis Command holds at 65% with recovery_strategy, containment and
 * stakeholder_impact repeatedly missed. The material she had was a list of
 * failing actions in prose; deciding severity, what is contained, and what is
 * customer-visible was left to her to infer sentence by sentence, every turn,
 * from the same underlying counts.
 *
 * Incident assessment is derivable. Which actions fail, whether any of them also
 * succeed, whether failures reach the publish step, and whether anything is
 * customer-visible are all facts in the record. They are computed here once, as
 * typed fields, so she reasons about containment and recovery instead of
 * reconstructing them.
 *
 * TWO FIELDS ARE DELIBERATELY NOT COMPUTED. This workspace has no escalation
 * hierarchy and no budget model, so escalation_status and financial_impact_status
 * report NOT_CONFIGURED. They are external business configuration, not
 * engineering gaps, and inventing either would produce an executive answer that
 * names a person who does not exist or a cost nobody agreed.
 */
class ExecutiveIncidentState
{
    /** Publishing is the only customer-visible step in this platform. */
    private const CUSTOMER_VISIBLE = ['publish_article'];

    public function assess(int $wsId): array
    {
        $t  = fn() => DB::table('tasks')->where('workspace_id', $wsId);
        $wk = now()->subDays(7);

        $failed    = (int) (clone $t())->where('status', 'failed')->where('created_at', '>', $wk)->count();
        $completed = (int) (clone $t())->where('status', 'completed')->where('created_at', '>', $wk)->count();
        $blocked   = (int) (clone $t())->where('status', 'blocked')->count();
        $attempted = $failed + $completed;
        $rate      = $attempted > 0 ? (int) round(100 * $failed / $attempted) : null;

        // Per-action recovery evidence: an action that also completes is
        // recoverable by retry; one that never completes is not.
        $byAction = [];
        foreach ((clone $t())->where('status', 'failed')->where('created_at', '>', $wk)
                   ->selectRaw('action, count(*) c')->groupBy('action')->orderByDesc('c')->get() as $r) {
            $ok = (int) (clone $t())->where('action', $r->action)->where('status', 'completed')
                     ->where('created_at', '>', $wk)->count();
            $byAction[(string) $r->action] = ['failed' => (int) $r->c, 'completed' => $ok];
        }

        $unrecovered = array_keys(array_filter($byAction, fn($v) => $v['completed'] === 0));
        $recovering  = array_keys(array_filter($byAction, fn($v) => $v['completed'] > 0));

        // Customer-visible only if the failures reach the publishing step.
        $visible = array_intersect(array_keys($byAction), self::CUSTOMER_VISIBLE);

        // Severity from measured impact, not from adjectives.
        $severity = 'NONE';
        if ($failed > 0) {
            $severity = 'LOW';
            if ($rate !== null && $rate >= 25) $severity = 'MEDIUM';
            if ($rate !== null && $rate >= 40 && $unrecovered) $severity = 'HIGH';
            if ($visible) $severity = 'HIGH';
        }

        // Contained when nothing is still failing without also succeeding.
        $containment = $failed === 0 ? 'NO_ACTIVE_INCIDENT'
                     : ($unrecovered ? 'NOT_CONTAINED' : 'PARTIALLY_CONTAINED');

        $capabilities = array_values(array_unique(array_map(
            fn($a) => match (true) {
                str_contains($a, 'article') || str_contains($a, 'meta') || str_contains($a, 'outline') => 'content production',
                str_contains($a, 'image')   => 'creative',
                str_contains($a, 'audit') || str_contains($a, 'serp') => 'SEO analysis',
                str_contains($a, 'lead')    => 'CRM',
                default                     => $a,
            }, array_keys($byAction))));

        return [
            'incident'                => $failed > 0 ? 'ELEVATED_TASK_FAILURE' : 'NONE',
            'severity'                => $severity,
            'severity_basis'          => $rate === null ? 'no work attempted this week'
                                          : "{$failed} of {$attempted} attempts failed this week ({$rate}%)",
            'affected_actions'        => $byAction,
            'affected_capabilities'   => $capabilities,
            'customer_visible_impact' => $visible
                                          ? 'YES - publishing is affected, so output reaching customers is impacted'
                                          : 'NO - no failures at the publish step; already-published content is unaffected',
            'failed_actions'          => $failed,
            'successful_actions'      => $completed,
            'containment_state'       => $containment,
            'not_recovering'          => $unrecovered,
            'recovering'              => $recovering,
            // Name WHICH actions a retry is justified for. Claiming retry is
            // evidence-backed while six of seven actions never complete would
            // contradict immediate_risk in the same block.
            'recovery_evidence'       => $recovering
                                          ? 'retry is evidence-backed ONLY for: ' . implode(', ', $recovering)
                                            . '. The rest have zero completions this week and need the cause found first.'
                                          : 'no failing action has completed this week; retry alone is not evidence-backed',
            'blocked_items'           => $blocked,
            'immediate_risk'          => $unrecovered
                                          ? 'work in ' . implode(', ', $unrecovered) . ' will keep failing until the cause is found'
                                          : 'failures are recoverable by retry at the measured rate',
            'next_safe_action'        => $unrecovered
                                          ? 'diagnose the zero-completion actions before spending credits on retries'
                                          : ($blocked > 0 ? 're-drive the blocked items via retry_blocked'
                                                          : 'retry the recoverable failures'),
            'escalation_status'       => 'NOT_CONFIGURED',
            'financial_impact_status' => 'NOT_CONFIGURED',
        ];
    }

    public function render(int $wsId): string
    {
        $s = $this->assess($wsId);
        if ($s['incident'] === 'NONE') {
            return "INCIDENT STATE: NONE. Nothing is failing this week, so there is no live incident "
                 . "to command. Do not manufacture one.\n";
        }

        $out  = "INCIDENT STATE - computed from the record before any judgement.\n";
        $out .= "  incident                : {$s['incident']}\n";
        $out .= "  severity                : {$s['severity']} ({$s['severity_basis']})\n";
        $out .= "  affected_capabilities   : " . implode(', ', $s['affected_capabilities']) . "\n";
        $out .= "  customer_visible_impact : {$s['customer_visible_impact']}\n";
        $out .= "  failed_actions          : {$s['failed_actions']} this week\n";
        $out .= "  successful_actions      : {$s['successful_actions']} this week\n";
        $out .= "  containment_state       : {$s['containment_state']}\n";
        foreach ($s['affected_actions'] as $a => $v)
            $out .= "    - {$a}: {$v['failed']} failed, {$v['completed']} completed"
                  . ($v['completed'] === 0 ? '  <- NOT RECOVERING' : '  <- recoverable') . "\n";
        $out .= "  recovery_evidence       : {$s['recovery_evidence']}\n";
        $out .= "  blocked_items           : {$s['blocked_items']}\n";
        $out .= "  immediate_risk          : {$s['immediate_risk']}\n";
        $out .= "  next_safe_action        : {$s['next_safe_action']}\n";
        $out .= "  escalation_status       : NOT_CONFIGURED - this workspace has no escalation\n"
              . "                            hierarchy. Say so; do not name a person or a rota.\n";
        $out .= "  financial_impact_status : NOT_CONFIGURED - no budget or revenue-per-article\n"
              . "                            model exists. Say so; do not estimate a cost.\n";

        return $out;
    }
}

<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-24 FIX 48 — Proactive rule set.
 *
 * Deterministic SQL+threshold rules that emit STRUCTURED CANDIDATE
 * proposals from the workspace's state. These candidates are fed into
 * the runtime synthesis payload so the LLM has concrete, real
 * data-driven options to select from when composing the morning brief.
 *
 * Architecture clarity:
 *   - This is NOT intelligence/synthesis. Each rule is:
 *       SELECT or COUNT + simple threshold + emit candidate.
 *   - The runtime LLM still picks WHICH candidates to surface, narrates
 *     them, decides priority, builds the brief.
 *   - These rules just seed the candidate pool. Like a checklist a
 *     human DMM would run through before opening the laptop.
 *
 * Rules emit candidates of shape:
 *   {
 *     "action": "fix_orphans|refresh_stale|target_opportunity_kw|...",
 *     "priority_hint": "high|medium|low",
 *     "estimated_credits": 0|1|2|3|...,
 *     "evidence": "structured data — count, slug, rank, etc."
 *   }
 */
class ProactiveRuleSet
{
    /**
     * Run all rules against the workspace state, return all candidate
     * proposals as a flat array.
     */
    public function emit(int $wsId, array $state): array
    {
        $candidates = [];

        // RULE 1 — Orphan pages
        $orphans = (int) ($state['seo']['orphan_pages'] ?? 0);
        if ($orphans > 0) {
            $candidates[] = [
                'action'             => 'fix_orphans',
                'priority_hint'      => $orphans > 10 ? 'high' : 'medium',
                'estimated_credits'  => 0,  // link insertion is free
                'evidence'           => "{$orphans} indexed pages have 0 inbound internal links",
                'title_hint'         => "Fix {$orphans} orphan pages (insert internal links)",
                'agent'              => 'james',
                'rule'               => 'orphan_detection',
            ];
        }

        // RULE 2 — Stale articles (>60 days unmodified, published)
        $stale = $state['seo']['stale_articles'] ?? [];
        foreach (array_slice($stale, 0, 3) as $a) {
            $candidates[] = [
                'action'             => 'refresh_article',
                'priority_hint'      => 'medium',
                'estimated_credits'  => 3,
                'evidence'           => "Article '{$a['title']}' unchanged since {$a['updated_at']}",
                'title_hint'         => "Refresh: {$a['title']}",
                'agent'              => 'priya',
                'rule'               => 'stale_article',
                'params'             => ['article_id' => $a['id']],
            ];
        }

        // RULE 3 — Opportunity-zone keywords (rank #11-30 — one push from page 1)
        $oppKws = $state['seo']['opportunity_keywords'] ?? [];
        foreach (array_slice($oppKws, 0, 5) as $kw) {
            $candidates[] = [
                'action'             => 'target_keyword_article',
                'priority_hint'      => $kw['rank'] <= 20 ? 'high' : 'medium',
                'estimated_credits'  => 3,
                'evidence'           => "Keyword '{$kw['keyword']}' at rank #{$kw['rank']} (volume ~{$kw['volume']})",
                'title_hint'         => "Targeted article for '{$kw['keyword']}'",
                'agent'              => 'priya',
                'rule'               => 'opportunity_zone',
                'params'             => ['target_keyword' => $kw['keyword']],
            ];
        }

        // RULE 4 — Significant rank deltas (>5 positions movement either way)
        $deltas = $state['seo']['rank_deltas_7d'] ?? [];
        foreach (array_slice($deltas, 0, 3) as $d) {
            if (abs($d['delta']) < 5) continue;
            if ($d['delta'] > 0) {
                // Improving — propose amplification
                $candidates[] = [
                    'action'             => 'amplify_winner',
                    'priority_hint'      => 'high',
                    'estimated_credits'  => 3,
                    'evidence'           => "'{$d['keyword']}' moved {$d['from_rank']} → {$d['to_rank']} (+{$d['delta']})",
                    'title_hint'         => "Satellite article supporting '{$d['keyword']}' (winning momentum)",
                    'agent'              => 'priya',
                    'rule'               => 'rank_delta_win',
                ];
            } else {
                // Dropping — propose investigation
                $candidates[] = [
                    'action'             => 'investigate_drop',
                    'priority_hint'      => 'high',
                    'estimated_credits'  => 1,
                    'evidence'           => "'{$d['keyword']}' dropped {$d['from_rank']} → {$d['to_rank']} ({$d['delta']})",
                    'title_hint'         => "Investigate rank drop: '{$d['keyword']}'",
                    'agent'              => 'james',
                    'rule'               => 'rank_delta_loss',
                ];
            }
        }

        // RULE 5 — Missing meta (any article without meta_title or meta_description)
        $missingMeta = (int) ($state['seo']['missing_meta'] ?? 0);
        if ($missingMeta > 0) {
            $candidates[] = [
                'action'             => 'fix_missing_meta',
                'priority_hint'      => 'medium',
                'estimated_credits'  => 1,  // bulk meta generation
                'evidence'           => "{$missingMeta} indexed pages missing meta_description",
                'title_hint'         => "Generate meta descriptions for {$missingMeta} pages",
                'agent'              => 'priya',
                'rule'               => 'missing_meta',
            ];
        }

        // RULE 6 — Thin pages (under 300 words)
        $thin = (int) ($state['seo']['thin_pages'] ?? 0);
        if ($thin > 0) {
            $candidates[] = [
                'action'             => 'expand_thin_pages',
                'priority_hint'      => 'medium',
                'estimated_credits'  => $thin * 3,
                'evidence'           => "{$thin} pages have under 300 words (Google views as thin content)",
                'title_hint'         => "Expand {$thin} thin pages to 800+ words",
                'agent'              => 'priya',
                'rule'               => 'thin_page',
            ];
        }

        // RULE 7 — Stale audit (last audit >7 days)
        $lastAuditAt = $state['seo']['last_audit_at'] ?? null;
        if (!$lastAuditAt || strtotime($lastAuditAt) < strtotime('-7 days')) {
            $candidates[] = [
                'action'             => 'run_audit',
                'priority_hint'      => 'low',
                'estimated_credits'  => 3,
                'evidence'           => $lastAuditAt
                    ? "Last full audit: {$lastAuditAt} (more than 7 days ago)"
                    : "No full audit on record",
                'title_hint'         => 'Run weekly SEO audit',
                'agent'              => 'alex',
                'rule'               => 'stale_audit',
            ];
        }

        // RULE 8/9/10 — REMOVED (launch-scope 2026-07-20). These previously emitted
        // social_create_post→marcus (social cadence), send_email→vera (email dormant)
        // and create_email_sequence→vera (lead nurture) candidates. Social automation,
        // email marketing and email sequences are OUT of the launch product and their
        // agents (marcus, vera) are removed, so these candidates are ELIMINATED — not
        // reassigned — per LaunchScopePolicy and audit Deliverable 4/5. New leads are
        // still surfaced to the owner through Sarah's CRM lead summaries (retained);
        // they are no longer turned into an email-marketing proposal. Do NOT
        // reintroduce a social/email candidate here without a product decision.

        // RULE 11 — Chatbot has unanswered visitor questions
        $unanswered = $state['chatbot']['unanswered_questions'] ?? [];
        if (count($unanswered) >= 2) {
            $sample = mb_substr(implode('; ', array_slice($unanswered, 0, 3)), 0, 200);
            $candidates[] = [
                'action'             => 'create_faq_content',
                'priority_hint'      => 'medium',
                'estimated_credits'  => 3,
                'evidence'           => count($unanswered) . " unanswered visitor questions: {$sample}",
                'title_hint'         => 'Article answering top visitor questions',
                'agent'              => 'priya',
                'rule'               => 'chatbot_gap',
            ];
        }

        // RULE 12 — Pending link suggestions backlog (>50 unprocessed)
        $pendingLinks = (int) ($state['seo']['pending_link_suggestions'] ?? 0);
        if ($pendingLinks > 50) {
            $candidates[] = [
                'action'             => 'apply_link_suggestions',
                'priority_hint'      => 'low',
                'estimated_credits'  => 5,  // estimate ~5 applies per credit batch
                'evidence'           => "{$pendingLinks} link suggestions queued — apply top batch to clear backlog",
                'title_hint'         => 'Apply top batch of pending internal link suggestions',
                'agent'              => 'james',
                'rule'               => 'link_backlog',
            ];
        }

        // RULE 13 — Stuck tasks (in blocked >2 days)
        $stuck = (int) ($state['pipeline']['stuck_tasks'] ?? 0);
        if ($stuck > 0) {
            $candidates[] = [
                'action'             => 'unstick_tasks',
                'priority_hint'      => 'high',
                'estimated_credits'  => 0,
                'evidence'           => "{$stuck} tasks blocked for >2 days — pipeline jam",
                'title_hint'         => "Unstick {$stuck} pipeline tasks",
                'agent'              => 'sarah',
                'rule'               => 'pipeline_jam',
            ];
        }

        // RULE 14 — Burn rate concerns. FIX 54 — three thresholds:
        //   warning_80  → 80-89% of monthly budget consumed (heads-up)
        //   critical_90 → 90%+ consumed (decide top-up or pause)
        //   over        → 100%+ consumed OR pace 15%+ ahead (act now)
        $tier = $state['tier_state'] ?? [];
        $burnStatus = $tier['burn_rate_status'] ?? 'on_track';
        $consumedPct = $tier['consumed_pct'] ?? 0;
        $balance = $tier['credit_balance'] ?? 0;
        $limit = $tier['plan_credit_limit'] ?? 0;
        $daysLeft = $tier['days_left_in_month'] ?? 0;

        if ($burnStatus === 'warning_80') {
            $candidates[] = [
                'action'             => 'budget_warning',
                'priority_hint'      => 'medium',
                'estimated_credits'  => 0,
                'evidence'           => "Used {$consumedPct}% of monthly budget ({$balance}/{$limit} credits left, {$daysLeft} days remaining)",
                'title_hint'         => 'Budget heads-up: 80% consumed',
                'agent'              => 'sarah',
                'rule'               => 'burn_rate_warning_80',
                'params'             => ['consumed_pct' => $consumedPct, 'balance' => $balance, 'days_left' => $daysLeft],
            ];
        } elseif ($burnStatus === 'critical_90') {
            $candidates[] = [
                'action'             => 'budget_critical',
                'priority_hint'      => 'high',
                'estimated_credits'  => 0,
                'evidence'           => "Used {$consumedPct}% of monthly budget — only {$balance} credits left for {$daysLeft} days. Top up or pause non-essential work.",
                'title_hint'         => 'Budget critical: 90% consumed',
                'agent'              => 'sarah',
                'rule'               => 'burn_rate_critical_90',
                'params'             => ['consumed_pct' => $consumedPct, 'balance' => $balance, 'days_left' => $daysLeft],
            ];
        } elseif ($burnStatus === 'over') {
            $candidates[] = [
                'action'             => 'budget_alert',
                'priority_hint'      => 'high',
                'estimated_credits'  => 0,
                'evidence'           => "Credit consumption past pace ({$consumedPct}% used) — top-up or scale down",
                'title_hint'         => 'Budget alert: over consumption pace',
                'agent'              => 'sarah',
                'rule'               => 'burn_rate_over',
                'params'             => ['consumed_pct' => $consumedPct, 'balance' => $balance, 'days_left' => $daysLeft],
            ];
        }

        // RULE 15 — FIX 52 — Goal lifecycle signals.
        // GoalLifecycleService updates workspace_goals.status before this
        // rule set runs. Re-read the status off the row (state['goals']
        // doesn't include status today) and emit course-correct or
        // celebration proposals accordingly.
        foreach ($state['goals'] ?? [] as $goal) {
            try {
                $row = DB::table('workspace_goals')
                    ->where('id', $goal['id'])
                    ->first(['status', 'current_state_json', 'target_deadline']);
                if (!$row) continue;
                $status = (string) $row->status;
                $cur = json_decode($row->current_state_json ?? '{}', true) ?: [];
            } catch (\Throwable $e) {
                continue;
            }

            $progress = $cur['progress_pct'] ?? 0;
            $title = $goal['title'] ?? '?';

            if ($status === 'achieved') {
                $candidates[] = [
                    'action'             => 'celebrate_goal_achieved',
                    'priority_hint'      => 'high',
                    'estimated_credits'  => 0,
                    'evidence'           => "Goal '{$title}' achieved at {$progress}% — celebrate + set next goal",
                    'title_hint'         => "Goal achieved: {$title}",
                    'agent'              => 'sarah',
                    'rule'               => 'goal_achieved',
                    'params'             => ['goal_id' => $goal['id']],
                ];
                continue;
            }

            if ($status === 'at_risk' || $status === 'off_track') {
                $candidates[] = [
                    'action'             => 'goal_pivot',
                    'priority_hint'      => $status === 'off_track' ? 'high' : 'medium',
                    'estimated_credits'  => 3,
                    'evidence'           => "Goal '{$title}' is {$status} at {$progress}% progress (deadline " . ($row->target_deadline ?? 'n/a') . ")",
                    'title_hint'         => "Course-correct: {$title}",
                    'agent'              => 'sarah',
                    'rule'               => 'goal_' . $status,
                    'params'             => [
                        'goal_id'   => $goal['id'],
                        'goal_type' => $goal['type'] ?? null,
                        'progress'  => $progress,
                        'status'    => $status,
                    ],
                ];
            }
        }

        Log::info('[ProactiveRuleSet] emitted candidates', [
            'workspace_id' => $wsId,
            'count'        => count($candidates),
            'rules_fired'  => array_unique(array_column($candidates, 'rule')),
        ]);

        return $candidates;
    }
}

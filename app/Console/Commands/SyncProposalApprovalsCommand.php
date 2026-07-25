<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-06-30 — detection→execution loop (#2).
 *
 * Mirrors approvable strategy_proposals (Sarah's daily/weekly/monthly
 * recommendations) into the `approvals` queue so they surface in the Command
 * Center and can be approved through the existing UI. Also reconciles status so
 * proposals approved/declined elsewhere don't leave a stale pending approval.
 *
 * Idempotent — safe to run on a schedule. One approval row per proposal.
 */
class SyncProposalApprovalsCommand extends Command
{
    protected $signature = 'proposals:sync-approvals {--workspace= : limit to one workspace}';
    protected $description = 'Mirror approvable strategy proposals into the approvals queue + reconcile status';

    /** Informational proposal types that only get acknowledged — never executed, so never queued. */
    private const INFORMATIONAL = [
        'goal_pivot', 'celebrate_goal_achieved', 'budget_warning', 'budget_critical', 'budget_alert',
    ];

    /**
     * 2026-07-07 — bounded-autonomy. These slugs are the safe, reversible,
     * recurring content/SEO work that `sarah:auto-execute` runs on its own,
     * within the standing tier credit budget the owner approved ONCE. They must
     * NEVER surface in the approvals queue — asking for approval every single day
     * for work already cleared is exactly the nag the owner rejected. Only
     * PUBLISHING + external/strategic proposals (everything NOT in this list)
     * still ask. Keep in lockstep with SarahAutoExecuteCommand::BOUNDED_AUTO.
     */
    private const AUTO_SAFE = [
        'write_article', 'insert_link', 'fix_orphans', 'generate_meta',
        'expand_thin_pages', 'apply_link_suggestions', 'link_suggestions',
        'improve_draft', 'generate_image',
    ];

    /**
     * b22 (2026-07-24) — STRATEGY PROPOSALS DO NOT BELONG IN THE REVIEW QUEUE.
     *
     * Boss decision: nothing may sit in the pending approvals queue that Sarah
     * could have raised in chat and had approved in chat. Every proposal this
     * command mirrored was exactly that — daily_action_* / weekly_pivot_* /
     * discovery_strategy_meeting / publish_ready are all surfaced in Sarah's
     * daily brief and are approvable end-to-end through the chat-side path
     * (GET /api/sarah/proposals, POST /api/sarah/proposals/{id}/approve|decline
     * and the batch variants). ProactiveStrategyEngine::approveProposal() works
     * purely off strategy_proposals and never needed an approvals row, so the
     * mirror only ever produced a second inbox showing the same decision twice.
     *
     * It had grown to 84 rows — the entire proposal-backed half of the queue.
     *
     * Steps 2 (reconcile) and 3 (close-out) below are unaffected and still run:
     * they keep any pre-existing mirrored rows consistent and unstick proposals
     * left in 'executing'.
     *
     * Set to true to restore Command-Center mirroring.
     */
    private const MIRROR_TO_APPROVAL_QUEUE = false;

    public function handle(): int
    {
        $wsFilter = $this->option('workspace');
        $created = 0;
        $reconciled = 0;

        // 1) MIRROR — pending, approvable proposals without an approval row yet.
        $proposals = self::MIRROR_TO_APPROVAL_QUEUE
            ? DB::table('strategy_proposals')
                ->where('status', 'pending_approval')
                ->when($wsFilter, fn ($q) => $q->where('workspace_id', (int) $wsFilter))
                ->get()
            : collect();

        foreach ($proposals as $p) {
            // Skip the safe recurring work sarah:auto-execute already handles —
            // never nag for daily approval on an already-approved standing plan.
            $suffix = preg_replace('/^(daily_action_|weekly_pivot_)/', '', (string) $p->type);
            if (in_array($suffix, self::AUTO_SAFE, true)) continue;

            // b22 — the INFORMATIONAL filter used to run as whereNotIn('type', …)
            // against the RAW type, but real rows carry a daily_action_ /
            // weekly_pivot_ prefix, so it never matched a single one. Purely
            // informational items (goal pivots, budget alerts) were queued for
            // "approval" when there is nothing to approve — 15 of the 84. The
            // prefix is stripped here, exactly as it already was for AUTO_SAFE.
            if (in_array($suffix, self::INFORMATIONAL, true)) continue;

            $exists = DB::table('approvals')->where('proposal_id', $p->id)->exists();
            if ($exists) continue;

            // 2026-07-07 — at most ONE standing approval per action-family per
            // workspace. The daily brief re-proposes the same external actions
            // (send emails, post social) every day; without this they pile into
            // the approvals queue and re-create the exact daily nag the owner
            // rejected. If a pending approval for this family already exists,
            // supersede the duplicate proposal instead of queuing another.
            $familyDup = DB::table('approvals as a2')
                ->join('strategy_proposals as p2', 'p2.id', '=', 'a2.proposal_id')
                ->where('a2.workspace_id', $p->workspace_id)
                ->where('a2.status', 'pending')
                ->where('a2.proposal_id', '!=', $p->id)
                ->whereRaw("REGEXP_REPLACE(p2.type, '^(daily_action_|weekly_pivot_)', '') = ?", [$suffix])
                ->exists();
            if ($familyDup) {
                DB::table('strategy_proposals')->where('id', $p->id)
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
                continue;
            }

            $breakdown = json_decode($p->cost_breakdown_json ?? '[]', true) ?: [];
            $agent = $breakdown[0]['agent'] ?? 'sarah';

            DB::table('approvals')->insert([
                'workspace_id' => $p->workspace_id,
                'proposal_id'  => $p->id,
                'task_id'      => null,
                'engine'       => 'strategy',
                'action'       => $p->type,
                'data_json'    => json_encode([
                    'kind'          => 'proposal',
                    'type'          => $p->type,
                    'title'         => $p->title,
                    'description'   => $p->description,
                    'total_credits' => (int) $p->total_credits,
                    'agent'         => $agent,
                ]),
                'status'       => 'pending',
                'created_at'   => $p->created_at ?: now(),
                'updated_at'   => now(),
            ]);
            $created++;
        }

        // 2) RECONCILE — approval rows whose proposal is no longer pending.
        $stale = DB::table('approvals as a')
            ->join('strategy_proposals as p', 'p.id', '=', 'a.proposal_id')
            ->whereNotNull('a.proposal_id')
            ->where('a.status', 'pending')
            ->where('p.status', '!=', 'pending_approval')
            ->when($wsFilter, fn ($q) => $q->where('a.workspace_id', (int) $wsFilter))
            ->get(['a.id as approval_id', 'p.status as proposal_status']);

        foreach ($stale as $s) {
            $newStatus = in_array($s->proposal_status, ['declined', 'insufficient_credits'], true) ? 'rejected' : 'approved';
            DB::table('approvals')->where('id', $s->approval_id)->update([
                'status'     => $newStatus,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            $reconciled++;
        }

        // 3) CLOSE OUT — 2026-07-07. approveProposal moves task-spawning proposals
        // (and publish_ready) to 'executing' but nothing ever reached 'completed'
        // (24 stuck platform-wide). Close a proposal once its spawned tasks are all
        // terminal, or after 6h if it spawned none (e.g. publish_ready).
        $closed = 0;
        $execRows = DB::table('strategy_proposals')
            ->where('status', 'executing')
            ->when($wsFilter, fn ($q) => $q->where('workspace_id', (int) $wsFilter))
            ->get(['id', 'workspace_id', 'updated_at']);
        foreach ($execRows as $ex) {
            $linked = DB::table('tasks')
                ->where('workspace_id', $ex->workspace_id)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.proposal_id')) = ?", [(string) $ex->id]);
            $hasTasks = (clone $linked)->exists();
            $pending  = (clone $linked)->whereNotIn('status', ['completed', 'failed', 'cancelled'])->count();
            $stale    = \Carbon\Carbon::parse($ex->updated_at)->lt(now()->subHours(6));
            if (($hasTasks && $pending === 0) || (! $hasTasks && $stale)) {
                // HONESTY FIX (2026-07-18) — "all tasks terminal" is NOT the same
                // as "the work succeeded". `failed` is terminal, so a proposal
                // whose every task failed was being closed as `completed`.
                //
                // Real case: proposal #1144 "Expand 6 thin pages with NJ-specific
                // content" was marked completed (6cr) while its only task #1987
                // failed with "No content to improve" at the same timestamp.
                // Sarah's daily "what I did for you" is built from PROPOSAL
                // status, so she reported finished work that never ran. Credits
                // were correctly released (reserve/release, no commit) — the
                // billing was right, only the reporting lied.
                //
                // Now: a proposal is `completed` only if at least one spawned
                // task actually completed. Tasks that all failed -> `failed`.
                $succeeded = (clone $linked)->where('status', 'completed')->exists();
                $newStatus = ($hasTasks && ! $succeeded) ? 'failed' : 'completed';

                DB::table('strategy_proposals')->where('id', $ex->id)->update([
                    'status'     => $newStatus,
                    'updated_at' => now(),
                ]);

                if ($newStatus === 'failed') {
                    Log::warning('[proposals:sync] closed as FAILED — every spawned task failed', [
                        'workspace_id' => $ex->workspace_id,
                        'proposal_id'  => $ex->id,
                    ]);
                }
                $closed++;
            }
        }

        $this->info("proposals:sync-approvals — mirrored {$created}, reconciled {$reconciled}, closed {$closed}");
        return self::SUCCESS;
    }
}

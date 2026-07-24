<?php

namespace App\Core\Agents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AgentCompletionReportService — gives the delegated agents a voice.
 *
 * THE PROBLEM THIS SOLVES (forensic, 2026-07-19):
 * Only 3 of 20 agents had EVER posted a message in any workspace — sarah 575,
 * james 121, elena 2 (last on 2026-05-12). The other 17 were structurally
 * mute: every notification in the codebase hardcodes its slug
 * (10 sites 'sarah', 2 sites 'james'), and the single dynamic site
 * (routes/api.php:9741) can only ever resolve to james-or-priya on a
 * WordPress publish.
 *
 * Meanwhile `tasks.assigned_agents_json` is 99.7% populated and, in ws2 alone,
 * assigns **900 tasks to priya** — who had posted exactly zero messages.
 * Sarah owns 8 tasks and posts 232 messages. The correlation was inverted:
 * the agents doing the work were the ones never heard from.
 *
 * WHAT IT DOES (per Boss, 2026-07-19):
 *   - COMPLETIONS ONLY. No "starting on it" chatter — at ~900 tasks/agent that
 *     would be pure noise.
 *   - EVERY AGENT EXCEPT SARAH. Sarah keeps her strategist voice (briefs,
 *     proposals, reminders); she does not also report her own delegations.
 *   - ALWAYS BATCHED, FRAMED AS DELEGATED BY SARAH. One message per agent per
 *     run — the specialist reporting back on what Sarah handed them.
 *
 * HONESTY: a task can complete having changed nothing (fix_orphans applied:0,
 * insert_link inserted:false). The Orchestrator flags that as `no_change` in
 * the result envelope — see the 2026-06-20 Chef Red orphan-loop forensic.
 * Those are reported as "looked at, nothing to change", never as work done,
 * and never inflate the headline count.
 *
 * NO SCHEMA LEAKAGE (hard rule): task slugs never reach the copy. Every
 * engine/action pair is mapped to a phrase; anything unmapped degrades to
 * spaced words, never a raw enum.
 */
class AgentCompletionReportService
{
    /** Sarah is the delegator, not a reporter. */
    private const EXCLUDED_AGENTS = ['sarah'];

    /** Most items listed individually before collapsing to "and N more". */
    private const MAX_LISTED = 6;

    /** Safety valve — never drain more than this per agent per run. */
    private const MAX_PER_RUN = 40;

    /**
     * Action slug -> what the agent says it did. Present tense, owner-facing.
     * Keys are the `action` column; anything missing falls back to
     * humanizeSlug() so a NEW action degrades to readable words.
     */
    private const ACTION_PHRASES = [
        'write_article'          => 'wrote a new article',
        'improve_draft'          => 'polished a draft',
        'expand_thin_pages'      => 'expanded a thin page',
        'generate_meta'          => 'rewrote page titles and descriptions',
        'insert_link'            => 'added internal links',
        'link_suggestions'       => 'found internal linking opportunities',
        'apply_link_suggestions' => 'applied internal links',
        'fix_orphans'            => 'linked up orphan pages',
        'generate_image'         => 'created an image',
        'seo_audit'              => 'ran a site audit',
        'serp_analysis'          => 'checked the search results',
        'deep_audit'             => 'ran a deep audit',
        'ai_report'              => 'put together a report',
        'publish'                => 'published content',
        'send_email'             => 'sent an email',
        'social_create_post'     => 'drafted a social post',
    ];

    /**
     * Report every unreported completion, grouped by agent, for one workspace.
     *
     * @return array<string,int> slug => number of tasks reported
     */
    public function reportForWorkspace(int $wsId, bool $dry = false): array
    {
        $rows = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->where('status', 'completed')
            ->whereNull('agent_reported_at')
            ->orderBy('completed_at')
            ->limit(self::MAX_PER_RUN * 4)
            // NOTE: `tasks` has NO `title` column — the human title lives inside
            // payload_json (e.g. {"title":"Expand 6 thin pages…","proposal_id":1144}).
            ->get(['id', 'engine', 'action', 'payload_json', 'result_json', 'assigned_agents_json', 'completed_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        // ── Group by owning agent ────────────────────────────────────────
        $byAgent = [];
        foreach ($rows as $row) {
            $slug = $this->ownerSlug($row);
            if ($slug === null || in_array($slug, self::EXCLUDED_AGENTS, true)) {
                // Not attributable, or Sarah's own — mark reported so it does
                // not accumulate forever, but say nothing.
                $this->markReported([$row->id], $dry);
                continue;
            }
            $byAgent[$slug][] = $row;
        }

        $posted = [];
        foreach ($byAgent as $slug => $agentRows) {
            $agentRows = array_slice($agentRows, 0, self::MAX_PER_RUN);
            $message   = $this->composeMessage($slug, $agentRows);
            $ids       = array_map(fn ($r) => $r->id, $agentRows);

            if ($message === null) {
                $this->markReported($ids, $dry);
                continue;
            }

            if (! $dry) {
                try {
                    app(\App\Core\Agents\AgentMessageService::class)->postAsAgent(
                        $wsId,
                        $slug,
                        $message,
                        [
                            'notification_type' => 'work_completed',
                            'delegated_by'      => 'sarah',
                            'task_count'        => count($agentRows),
                            'action_link'       => '/app/?tab=blog',
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[AgentVoice] post failed — not marking reported', [
                        'workspace_id' => $wsId,
                        'agent'        => $slug,
                        'error'        => $e->getMessage(),
                    ]);
                    continue; // leave unreported so the next run retries
                }
            }

            $this->markReported($ids, $dry);
            $posted[$slug] = count($agentRows);
        }

        return $posted;
    }

    /** First assignee wins; tasks carry `assigned_agents_json` like ["priya"]. */
    private function ownerSlug(object $row): ?string
    {
        $assignees = json_decode((string) ($row->assigned_agents_json ?? ''), true);
        if (! is_array($assignees) || empty($assignees)) {
            return null;
        }
        $slug = strtolower(trim((string) ($assignees[0] ?? '')));
        return $slug !== '' ? $slug : null;
    }

    /**
     * Compose one batched, owner-facing message. Returns null when there is
     * genuinely nothing worth saying (every task was a no-op).
     */
    private function composeMessage(string $slug, array $rows): ?string
    {
        $did      = [];   // real changes
        $noChange = 0;    // completed but changed nothing

        foreach ($rows as $row) {
            if ($this->changedNothing($row)) {
                $noChange++;
                continue;
            }
            $did[] = $this->describe($row);
        }

        // Everything was a no-op — say so plainly rather than claiming work.
        if (empty($did)) {
            if ($noChange === 0) {
                return null;
            }
            return "Sarah passed me " . $this->plural($noChange, 'job', 'jobs')
                 . " to look at. Checked " . ($noChange === 1 ? 'it' : 'them')
                 . " — nothing needed changing, so I left things as they are.";
        }

        $lines = [];
        foreach (array_slice($did, 0, self::MAX_LISTED) as $d) {
            $lines[] = '• ' . $d;
        }
        $hidden = count($did) - count($lines);
        if ($hidden > 0) {
            $lines[] = '• …and ' . $this->plural($hidden, 'more job', 'more jobs');
        }

        $intro = "Sarah passed me " . $this->plural(count($did), 'job', 'jobs')
               . " — here's what's done:";

        $out = $intro . "\n\n" . implode("\n", $lines);

        if ($noChange > 0) {
            $out .= "\n\nI also checked " . $this->plural($noChange, 'other item', 'other items')
                  . " that turned out not to need any changes.";
        }

        return $out;
    }

    /**
     * Honors the Orchestrator's `no_change` envelope flag (2026-06-20 Chef Red
     * orphan-loop forensic): a handler can return success while changing
     * nothing, and narrating that as "done" is exactly the dishonesty this
     * project keeps having to fix.
     */
    private function changedNothing(object $row): bool
    {
        $result = json_decode((string) ($row->result_json ?? ''), true);
        if (! is_array($result)) {
            return false;
        }
        $data = (array) ($result['data'] ?? []);
        return ! empty($result['no_change'])
            || (array_key_exists('changed', $data) && $data['changed'] === false)
            || (array_key_exists('changed', $result) && $result['changed'] === false);
    }

    /** One bullet: the phrase for the action, plus the title when we have one. */
    private function describe(object $row): string
    {
        $action = strtolower(trim((string) ($row->action ?? '')));
        $phrase = self::ACTION_PHRASES[$action] ?? $this->humanizeSlug($action);

        // `tasks` has no title column; it lives in payload_json.
        $payload = json_decode((string) ($row->payload_json ?? ''), true);
        $title   = is_array($payload) ? trim((string) ($payload['title'] ?? '')) : '';

        if ($title !== '' && ! $this->looksLikeSlug($title)) {
            if (mb_strlen($title) > 70) {
                $title = mb_substr($title, 0, 67) . '…';
            }
            return $phrase . ' — ' . $title;
        }
        return $phrase;
    }

    /** Never let a raw slug reach the owner; degrade to spaced words. */
    private function humanizeSlug(string $slug): string
    {
        $s = trim(str_replace(['daily_action_', 'weekly_pivot_'], '', $slug));
        $s = trim(str_replace('_', ' ', $s));
        return $s !== '' ? "finished {$s}" : 'finished a job';
    }

    /**
     * Guards against titles that add nothing or echo internals.
     *
     * Caught in live testing 2026-07-19: proposal-spawned tasks carry
     * auto-generated titles like "Sarah proposal: generate meta", which
     * rendered as "rewrote page titles and descriptions — Sarah proposal:
     * generate meta" — the de-underscored action slug leaking back through the
     * title field after the phrase had already said it properly. Real
     * human titles ("Private Chef for Special Occasions NJ…") are kept.
     */
    private function looksLikeSlug(string $t): bool
    {
        $t = trim($t);

        // Bare snake_case.
        if (preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $t)) {
            return true;
        }
        // Machine-generated prefixes.
        if (preg_match('/^(sarah\s+proposal|daily\s+action|weekly\s+pivot|proposal)\s*[:\-]/i', $t)) {
            return true;
        }
        // The title is just the action restated (e.g. "generate meta").
        $flat = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $t) ?? '');
        if ($flat !== '' && array_key_exists(trim($flat, '_'), self::ACTION_PHRASES)) {
            return true;
        }
        return false;
    }

    private function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    private function markReported(array $ids, bool $dry): void
    {
        if ($dry || empty($ids)) {
            return;
        }
        DB::table('tasks')->whereIn('id', $ids)->update(['agent_reported_at' => now()]);
    }
}

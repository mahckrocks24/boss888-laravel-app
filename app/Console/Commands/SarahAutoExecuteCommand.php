<?php

namespace App\Console\Commands;

use App\Core\Agents\AgentMessageService;
use App\Core\Orchestration\ProactiveStrategyEngine;
use App\Core\Strategy\StrategyTierService;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sarah Autonomous Executor — BOUNDED AUTO lane (2026-07-06).
 *
 * Closes the propose-only gap: before this command, every proactive proposal
 * Sarah made landed in strategy_proposals @ pending_approval and waited for a
 * human click that (on most tenants) never came — 297 stuck on ws2, 1085 cr of
 * proposed work, 897/900 credits frozen. Sarah's ONLY autonomous path was a
 * template message. This command gives her hands, WITHIN a hard safety envelope:
 *
 *   AUTO (no click)  — reversible, internal, within a per-day tier credit cap:
 *       write_article, insert_link, fix_orphans, generate_meta,
 *       expand_thin_pages, apply_link_suggestions, link_suggestions,
 *       improve_draft, generate_image   (+ publish own-blog drafts, 0cr)
 *   STILL ASKS (human, untouched here) — external / irreversible:
 *       send_email/create_campaign, social_create_post/social_publish,
 *       publish to a verified external domain, goal_pivot, strategy meetings.
 *
 * Mechanism: reuses the proven ProactiveStrategyEngine::approveProposal() path
 * (reserve -> dispatchDailyAction -> commit) exactly as a human approval would,
 * so there is ONE executor, one credit path, one audit trail. Bounded by a
 * tier-derived daily credit cap and a once-per-local-day idempotency gate.
 *
 * Per-workspace switch: workspaces.settings_json->sarah_autonomy
 *   'off'      -> skip entirely (pure propose-only, legacy behaviour)
 *   'bounded'  -> this lane (default, platform-wide)
 *   'full'     -> bounded + external actions (reserved; treated as bounded here)
 *   'draft_only' -> execute non-publishing actions only (no draft auto-publish)
 */
class SarahAutoExecuteCommand extends Command
{
    protected $signature = 'sarah:auto-execute
        {--workspace= : Limit to a single workspace id}
        {--dry-run : Report what WOULD run without executing}
        {--force : Ignore the once-per-day + local-hour gate}';

    protected $description = "Sarah's bounded autonomous execution lane — drains safe pending proposals within a per-day tier credit budget";

    /** Slugs (daily_action_<slug>) Sarah may run WITHOUT a human click. */
    private const BOUNDED_AUTO = [
        'write_article', 'insert_link', 'fix_orphans', 'generate_meta',
        'expand_thin_pages', 'apply_link_suggestions', 'link_suggestions',
        'improve_draft', 'generate_image',
    ];

    /** Per-day credit ceiling by tier. Keeps monthly burn well under plan. */
    private const TIER_DAILY_CAP = [
        'normal'           => 12,
        'aggressive'       => 25,
        'super_aggressive' => 40,
    ];

    private const MAX_ACTIONS_PER_RUN = 8;
    private const MAX_PUBLISH_PER_DAY  = 5;
    private const RUN_AFTER_LOCAL_HOUR = 9; // spread from the 08:00 morning brief

    // A proposal's stated total_credits UNDER-counts real burn: approveProposal
    // commits the proposal cost, but the spawned tasks then commit their own
    // generation cost via EngineExecutionService. Budget against a realistic
    // floor per action so a single run's ACTUAL spend stays near the tier cap.
    private const MIN_ASSUMED_COST = 4;

    public function handle(ProactiveStrategyEngine $proactive, AgentMessageService $msg): int
    {
        $dry   = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Workspace::query()
            ->where('onboarded', true)
            ->where('proactive_enabled', true);
        if ($only = $this->option('workspace')) {
            $query->where('id', (int) $only);
        }
        $workspaces = $query->get();

        $this->info("sarah:auto-execute — {$workspaces->count()} workspace(s)" . ($dry ? ' [DRY-RUN]' : ''));

        foreach ($workspaces as $ws) {
            try {
                $this->runForWorkspace($ws, $proactive, $msg, $dry, $force);
            } catch (\Throwable $e) {
                Log::error('[SarahAutoExecute] workspace failed', [
                    'workspace_id' => $ws->id, 'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ ws {$ws->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function runForWorkspace(Workspace $ws, ProactiveStrategyEngine $proactive, AgentMessageService $msg, bool $dry, bool $force): void
    {
        $wsId = (int) $ws->id;

        // ── Per-workspace autonomy switch ────────────────────────────────
        $settings = $this->settings($ws);
        $mode = (string) ($settings['sarah_autonomy'] ?? 'bounded');
        if ($mode === 'off') {
            $this->line("  – ws {$wsId}: autonomy off — skip");
            return;
        }

        // ── Timezone / once-per-day gate (mirrors the morning-brief pattern) ─
        $tz = $ws->timezone ?: 'UTC';
        $localNow  = Carbon::now($tz);
        $localDate = $localNow->toDateString();
        $cacheKey  = "ws:{$wsId}:sarah_autoexec_date";
        if (! $force) {
            if ($localNow->hour < self::RUN_AFTER_LOCAL_HOUR) {
                return; // too early in this workspace's day
            }
            if (Cache::get($cacheKey) === $localDate) {
                return; // already ran today
            }
        }

        // ── Daily credit budget from tier ────────────────────────────────
        $tier = (string) (StrategyTierService::getActiveStrategy($wsId)['tier'] ?? 'normal');
        $dailyCap = self::TIER_DAILY_CAP[$tier] ?? self::TIER_DAILY_CAP['normal'];

        // SEO-DRAIN FIX (2026-07-18) — this used to sum EVERY commit in the
        // workspace, so Sarah's autonomous budget was consumed by work that was
        // not hers: human clicks, queue tasks, the 0.1cr chat meter. Measured on
        // ws2 the workspace committed 26/5/86/24/47/90/30 credits over 7 days
        // against a 12-credit cap — so `$remaining` was 0 on SIX of seven days
        // and this command silently executed NOTHING. Her SEO proposals then sat
        // in pending_approval until the 7-day wipe deleted them. That single
        // mis-scoped query is the root of the 92% supersede rate.
        //
        // Now scoped to HER OWN drain: credits attached to bounded-auto
        // proposals that reached `completed` today. Derived from data we already
        // have — no new table, no change to CreditService, no shared-billing
        // blast radius. (A human approving a bounded-auto proposal also counts,
        // which is correct: it is still this queue draining.)
        // Anchored on `approved_at` (when the spend actually happened), NOT
        // `updated_at`. proposals:sync-approvals touches updated_at every 15
        // minutes when it closes executing -> completed, so updated_at would
        // re-count days-old work as today's spend and wrongly zero her budget.
        // approved_at is 100% populated (44/44 on ws2) and set once, at
        // approval time.
        $spentToday = (int) DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('status', 'completed')
            ->where('approved_at', '>=', $localNow->copy()->startOfDay()->utc())
            ->where(function ($q) {
                $q->where('type', 'like', 'daily_action_%')
                  ->orWhere('type', 'like', 'weekly_pivot_%');
            })
            ->whereRaw("SUBSTRING(type, 14) IN ('" . implode("','", self::BOUNDED_AUTO) . "')")
            ->sum('total_credits');

        $available = (int) (app(\App\Core\Billing\CreditService::class)->getBalance($wsId)['available'] ?? 0);
        $remaining = max(0, min($dailyCap - $spentToday, $available));

        // ── Pick the bounded-auto pending proposals that fit ─────────────
        $slugCsv = "'" . implode("','", self::BOUNDED_AUTO) . "'";
        $proposals = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending_approval')
            // daily_action_ AND weekly_pivot_ (both 13-char prefixes) — otherwise
            // weekly_pivot_ safe work is stranded (not auto-run, not surfaced).
            ->where(function ($q) {
                $q->where('type', 'like', 'daily_action_%')
                  ->orWhere('type', 'like', 'weekly_pivot_%');
            })
            // only slugs on the whitelist
            ->whereRaw("SUBSTRING(type, 14) IN ({$slugCsv})")
            // Value-first: a marketing manager writes content, she doesn't burn
            // her action budget on free link-inserts. High-leverage work leads;
            // cheap fill-ins mop up whatever budget/slots remain.
            ->orderByRaw("FIELD(SUBSTRING(type, 14),"
                . "'write_article','expand_thin_pages','improve_draft','generate_image',"
                . "'apply_link_suggestions','link_suggestions','generate_meta',"
                . "'insert_link','fix_orphans')")
            ->orderBy('created_at')          // then oldest first
            ->limit(250)                     // wide enough that leftover budget reaches cheap SEO fill-ins
            ->get();

        $approver = (int) ($ws->created_by ?: 1);
        $executed = [];
        $spent = 0;

        foreach ($proposals as $p) {
            if (count($executed) >= self::MAX_ACTIONS_PER_RUN) break;
            $cost = (int) $p->total_credits;
            // Budget against the realistic burn, not the (under-stated) proposal cost.
            $budgetCost = max($cost, self::MIN_ASSUMED_COST);
            if ($budgetCost > $remaining) {
                // SEO-DRAIN FIX (2026-07-18) — this was a silent `continue`.
                // With the mis-scoped budget above it fired on every proposal,
                // every day, for weeks, and left NO trace anywhere: no log, no
                // metric, no user-facing signal. That silence is why a fully
                // dead auto-executor looked healthy. Never skip quietly again.
                Log::info('[SarahAutoExecute] skipped — over daily budget', [
                    'workspace_id' => $wsId,
                    'proposal_id'  => $p->id,
                    'slug'         => substr((string) $p->type, 13),
                    'budget_cost'  => $budgetCost,
                    'remaining'    => $remaining,
                    'daily_cap'    => $dailyCap,
                    'spent_today'  => $spentToday,
                ]);
                continue; // try the next one that fits
            }
            $slug = substr((string) $p->type, 13);

            if ($dry) {
                $executed[] = ['slug' => $slug, 'title' => $p->title, 'cost' => $cost, 'dry' => true];
                $remaining -= $budgetCost; $spent += $cost;
                continue;
            }

            try {
                $res = $proactive->approveProposal($wsId, $approver, (int) $p->id);
                if (! empty($res['success'])) {
                    $executed[] = ['slug' => $slug, 'title' => $p->title, 'cost' => $cost];
                    $remaining -= $budgetCost; $spent += $cost;
                } else {
                    Log::info('[SarahAutoExecute] proposal not executed', [
                        'workspace_id' => $wsId, 'proposal_id' => $p->id, 'reason' => $res['error'] ?? ($res['code'] ?? 'unknown'),
                    ]);
                    if (($res['code'] ?? '') === 'NO_CREDITS') break;
                }
            } catch (\Throwable $e) {
                Log::warning('[SarahAutoExecute] approveProposal threw', [
                    'workspace_id' => $wsId, 'proposal_id' => $p->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Publishing is HUMAN-GATED — the ONE approval that remains ────
        // She does NOT auto-publish. When drafts are ready she surfaces a single
        // standing "publish ready articles" proposal → one approval, one click
        // makes the batch live (ProactiveStrategyEngine::executePublishReady).
        // 'draft_only' mode suppresses even the publish prompt.
        $readyDrafts = (int) DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', 'draft')
            ->whereNull('wp_post_id')
            ->whereNull('deleted_at')
            ->count();
        if ($readyDrafts > 0 && $mode !== 'draft_only' && ! $dry) {
            try {
                $this->upsertPublishProposal($wsId, $readyDrafts);
            } catch (\Throwable $e) {
                Log::warning('[SarahAutoExecute] publish proposal upsert failed', [
                    'workspace_id' => $wsId, 'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Nothing to do ────────────────────────────────────────────────
        if (empty($executed) && $readyDrafts === 0) {
            if (! $dry && ! $force) Cache::put($cacheKey, $localDate, now()->addDays(2));
            $this->line("  – ws {$wsId}: nothing eligible (tier={$tier}, cap={$dailyCap}, spent_today={$spentToday})");
            return;
        }

        // ── One-time standing-plan announcement, then daily "what I did" ──
        if (! $dry) {
            $this->announceStandingPlan($wsId, $msg, $tier, $dailyCap);
            $msg->postAsAgent($wsId, 'sarah', $this->summary($executed, $readyDrafts, $spent), [
                'kind'           => 'autonomous_execution',
                'executed'       => count($executed),
                'ready_publish'  => $readyDrafts,
                'credits_spent'  => $spent,
            ]);
            if (! $force) Cache::put($cacheKey, $localDate, now()->addDays(2));
        }

        $this->info("  ✓ ws {$wsId}: ran " . count($executed) . " action(s), {$readyDrafts} draft(s) awaiting publish approval, spent {$spent}cr" . ($dry ? ' [DRY]' : ''));
    }

    /**
     * Keep exactly ONE pending publish_ready proposal per workspace, refreshed
     * with the current ready-draft count. This is the single publishing gate —
     * it surfaces as one approval, not a daily pile.
     */
    private function upsertPublishProposal(int $wsId, int $count): void
    {
        $title = $count === 1 ? 'Publish 1 ready article' : "Publish {$count} ready articles";
        $desc  = "You have {$count} finished draft(s) ready to go live on your site. Approve to publish them all.";

        $existing = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('type', 'publish_ready')
            ->where('status', 'pending_approval')
            ->first();

        if ($existing) {
            DB::table('strategy_proposals')->where('id', $existing->id)->update([
                'title' => $title, 'description' => $desc, 'updated_at' => now(),
            ]);
            return;
        }

        DB::table('strategy_proposals')->insert([
            'workspace_id'       => $wsId,
            'type'               => 'publish_ready',
            'title'              => $title,
            'description'        => $desc,
            'status'             => 'pending_approval',
            'cost_breakdown_json' => json_encode([['agent' => 'sarah', 'label' => 'Publish to site', 'credits' => 0]]),
            'total_credits'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /**
     * Post the standing-plan message ONCE per workspace. This is the "she says
     * she will do X daily for ~Y credits, approve once" consent framing — after
     * this she just runs it; she never re-asks except to publish.
     */
    private function announceStandingPlan(int $wsId, AgentMessageService $msg, string $tier, int $dailyCap): void
    {
        $key = "ws:{$wsId}:sarah_plan_announced";
        if (Cache::get($key)) return;
        $tierName = ucfirst(str_replace('_', ' ', $tier));
        $body = "Quick note on how I'll work from now on — you approved this once, so I won't keep asking:\n\n"
            . "• Every day I'll handle the routine growth work myself — writing articles, on-page SEO, "
            . "internal links, meta, thin-page fixes — up to about {$dailyCap} credits/day on your {$tierName} plan.\n"
            . "• I'll only ever ask you for ONE thing: a quick OK to publish finished articles to your live site. "
            . "Nothing goes public without your say-so.\n"
            . "• Bigger external moves (emailing your leads, social posts) still wait for your approval too.\n\n"
            . "You'll get a short recap each day of what I got done. If you ever want me to pause, just say so.";
        $msg->postAsAgent($wsId, 'sarah', $body, ['kind' => 'standing_plan']);
        Cache::put($key, now()->toDateString(), now()->addDays(365));
    }

    private function summary(array $executed, int $readyDrafts, int $spent): string
    {
        $lines = ["Here's what I got done for you today — no approval needed, this is the plan you already okayed:\n"];
        foreach ($executed as $e) {
            $lines[] = "• " . $this->humanize($e['slug']) . " — " . $this->trim($e['title']);
        }
        if (empty($executed)) {
            $lines[] = "• Stayed on top of the routine work — nothing new needed today.";
        }
        $lines[] = "\nThat's {$spent} credit(s) put to work.";
        if ($readyDrafts > 0) {
            $noun = $readyDrafts === 1 ? 'article is' : 'articles are';
            $lines[] = "\n📢 {$readyDrafts} finished {$noun} ready to go live — approve the \"Publish\" request whenever you want them public. That's the only thing I need you for.";
        }
        return implode("\n", $lines);
    }

    private function humanize(string $slug): string
    {
        return [
            'write_article'          => 'Wrote a new article',
            'insert_link'            => 'Added internal links',
            'fix_orphans'            => 'Fixed orphan pages',
            'generate_meta'          => 'Generated SEO meta',
            'expand_thin_pages'      => 'Expanded thin content',
            'apply_link_suggestions' => 'Applied link suggestions',
            'link_suggestions'       => 'Built internal-link plan',
            'improve_draft'          => 'Improved a draft',
            'generate_image'         => 'Generated an image',
        ][$slug] ?? ucfirst(str_replace('_', ' ', $slug));
    }

    private function trim(?string $s): string
    {
        $s = trim((string) $s);
        return mb_strlen($s) > 80 ? mb_substr($s, 0, 77) . '…' : $s;
    }

    private function settings(Workspace $ws): array
    {
        $raw = $ws->settings_json ?? null;
        if (is_array($raw)) return $raw;
        if (is_string($raw)) return json_decode($raw, true) ?: [];
        return [];
    }
}

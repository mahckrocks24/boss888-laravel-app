<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use App\Core\EngineKernel\CapabilityMapService;
use App\Core\Billing\CreditService;
use App\Core\Audit\AuditLogService;
use App\Core\Memory\WorkspaceMemoryService;
use App\Connectors\ConnectorResolver;
use App\Services\ParameterResolverService;
use App\Services\IdempotencyService;
use App\Services\ConnectorCircuitBreakerService;
use App\Services\TaskProgressService;
use App\Services\QueueControlService;
use App\Services\ExecutionRateLimiterService;
use App\Core\PlanGating\PlanGatingService;
use Illuminate\Support\Facades\Log;

class Orchestrator
{
    private int $maxStepsPerTask = 5;
    private int $maxRetries = 2;

    public function __construct(
        private CapabilityMapService $capabilityMap,
        private CreditService $creditService,
        private TaskService $taskService,
        private AuditLogService $auditLog,
        private WorkspaceMemoryService $memory,
        private ConnectorResolver $connectorResolver,
        private ParameterResolverService $parameterResolver,
        private IdempotencyService $idempotency,
        private ConnectorCircuitBreakerService $circuitBreaker,
        private TaskProgressService $progress,
        private QueueControlService $queueControl,
        private ExecutionRateLimiterService $rateLimiter,
        private PlanGatingService $planGating,
    ) {}

    public function execute(Task $task): void
    {
        $reservationRef = null;
        $__cjob = null; // Phase I — observational CreativeJob handle

        // 2026-06-20 — bind the executing task's workspace into the container so
        // queue/orchestrator-context callers that have no request header and no
        // authenticated user can still resolve the workspace. Used as a
        // last-resort fallback by RuntimeClient::imageGenerate to namespace
        // featured images correctly (was landing in ai-images/0/). Scoped to
        // this single execute() call; rebound on every task, never leaks across
        // requests (request-context code paths resolve workspace on their own
        // and never read this binding).
        try {
            app()->instance('lu.current_workspace_id', (int) $task->workspace_id);
        } catch (\Throwable) {
            // Container unavailable — non-fatal; image pathing falls back to 0.
        }

        try {
            // ── b27 (2026-07-24) — AGENT CAPABILITY CHECK (SHADOW MODE) ──
            //
            // The platform has TWO execution engines. EngineExecutionService
            // (synchronous / Sarah-plan path) checks AgentCapabilityService before
            // it runs anything. This async queue path — which carries ~95% of all
            // task volume — has its own dispatch table and never consulted it, so
            // agent permissions were simply not enforced on the busy path. Audit
            // evidence: james ran `fix_orphans` 114 times without holding the
            // capability, and zero AGENT_NOT_AUTHORIZED was ever raised here.
            //
            // Deliberately SHADOW for now: it records what WOULD be denied and
            // changes nothing. Turning this into a hard block is a governance
            // decision — flipping it blind on a live queue would stop real
            // customer work the moment the grant table disagrees with reality.
            // Promote to enforcing only once the log stays clean.
            $this->shadowCapabilityCheck($task);

            // ── 0. Idempotency check ─────────────────────────────────────
            $idemKey = $this->idempotency->ensureKey($task);

            $duplicate = $this->idempotency->checkDuplicate($idemKey);
            if ($duplicate) {
                $task->update([
                    'status' => 'completed',
                    'result_json' => $duplicate['result'],
                    'completed_at' => now(),
                    'progress_message' => 'Duplicate — returned cached result',
                ]);
                $this->progress->recordEvent($task->id, 'idempotent_skip', 'completed',
                    message: 'Duplicate execution skipped');
                return;
            }

            if (! $this->idempotency->acquireLock($idemKey)) {
                $task->update(['status' => 'blocked', 'progress_message' => 'Execution lock held by another worker']);
                $this->progress->recordEvent($task->id, 'lock_blocked', 'blocked',
                    message: 'Could not acquire execution lock');
                return;
            }

            // ── 1. Plan gating check ──────────────────────────────────
            $planCheck = $this->planGating->check($task->workspace_id, $task->action);
            if (! $planCheck['allowed']) {
                $task->update(['status' => 'failed', 'progress_message' => $planCheck['reason']]);
                $this->progress->recordEvent($task->id, 'plan_gated', 'failed',
                    message: $planCheck['reason']);
                $this->idempotency->releaseLock($idemKey);
                return;
            }

            // ── 1b. Launch-scope enforcement (MISSION-018 WS-1, RISK-0039) ──
            // The sync path (EngineExecutionService::execute) blocks removed
            // capabilities "regardless of how it was reached"; this async path —
            // ~95% of task volume — did not, so a removed-capability task row
            // created by any path other than the (now-hardened, RISK-0038)
            // TaskService::create would execute. Enforce here too, keyed on the
            // task's engine AND the engine the capability map derives, so a
            // mislabelled row cannot slip through either. Unlike the b27
            // agent-capability check above (shadow — the grant table can lag
            // reality), launch scope is a hard product boundary: a removed
            // capability is FAILED, never run.
            $__derivedEngine = ($this->capabilityMap->resolve($task->action)['engine'] ?? null);
            foreach (array_unique(array_filter([(string) $task->engine, (string) $__derivedEngine])) as $__eng) {
                $__scopeDenied = \App\Core\LaunchScope\LaunchScopePolicy::deniedReason($__eng, (string) $task->action, [], []);
                if ($__scopeDenied !== null) {
                    \Illuminate\Support\Facades\Log::warning('[LaunchScope] async execution refused', [
                        'task_id' => $task->id, 'engine' => $__eng, 'action' => $task->action, 'reason' => $__scopeDenied,
                    ]);
                    $this->taskService->markFailed($task, "LAUNCH_SCOPE: removed capability {$__eng}/{$task->action}", terminal: true);
                    $this->idempotency->releaseLock($idemKey);
                    return;
                }
            }

            // ── 2. Resolve capability ────────────────────────────────────
            $capability = $this->capabilityMap->resolve($task->action);
            if (! $capability) {
                throw new \RuntimeException("No capability mapped for action: {$task->action}");
            }

            $connectorName = $capability['connector'];

            // ── 2. Circuit breaker check ─────────────────────────────────
            if ($connectorName && ! $this->circuitBreaker->isAvailable($connectorName)) {
                $task->update(['status' => 'degraded', 'progress_message' => "Connector {$connectorName} circuit open"]);
                $this->progress->recordEvent($task->id, 'circuit_open', 'degraded',
                    connector: $connectorName, message: 'Circuit breaker open — execution blocked');
                $this->idempotency->releaseLock($idemKey);
                return;
            }

            // ── 3. Rate limit check ──────────────────────────────────────
            $agentSlug = $this->extractAgentSlug($task);
            $rateCheck = $this->rateLimiter->check($task->workspace_id, $agentSlug, $connectorName ?? '');
            if (! $rateCheck['allowed']) {
                // 2026-05-25 — auto-retry rate-limited tasks instead of leaving
                // them permanently blocked. Forensic: 90+ orphan chain children
                // accumulated because rate-limited tasks set status='blocked'
                // with no retry path. Mirror the workspace-concurrency handler
                // 10 lines below which already uses requeue().
                $reason   = $rateCheck['reason'] ?? '';
                $delaySec = 60; // default: 1 min
                if (str_contains($reason, 'per_hour'))        $delaySec = 3630; // hour window + 30s buffer
                elseif (str_contains($reason, 'per_day'))     $delaySec = 600;  // re-check every 10 min
                elseif (str_contains($reason, 'per_minute'))  $delaySec = 65;   // minute + buffer
                $task->update(['status' => 'queued', 'progress_message' => $reason . ' — auto-retry in ' . $delaySec . 's']);
                $this->progress->recordEvent($task->id, 'rate_limited_retry', 'queued',
                    message: $reason . ' — retry queued in ' . $delaySec . 's');
                $this->idempotency->releaseLock($idemKey);
                $this->taskService->requeue($task, $delaySec);
                return;
            }

            // ── 4. Workspace concurrency check ───────────────────────────
            if (! $this->queueControl->canWorkspaceExecute($task->workspace_id)) {
                $task->update(['status' => 'queued', 'progress_message' => 'Workspace at concurrency cap — requeued']);
                $this->progress->recordEvent($task->id, 'throttled', 'queued',
                    message: 'Workspace concurrency cap reached');
                $this->idempotency->releaseLock($idemKey);
                // Re-dispatch with delay
                $this->taskService->requeue($task, 10);
                return;
            }

            // ── 5. Parameter resolution ──────────────────────────────────
            $payload = $task->payload_json ?? [];

            // 2026-07-23 FIX — ORDERING BUG. The documented contract for the
            // creative image actions is "article_id (required) OR explicit
            // prompt", and ensureImagePrompt() exists to satisfy it. But it was
            // only applied down in the dispatch map (step 7), while the
            // parameter resolver here at step 5 hard-requires `prompt` — so a
            // Sarah proposal/chat image task carrying only title/article_id was
            // rejected as "Missing required parameters: prompt" and execution
            // never reached the derivation that would have fixed it. Forensic:
            // ws29 task 2205 (title present, no prompt) burned 3 retries, and
            // repeated failures then tripped the creative connector's circuit
            // breaker, degrading every later image task. Derive first, then
            // validate. A caller that already supplies a prompt is untouched.
            if (in_array($task->action, ['generate_image', 'generate_image_mini', 'generate_image_high'], true)) {
                $payload = $this->ensureImagePrompt($task->workspace_id, $payload);
            }

            $resolution = $this->parameterResolver->resolve($task->workspace_id, $task->action, $payload);

            if (! $resolution['resolved']) {
                throw new \RuntimeException('Missing required parameters: ' . implode(', ', $resolution['missing']));
            }

            $validatedParams = $resolution['params'];

            // ── 6. Mark running ──────────────────────────────────────────
            $this->taskService->markRunning($task);
            $task->update(['execution_started_at' => now()]);
            $this->progress->recordEvent($task->id, 'execution_started', 'running',
                action: $task->action, message: 'Execution started');

            // ── 7. Credit reservation (reserve upfront for all steps) ────
            // Wave 42b: task.credit_cost is the source of truth (locked in at
            // TaskService::create time with chain-bundle override applied for
            // Sarah chains). CapabilityMap is only a seed/fallback.
            $creditCost = $task->credit_cost ?? ($capability['credit_cost'] ?? 0);
            if ($creditCost > 0) {
                $reservation = $this->creditService->reserveCredits(
                    $task->workspace_id, $creditCost, 'Task', $task->id, "task_{$task->id}"
                );
                $reservationRef = $reservation->reservation_reference;
                $this->progress->recordEvent($task->id, 'credits_reserved', 'running',
                    message: "Reserved {$creditCost} credits (ref: {$reservationRef})");
            }

            // ── 7b (Phase I): open an observational CreativeJob for Studio tasks ──
            if (in_array($task->engine, ['creative', 'studio'], true)) {
                $__cjob = $this->cjSafe(fn () => app(\App\Engines\Studio\Services\CreativeJobService::class)->begin([
                    'workspace_id'    => $task->workspace_id,
                    'task_id'         => $task->id,
                    'type'            => $task->action === 'generate_video' ? 'video' : 'generation',
                    'capability'      => $task->action,
                    'original_prompt' => is_string($task->payload_json['prompt'] ?? null) ? $task->payload_json['prompt'] : null,
                    'source'          => 'orchestrator',
                ]));
            }

            // ── 8. Execute steps ─────────────────────────────────────────
            $steps = $this->resolveSteps($task, $validatedParams);
            $totalSteps = min(count($steps), $this->maxStepsPerTask);
            $task->update(['total_steps' => $totalSteps]);

            $results = [];
            foreach ($steps as $i => $step) {
                if ($i >= $this->maxStepsPerTask) break;

                $this->progress->updateProgress($task, $i + 1, $totalSteps,
                    "Executing step " . ($i + 1) . " of {$totalSteps}: {$step['action']}");

                $stepResult = $this->executeStep($task, $step['action'], $step['params'], $capability, $i);
                $results[] = $stepResult;

                if (! $stepResult['success']) {
                    throw new \RuntimeException("Step " . ($i + 1) . " ({$step['action']}) failed: {$stepResult['message']}");
                }

                // H2 Part 1 — asset parity: persist Sarah-generated creative images
                // via the EXISTING asset lifecycle, exactly as the EES path does.
                $this->persistCreativeAssetIfNeeded($task, $step['action'], $step['params'], $stepResult);
            }

            // ── 9. Commit credits ────────────────────────────────────────
            if ($reservationRef && $reservationRef !== 'zero_cost') {
                $this->creditService->commitReservedCredits($reservationRef);
                $this->progress->recordEvent($task->id, 'credits_committed', 'running',
                    message: "Credits committed (ref: {$reservationRef})");
            }

            // ── 10. Finalize ─────────────────────────────────────────────
            $finalResult = count($results) === 1 ? $results[0] : [
                'success' => true,
                'data' => $results,
                'message' => 'All ' . count($results) . ' steps completed',
            ];

            $task->update(['execution_finished_at' => now()]);
            $this->taskService->markCompleted($task, $finalResult);

            // Phase I — finalize the observational CreativeJob (link persisted asset).
            if ($__cjob) {
                $this->cjSafe(function () use ($__cjob, $finalResult, $task) {
                    $__meta = (is_array($finalResult['data'] ?? null) && is_array($finalResult['data']['metadata'] ?? null))
                        ? $finalResult['data']['metadata'] : [];
                    app(\App\Engines\Studio\Services\CreativeJobService::class)->complete($__cjob, [
                        'status'         => 'completed',
                        'asset_id'       => \Illuminate\Support\Facades\DB::table('assets')->where('task_id', $task->id)->value('id'),
                        'provider'       => $__meta['provider'] ?? null,
                        'provider_model' => $__meta['model'] ?? null,
                    ]);
                });
            }
            $this->progress->recordEvent($task->id, 'execution_completed', 'completed',
                message: 'Task completed successfully');

            // PATCH (Phase 2F, 2026-05-10) — execution-plan dependency unlock.
            // If this task is part of a multi-step plan, see if any sibling
            // tasks become unblocked. Wrapped in try/catch so any plan-side
            // failure cannot derail the orchestrator's normal completion path.
            try {
                app(\App\Core\Orchestration\ExecutionPlanService::class)->onTaskComplete($task->id);
            } catch (\Throwable $planErr) {
                \Illuminate\Support\Facades\Log::warning("ExecutionPlanService::onTaskComplete failed for task {$task->id}: " . $planErr->getMessage());
            }

            // Wave 41 — wake children that were blocked waiting for this parent.
            // Sarahs chain uses parent_task_id (not execution_plan_id) so the
            // ExecutionPlanService hook above doesnt see them.
            try {
                $blockedChildren = \App\Models\Task::where('parent_task_id', $task->id)
                    ->where('status', 'blocked')
                    ->get();
                foreach ($blockedChildren as $child) {
                    \Illuminate\Support\Facades\Log::info('[Orchestrator] waking blocked child', [
                        'parent_id' => $task->id,
                        'child_id'  => $child->id,
                        'action'    => $child->action,
                    ]);
                    app(\App\Core\TaskSystem\TaskDispatcher::class)->dispatch($child);
                }
            } catch (\Throwable $wakeErr) {
                \Illuminate\Support\Facades\Log::warning("Wave 41 wake-children failed for task {$task->id}: " . $wakeErr->getMessage());
            }

            // 2026-06-20 — self-orphaning fix (UNIVERSAL). Every article path
            // (sarah_chat conversational, SEO-assistant batch, proactive) lands
            // a completed write_article here. The per-article chain only inserts
            // OUTBOUND links from the new article, so it is born with zero
            // INBOUND links = an orphan. Enqueue ONE inbound orphan-rescue via
            // the existing, wired, proven fix_orphans action — its anchor /
            // relevance intelligence runs in the runtime, nothing new added here.
            //
            // Runs for ALL workspaces incl. WP-connected (owner directive
            // 2026-06-20 — the WP SEO assistant must auto-link too). On WP the
            // fix_orphans path delivers inbound links into the LIVE WordPress
            // site via the WP-truthful path (records only on successful push).
            // Deduped to a 10-minute window so a multi-article batch queues at
            // most one rescue. app888 / WP assistant are transparent (task card).
            try {
                if ($task->action === 'write_article' && ($finalResult['success'] ?? true)) {
                    $wsForRescue = (int) $task->workspace_id;
                    $recentRescue = \Illuminate\Support\Facades\DB::table('tasks')
                        ->where('workspace_id', $wsForRescue)
                        ->where('action', 'fix_orphans')
                        ->where('created_at', '>=', now()->subMinutes(10))
                        ->exists();
                    if (! $recentRescue) {
                        $rescue = app(\App\Core\TaskSystem\TaskService::class)->create($wsForRescue, [
                            'engine'            => 'seo',
                            'action'            => 'fix_orphans',
                            'source'            => 'agent',
                            'priority'          => 'low',
                            'assigned_agents'   => ['james'],
                            'auto_approve'      => true,
                            'requires_approval' => false,
                            'credit_cost'       => 0,
                            'payload'           => [
                                'title'          => 'Link new articles into the site',
                                'created_via'    => 'auto_orphan_rescue',
                                // Unique per trigger so TaskService's payload-derived
                                // idempotency key never collides with a prior rescue
                                // (identical payloads hash to ONE key and the 2nd
                                // insert hits tasks_idempotency_key_unique forever).
                                // Batch spam is still bounded by the 10-min window above.
                                'trigger_task_id' => (int) $task->id,
                            ],
                        ]);
                        $rescue->update(['progress_message' => 'Linking new articles into the site']);
                    }
                }
            } catch (\Throwable $rescueErr) {
                \Illuminate\Support\Facades\Log::warning("[Orchestrator] orphan-rescue enqueue failed for task {$task->id}: " . $rescueErr->getMessage());
            }

            // 2026-06-20 — Fix 3: seo_content_index featured-image sync. The
            // index row is written at write-time (before the image exists);
            // generate_image_mini sets articles.featured_image_url but never
            // refreshes the index, so the SEO health report / assistant under-
            // count featured images. After an image task completes, copy the
            // article's featured image into its index row.
            //
            // TARGETED update of ONLY the two image columns — deliberately does
            // NOT touch inbound_links (a full re-index via indexArticleForLink-
            // Graph writes inbound_links=0 and would RE-ORPHAN the article).
            // Pure read-model correction: no intelligence, no generation logic,
            // and no external side effect (does not push to WP), so it is safe
            // for every workspace incl. ws7 and left ungated.
            try {
                if (in_array($task->action, ['generate_image_mini', 'generate_image', 'generate_image_high'], true)
                    && ($finalResult['success'] ?? true)) {
                    $aid = (int) ($params['article_id'] ?? 0);
                    // 2026-07-06 — $params is not in scope in execute(), and
                    // standalone image tasks (Sarah-chat / proposal, no parent)
                    // carry article_id in their OWN payload. Resolve from there
                    // first; without this $aid stayed 0 and the image never got
                    // attached to the article.
                    if ($aid === 0) {
                        $pl = is_string($task->payload_json)
                            ? (json_decode($task->payload_json, true) ?: [])
                            : ((array) ($task->payload_json ?? []));
                        $aid = (int) ($pl['article_id'] ?? 0);
                    }
                    // Chain children (write_article parent -> image child) carry
                    // article_id only via the parent's result passthrough.
                    if ($aid === 0 && ! empty($task->parent_task_id)) {
                        $pr = \Illuminate\Support\Facades\DB::table('tasks')
                            ->where('id', $task->parent_task_id)->value('result_json');
                        if ($pr) {
                            $pd = json_decode($pr, true);
                            $aid = (int) ($pd['data']['article_id'] ?? $pd['article_id'] ?? 0);
                        }
                    }
                    if ($aid > 0) {
                        $art = \Illuminate\Support\Facades\DB::table('articles')
                            ->where('id', $aid)
                            ->where('workspace_id', (int) $task->workspace_id)
                            ->first(['title', 'featured_image_url']);

                        // 2026-07-06 — CreativeService (LOCKED) returns the image
                        // in the task result but does not reliably write it back
                        // to the article. Persist it here (plumbing, not creative
                        // logic) so chat/proposal image tasks actually ATTACH the
                        // image to the article — otherwise the work "completes"
                        // but the article stays imageless.
                        $imgUrl = $finalResult['data']['featured_image_url'] ?? $finalResult['data']['url']
                                ?? $finalResult['featured_image_url'] ?? ($finalResult['url'] ?? null);
                        $imgAlt = $finalResult['data']['featured_image_alt'] ?? ($finalResult['featured_image_alt'] ?? null);
                        if ($art && empty($art->featured_image_url) && ! empty($imgUrl)) {
                            \Illuminate\Support\Facades\DB::table('articles')
                                ->where('id', $aid)
                                ->update([
                                    'featured_image_url' => $imgUrl,
                                    'featured_image_alt' => $imgAlt,
                                    'updated_at'         => now(),
                                ]);
                            $art->featured_image_url = $imgUrl;
                        }

                        if ($art && ! empty($art->featured_image_url) && ! empty($art->title)) {
                            \Illuminate\Support\Facades\DB::table('seo_content_index')
                                ->where('workspace_id', (int) $task->workspace_id)
                                ->where('title', $art->title)
                                ->update([
                                    'featured_image_url' => $art->featured_image_url,
                                    'has_featured_image' => 1,
                                    'updated_at'         => now(),
                                ]);
                        }
                    }
                }
            } catch (\Throwable $imgSyncErr) {
                \Illuminate\Support\Facades\Log::warning("[Orchestrator] index featured-image sync failed for task {$task->id}: " . $imgSyncErr->getMessage());
            }

            // 2026-06-20 — PROACTIVE completion reporting (owner directive). Sarah
            // (Laravel/app888) and the WP SEO assistant must announce finished work
            // WITHOUT being asked. Forensic showed completions were only surfaced
            // reactively on the next user message (read-back at routes/api.php) and
            // chains that finished with no follow-up were never reported at all.
            // When the LAST task of a user-requested chain completes, post ONE
            // honest summary as the orchestrating agent (Sarah on Laravel, James on
            // the WP connector assistant surface — same reporting intelligence).
            // postAsAgent already fans the message out as push to web + app888.
            // An atomic JSON_SET claim on the root prevents a double-post when
            // sibling children finish concurrently across the worker pool. Phrasing
            // reuses the root's already-honest result message (friendlyMessageFor +
            // no_change), so no-op work is never mis-reported as done.
            try {
                $rootId = (int) ($task->parent_task_id ?: $task->id);
                $chain  = \App\Models\Task::where('id', $rootId)
                    ->orWhere('parent_task_id', $rootId)->get(['id', 'status']);
                $pending = $chain->whereNotIn('status', ['completed', 'failed', 'cancelled'])->count();
                if ($chain->isNotEmpty() && $pending === 0) {
                    $claimed = \Illuminate\Support\Facades\DB::update(
                        "UPDATE tasks SET payload_json = JSON_SET(COALESCE(payload_json, JSON_OBJECT()), '$.completion_reported', true) "
                        . "WHERE id = ? AND COALESCE(JSON_EXTRACT(payload_json, '$.completion_reported'), false) = false",
                        [$rootId]
                    );
                    if ($claimed === 1) {
                        // Raw row (NOT the Eloquent model) — Task casts
                        // payload_json/result_json to arrays, which would make the
                        // json_decode() calls below throw on an array argument.
                        $root = \Illuminate\Support\Facades\DB::table('tasks')->where('id', $rootId)->first();
                        $rp   = $root ? (json_decode($root->payload_json ?? '{}', true) ?: []) : [];
                        $skipVia = ['auto_orphan_rescue', 'sarah_proposal', 'sarah_daily',
                                    'sarah_weekly', 'sarah_monthly', 'post_publish_coordinator',
                                    'fill_missing_images', 'sarah_router', 'review_publish', 'proof_run'];
                        // 2026-07-07 — a bulk image fill produces N standalone image
                        // tasks, each posting an identical "Image generated." bubble
                        // (44 seen in ws2). Suppress per-image completion chatter —
                        // the image simply appears on its article.
                        $isStandaloneImage = in_array(($root->action ?? ''), ['generate_image_mini', 'generate_image', 'generate_image_high'], true)
                                             && empty($root->parent_task_id);
                        if ($root && ! $isStandaloneImage && ! in_array(($rp['created_via'] ?? ''), $skipVia, true)
                            && ($root->source ?? '') !== 'system') {
                            $rr  = json_decode($root->result_json ?? '{}', true) ?: [];
                            $msg = (string) ($rr['message'] ?? 'Your request is done.');
                            if ($root->action === 'write_article') {
                                $aid = (int) ($rr['data']['article_id'] ?? 0);
                                if ($aid > 0) {
                                    $art = \Illuminate\Support\Facades\DB::table('articles')
                                        ->where('id', $aid)->first(['title']);
                                    if ($art && ! empty($art->title)) {
                                        $idx = \Illuminate\Support\Facades\DB::table('seo_content_index')
                                            ->where('workspace_id', (int) $root->workspace_id)
                                            ->where('title', $art->title)
                                            ->first(['has_featured_image', 'internal_link_count']);
                                        $imgTxt  = ($idx && $idx->has_featured_image) ? 'with a featured image' : 'image still finishing';
                                        $linkTxt = ($idx && $idx->internal_link_count !== null)
                                            ? ((int) $idx->internal_link_count . ' internal links') : 'internal links added';
                                        $msg = "Your article \"{$art->title}\" is ready — {$imgTxt}, {$linkTxt}. You'll find it in your drafts.";
                                    }
                                }
                            }
                            // WP connector surface has NO agents (owner directive):
                            // the single SEO Assistant reports there (its own store).
                            // Laravel/app888 reports as Sarah via agent_messages (+push).
                            $seoAssistant = app(\App\Engines\SEO\Services\SeoAssistantService::class);
                            if ($seoAssistant->isWpWorkspace((int) $root->workspace_id)) {
                                $seoAssistant->pushAssistantNotice((int) $root->workspace_id, $msg);
                            } else {
                                app(\App\Core\Agents\AgentMessageService::class)->postAsAgent(
                                    (int) $root->workspace_id, 'sarah', $msg,
                                    ['completion_report' => true, 'root_task_id' => $rootId]
                                );
                            }
                            // Mark the chain read so the reactive read-back layer does
                            // not surface the same completion a second time.
                            \Illuminate\Support\Facades\DB::table('tasks')
                                ->where(function ($q) use ($rootId) {
                                    $q->where('id', $rootId)->orWhere('parent_task_id', $rootId);
                                })
                                ->whereNull('sarah_read_at')
                                ->update(['sarah_read_at' => now()]);
                        }
                    }
                }
            } catch (\Throwable $reportErr) {
                \Illuminate\Support\Facades\Log::warning("[Orchestrator] completion report failed for task {$task->id}: " . $reportErr->getMessage());
            }

            // Record rate limit usage
            $this->rateLimiter->record($task->workspace_id, $agentSlug, $connectorName ?? '');

            // ── 11. Audit ────────────────────────────────────────────────
            $this->auditLog->log($task->workspace_id, null, 'task.executed', 'Task', $task->id,
                $this->sanitizeAuditPayload([
                    'idempotency_key' => $idemKey,
                    'action' => $task->action,
                    'connector' => $connectorName,
                    'steps' => count($results),
                    'credit_cost' => $creditCost,
                    'reservation_ref' => $reservationRef,
                    'verification' => 'passed',
                ]));

            $this->idempotency->releaseLock($idemKey);

            if ($connectorName) {
                $this->circuitBreaker->recordSuccess($connectorName);
            }

        } catch (\Throwable $e) {
            Log::error("Orchestrator failed for task {$task->id}", [
                'action' => $task->action,
                'error' => $e->getMessage(),
            ]);

            // Release reserved credits on failure
            if ($reservationRef && $reservationRef !== 'zero_cost') {
                $this->creditService->releaseReservedCredits($reservationRef);
                $this->progress->recordEvent($task->id, 'credits_released', 'failed',
                    message: "Credits released on failure (ref: {$reservationRef})");
            }

            if (isset($connectorName) && $connectorName) {
                $this->circuitBreaker->recordFailure($connectorName);
            }

            $this->progress->recordEvent($task->id, 'execution_failed', 'failed',
                message: $e->getMessage());

            $this->auditLog->log($task->workspace_id, null, 'task.execution_failed', 'Task', $task->id,
                $this->sanitizeAuditPayload([
                    'idempotency_key' => $idemKey ?? null,
                    'action' => $task->action,
                    'connector' => $connectorName ?? null,
                    'error' => $e->getMessage(),
                    'reservation_ref' => $reservationRef,
                ]));

            // b28 (2026-07-24) — TRANSIENT-PROVIDER BACKOFF.
            //
            // The in-step retry loop waits 0.5s then 1s. Upstream blips
            // (runtime 503 request_timeout, OpenAI Cloudflare 520, rate limits
            // that return retry_after:60) outlast all three attempts inside
            // ~1.5s, so a task that would succeed on a later try was recorded as
            // a hard failure. Audit found this on image generation (14/174).
            //
            // If the error looks transient AND we have re-queue budget left,
            // release the lock and re-dispatch with a real delay instead of
            // failing. Deterministic-failure paths (bad input, plan gating,
            // moderation_blocked) are NOT transient and fall straight through to
            // markFailed as before.
            if (isset($idemKey)) {
                $this->idempotency->releaseLock($idemKey);
            }

            $requeueDelay = $this->transientRequeueDelay($task, $e->getMessage());
            if ($requeueDelay !== null) {
                \Illuminate\Support\Facades\DB::table('tasks')->where('id', $task->id)->update([
                    'status'           => 'queued',
                    'retry_count'      => \Illuminate\Support\Facades\DB::raw('retry_count + 1'),
                    'progress_message' => 'Transient upstream error — retrying in ' . $requeueDelay . 's',
                    'updated_at'       => now(),
                ]);
                $this->progress->recordEvent($task->id, 'transient_requeue', 'queued',
                    message: 'Upstream transient — re-dispatched with ' . $requeueDelay . 's backoff');
                Log::warning('[Orchestrator] transient error — re-queued', [
                    'task_id' => $task->id, 'action' => $task->action,
                    'delay_s' => $requeueDelay, 'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
                app(\App\Core\TaskSystem\TaskDispatcher::class)
                    ->dispatchWithDelay($task->fresh(), $requeueDelay);
                return;
            }

            // H2 Part 2 — reached only after transientRequeueDelay() returned null,
            // i.e. a TERMINAL (non-retryable) error. Fail immediately instead of
            // re-queuing on the generic retry budget.
            $this->taskService->markFailed($task, $e->getMessage(), terminal: true);
            $this->cjSafe(fn () => app(\App\Engines\Studio\Services\CreativeJobService::class)->fail($__cjob, $e->getMessage()));
        }
    }

    /**
     * b28 — decide whether a failed task should be re-queued after a backoff.
     *
     * @return int|null  seconds to wait, or null to fail immediately.
     */
    private function transientRequeueDelay(Task $task, string $error): ?int
    {
        // Budget: at most 2 transient re-queues per task, on top of the
        // in-step retries. retry_count is the persisted counter.
        $maxRequeues = 2;
        if ((int) ($task->retry_count ?? 0) >= $maxRequeues) {
            return null;
        }

        $e = strtolower($error);

        // Deterministic — retrying cannot help. Never re-queue these.
        $permanent = [
            'bad input', 'no content', 'validation', 'moderation_blocked',
            'safety system', 'not authorized', 'plan does not allow',
            'no capability', 'invalid', 'unauthorized', 'quota', 'insufficient',
        ];
        foreach ($permanent as $p) {
            if (str_contains($e, $p)) return null;
        }

        // Transient — worth another attempt after a real pause.
        $transient = [
            'request_timeout', 'timeout', 'timed out', '503', '502', '520', '429',
            'temporarily', 'try again', 'rate limit', 'overloaded', 'unavailable',
            'connection', 'econnreset', 'cloudflare', 'origin',
        ];
        $isTransient = false;
        foreach ($transient as $t) {
            if (str_contains($e, $t)) { $isTransient = true; break; }
        }
        if (! $isTransient) return null;

        // Honour an explicit retry_after if the upstream gave one; else escalate
        // 30s → 90s across the two re-queues.
        if (preg_match('/retry[_ ]?after["\':\s]+(\d{1,4})/', $e, $m)) {
            return min(300, max(30, (int) $m[1]));
        }
        return ((int) ($task->retry_count ?? 0) === 0) ? 30 : 90;
    }

    /**
     * H2 Part 1 — Sarah/Orchestrator asset parity.
     *
     * The CreativeConnector dispatch path returns image data but (unlike the
     * EES/CreativeService path) never writes an assets row. Reuse the EXISTING
     * asset lifecycle (CreativeService::createAsset + completeAsset) to record
     * exactly one asset per task with identical provider/model metadata. Guarded
     * against duplicates so retries and idempotent replays never double-persist.
     * No creative_job_id, no schema change, no EES impact.
     */
    private function persistCreativeAssetIfNeeded(Task $task, string $action, array $params, array $result): void
    {
        if (! in_array($action, ['generate_image', 'generate_image_mini', 'generate_image_high'], true)) {
            return;
        }
        if (! ($result['success'] ?? false)) {
            return;
        }
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $url  = $data['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }
        // Exactly one asset per task — safe across in-step retries and idempotent replay.
        if (\Illuminate\Support\Facades\DB::table('assets')->where('task_id', $task->id)->exists()) {
            return;
        }

        try {
            $creative = app(\App\Engines\Creative\Services\CreativeService::class);
            $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            [$w, $h] = $this->parseImageDimensions((string) ($meta['requested_size'] ?? ''));

            $asset = $creative->createAsset($task->workspace_id, [
                'type'     => 'image',
                'prompt'   => (string) ($params['prompt'] ?? ($task->payload_json['prompt'] ?? '')),
                'task_id'  => $task->id,
                'metadata' => ['source' => 'orchestrator', 'revised_prompt' => $meta['revised_prompt'] ?? null],
            ]);
            $creative->completeAsset((int) $asset['asset_id'], [
                'url'       => $url,
                'width'     => $w,
                'height'    => $h,
                'mime_type' => 'image/png',
            ]);

            $this->progress->recordEvent($task->id, 'asset_persisted', null,
                message: 'Creative asset recorded (parity with EES path)');
        } catch (\Throwable $e) {
            // Best-effort — a persistence hiccup must not fail an otherwise
            // successful generation (mirrors CreativeService's own dual-write policy).
            Log::warning("[Orchestrator] creative asset persist failed for task {$task->id}: " . $e->getMessage());
        }
    }

    /**
     * Phase I — run a CreativeJob observational side-write with total isolation.
     * A CreativeJob fault MUST NEVER affect task execution, billing, or assets.
     */
    private function cjSafe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] hook error (ignored, execution unaffected): ' . $e->getMessage());
            return null;
        }
    }

    /** Parse a 'WxH' size string into [width, height]; default 1024x1024. */
    private function parseImageDimensions(string $size): array
    {
        if (preg_match('/^(\d+)x(\d+)$/', trim($size), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [1024, 1024];
    }

    /**
     * Execute a single step with idempotent check, retry, and verification.
     */
    /**
     * b27 — record (never block) an agent running an action it does not hold.
     *
     * Writes to the app log AND to engine_intelligence so the drift is queryable
     * rather than buried. Wholly best-effort: a fault here must never stop a task.
     */
    private function shadowCapabilityCheck(Task $task): void
    {
        try {
            $agents = json_decode((string) ($task->assigned_agents_json ?? '[]'), true) ?: [];
            $agent  = is_array($agents) ? ($agents[0] ?? null) : null;
            $action = (string) ($task->action ?? '');
            if (!$agent || $action === '') return;

            if (app(\App\Core\Agent\AgentCapabilityService::class)->canUse((string) $agent, $action)) {
                return;
            }

            \Illuminate\Support\Facades\Log::warning('[CapabilityShadow] agent ran an action it does not hold', [
                'task_id'      => $task->id,
                'workspace_id' => $task->workspace_id,
                'agent'        => $agent,
                'engine'       => $task->engine,
                'action'       => $action,
                'mode'         => 'shadow — not blocked',
            ]);
        } catch (\Throwable $e) {
            // never let an audit probe break execution
        }
    }

    private function executeStep(Task $task, string $action, array $params, array $capability, int $stepIndex): array
    {
        $connectorName = $capability['connector'];
        $connectorAction = $capability['action'];

        // 2026-08-11 ASYNC VIDEO RECONCILIATION — generate_video must run through
        // the governed CreativeService → ScenePlannerService → generateVideoViaProvider
        // → MiniMax path (the one hardened with the P0 mock gate, single-scene
        // constraint and the video:finalize-pending completion worker). The
        // CapabilityMap marks its connector as 'creative', which would send it to
        // CreativeConnector::execute('generate_video') → the legacy generateVideo()
        // that POSTs connectors.creative.base_url (localhost:8000) — a phantom path
        // that never reaches MiniMax. Route it to the SAME internal service dispatch
        // that creative/generate_image already uses, so there is ONE authoritative
        // provider path. Governance (kernel → approval → async task → Orchestrator →
        // credits → creative_jobs → asset lineage) is untouched.
        if ($task->engine === 'creative' && $action === 'generate_video') {
            $connectorName = null;
        }

        // Idempotent step check
        $stepHash = $this->idempotency->generateStepHash($task->id, $action, $stepIndex);
        $cached = $this->idempotency->checkStepCompleted($stepHash);
        if ($cached) {
            $this->progress->recordEvent($task->id, 'step_idempotent_skip', null,
                step: $stepIndex, action: $action, message: 'Step already completed — cached result used');
            return $cached;
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;

            try {
                // Execute
                if ($connectorName) {
                    $connector = $this->connectorResolver->resolve($connectorName);
                    $result = $connector->execute($connectorAction, $params);
                } else {
                    $result = $this->executeInternalAction($task, $action, $params);
                }

                $this->progress->recordEvent($task->id, 'step_executed', null,
                    step: $stepIndex, connector: $connectorName, action: $action,
                    message: $result['success'] ? 'Step executed' : 'Step execution returned failure',
                    data: ['attempt' => $attempt]);

                if (! $result['success']) {
                    $lastError = $result['message'];
                    // FIX 2026-05-11: poison-task short-circuit — don't burn 3 retries
                    // when the action explicitly says the failure is not retryable
                    // (bad payload, quota, validation, etc.)
                    if (isset($result['retryable']) && $result['retryable'] === false) {
                        break;
                    }
                    if ($attempt <= $this->maxRetries) {
                        usleep($attempt * 500_000);
                    }
                    continue;
                }

                // ── Verification ─────────────────────────────────────────
                if ($connectorName) {
                    $task->update(['status' => 'verifying']);

                    $verification = $connector->verifyResult($connectorAction, $params, $result);

                    $this->progress->recordEvent($task->id, 'step_verified', null,
                        step: $stepIndex, connector: $connectorName, action: $action,
                        message: $verification['verified'] ? 'Verification passed' : 'Verification FAILED: ' . $verification['message']);

                    if (! $verification['verified']) {
                        $result = [
                            'success' => false,
                            'data' => $verification['data'] ?? [],
                            'message' => 'Verification failed: ' . $verification['message'],
                        ];
                        $lastError = $result['message'];
                        if ($attempt <= $this->maxRetries) {
                            usleep($attempt * 500_000);
                        }
                        continue;
                    }

                    // Merge verified data
                    $result['data'] = $verification['data'];

                    $task->update(['status' => 'running']);
                }

                // Cache step result for idempotent replay
                $this->idempotency->recordStepResult($stepHash, $result);

                return $result;

            } catch (\InvalidArgumentException $e) {
                // 2026-05-31 — Validation errors are deterministic: retrying
                // won't help because the caller passed bad args. Fail fast.
                // Previously these consumed 3 retry attempts before giving up
                // (e.g. creative/generate_image_mini missing the prompt param
                // burned ~1.5s of waste + retry credits per stuck call).
                $lastError = $e->getMessage();
                $this->progress->recordEvent($task->id, 'step_error', null,
                    step: $stepIndex, action: $action,
                    message: "Bad input — failing fast (no retry): {$lastError}");
                return ['success' => false, 'data' => [], 'message' => "Bad input: {$lastError}"];

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->progress->recordEvent($task->id, 'step_error', null,
                    step: $stepIndex, action: $action,
                    message: "Attempt {$attempt} error: {$lastError}");

                if ($attempt <= $this->maxRetries) {
                    usleep($attempt * 500_000);
                }
            }
        }

        return ['success' => false, 'data' => [], 'message' => "Failed after {$attempt} attempts: {$lastError}"];
    }

    /**
     * Execute an internal (non-connector) action for a queued task.
     *
     * PATCH v1.0.2 — Option C: Orchestrator is now the single async execution
     * authority for ALL engine actions, including LLM-powered ones.
     *
     * GUARDRAILS enforced here:
     *   1. No pipeline duplication — credits, gating, audit are already handled
     *      by execute() before this method is called. This method ONLY dispatches.
     *   2. Strictly whitelisted map — action → [ServiceClass, methodName].
     *      No dynamic method resolution. User-controlled strings never reach call_user_func.
     *   3. Non-array return values (bool, int, Eloquent model) are normalised
     *      into the standard ['success', 'data', 'message'] shape before returning.
     *   4. All exceptions caught, normalised to failure result — worker never crashes.
     *   5. Result is JSON-safe by construction (Eloquent models cast via ->toArray()).
     *
     * EES (EngineExecutionService) remains the synchronous/manual execution path.
     * Orchestrator owns async/agent execution. The two paths do NOT overlap.
     *
     * To add a new action: add one entry to $dispatchMap below AND to
     * Orchestrator::dispatchableActions() (the LLM-facing catalog).
     */

    /**
     * Canonical, machine-readable catalog of every action that the
     * Orchestrator's $dispatchMap can actually execute. Two callers depend
     * on this:
     *   1. AgentMeetingEngine::extractPlanFromSynthesis() — feeds the list
     *      to the LLM so it only picks dispatchable actions.
     *   2. Future capability/dispatch consistency tests.
     *
     * Each entry is keyed by `<engine>/<action>` and carries a `params_hint`
     * string that tells the LLM what fields the engine service needs in the
     * task payload. Keep this in sync with the local $dispatchMap inside
     * executeInternalAction() — if you add a dispatch entry without listing
     * it here, the LLM can't see it; if you list it here without dispatch,
     * the orchestrator throws when the task fires.
     */
    public static function dispatchableActions(): array
    {
        return [
            // ── CRM ─────────────────────────────────────────────────
            'crm/create_lead'        => ['params_hint' => 'first_name (required), last_name, email, phone, source, company'],
            'crm/update_lead'        => ['params_hint' => 'lead_id (required), fields to update'],
            'crm/list_leads'         => ['params_hint' => 'optional: status, limit, offset'],
            'crm/score_lead'         => ['params_hint' => 'lead_id (required)'],
            'crm/assign_lead'        => ['params_hint' => 'lead_id (required), user_id (required)'],
            'crm/import_leads'       => ['params_hint' => 'leads (array, required)'],
            'crm/create_contact'     => ['params_hint' => 'first_name, email (required)'],
            'crm/create_deal'        => ['params_hint' => 'name, lead_id, value'],
            'crm/update_deal_stage'  => ['params_hint' => 'deal_id (required), stage (required)'],
            'crm/log_activity'       => ['params_hint' => 'lead_id or deal_id, type, notes'],
            'crm/add_note'           => ['params_hint' => 'lead_id, text (required)'],
            // ── SEO ─────────────────────────────────────────────────
            'seo/serp_analysis'      => ['params_hint' => 'query (required) — search query to analyze'],
            'seo/ai_report'          => ['params_hint' => 'url (required) — target page'],
            'seo/deep_audit'         => ['params_hint' => 'url (required) — full URL to audit'],
            'seo/improve_draft'      => ['params_hint' => 'article_id (required)'],
            'seo/write_article'      => ['params_hint' => 'topic (required), keyword, word_count'],
            'seo/link_suggestions'   => ['params_hint' => 'article_id or url (required)'],
            'seo/fix_orphans'        => ['params_hint' => 'no params (optional limit) — links orphan pages, self-bills 2cr/insert'],
            'seo/gsc_sync'           => ['params_hint' => 'no params (optional days, default 28) — pulls Search Console clicks/impressions/CTR/position into the workspace'],
            'seo/check_outbound'     => ['params_hint' => 'article_id or url (required)'],
            'seo/autonomous_goal'    => ['params_hint' => 'goal (required) — natural language objective'],
            'seo/add_keyword'        => ['params_hint' => 'keyword (required)'],
            'seo/list_keywords'      => ['params_hint' => 'optional: limit'],
            'seo/keyword_research'   => ['params_hint' => 'seed_keyword (required), market (optional)'],
            'seo/keywords_suggest'   => ['params_hint' => 'topic or seed (required)'],
            'seo/keyword_check'      => ['params_hint' => 'keyword (required)'],
            'seo/generate_links'     => ['params_hint' => 'article_id (required)'],
            // ── Write ───────────────────────────────────────────────
            'write/create_article'   => ['params_hint' => 'title (required), topic, keyword'],
            'write/write_article'    => ['params_hint' => 'topic (required), keyword (optional), word_count (optional)'],
            'write/improve_draft'    => ['params_hint' => 'article_id (required), instruction (optional)'],
            'write/generate_outline' => ['params_hint' => 'topic (required)'],
            'write/generate_headlines' => ['params_hint' => 'topic (required)'],
            'write/generate_meta'    => ['params_hint' => 'article_id (auto-filled from parent task)'],
            'write/aeo_enrich'       => ['params_hint' => 'article_id (auto-filled from parent task)'],
            'write/publish_article'  => ['params_hint' => 'article_id (required)'],
            'write/delete_article'   => ['params_hint' => 'article_id (required)'],
            // ── Social ──────────────────────────────────────────────
            'social/create_post'         => ['params_hint' => 'topic (required), platform (optional)'],
            'social/social_create_post'  => ['params_hint' => 'topic (required), platform (optional)'],
            'social/social_schedule_post' => ['params_hint' => 'post_id (required), scheduled_at (required)'],
            'social/social_publish_post' => ['params_hint' => 'post_id (required)'],
            'social/delete_post'         => ['params_hint' => 'post_id (required)'],
            'social/list_posts'          => ['params_hint' => 'optional: platform, status'],
            // ── Marketing ───────────────────────────────────────────
            'marketing/create_campaign'   => ['params_hint' => 'name (required), audience, channel'],
            'marketing/schedule_campaign' => ['params_hint' => 'campaign_id (required), scheduled_at'],
            'marketing/create_automation' => ['params_hint' => 'name, trigger, actions'],
            'marketing/list_campaigns'    => ['params_hint' => 'optional: status'],
            // ── Builder ─────────────────────────────────────────────
            'builder/create_website'   => ['params_hint' => 'name (required), industry'],
            'builder/generate_page'    => ['params_hint' => 'website_id (required), section_brief'],
            'builder/wizard_generate'  => ['params_hint' => 'website_id (required), prompt'],
            'builder/publish_website'  => ['params_hint' => 'website_id (required)'],
            // ── Calendar / Creative / Misc ──────────────────────────
            'calendar/create_event'    => ['params_hint' => 'title, start_at, end_at (required)'],
            'creative/generate_image'      => ['params_hint' => 'article_id (required, or explicit prompt), aspect (optional)'],
            'creative/generate_image_mini' => ['params_hint' => 'article_id (required, or explicit prompt) — small/fast image'],
            'creative/generate_image_high' => ['params_hint' => 'article_id (required, or explicit prompt) — hi-res image'],
            // ── Studio ───────────────────────────────────────────────
            'studio/generate_design'   => ['params_hint' => 'format (square|portrait|reel), industry, intent, headline_seed (all optional — Sarah grounds from brand kit + audit). Returns design draft.'],
            'studio/generate_image'    => ['params_hint' => 'prompt (required), aspect (1:1|9:16|16:9), style (modern|minimal|bold). Returns image URL for use in a Studio design.'],
            'studio/suggest_copy'      => ['params_hint' => 'context (required) — headline + sub + cta variants grounded in brand voice. Returns 3 copy options.'],
            // ── Email Builder (Phase 1) ─────────────────────────────
            'marketing/email_ai_generate'      => ['params_hint' => 'goal (sell|nurture|announce|onboard|reactivate), prompt (required). Brand kit auto-resolved from workspace. Returns AI-generated template with subject_a, subject_b, preview_text, blocks[]. Draft only — never sends.'],
            'marketing/email_block_rewrite'    => ['params_hint' => 'template_id, block_id, instruction (required) — rewrites a single block in workspace brand voice. Returns updated content_json.'],
            'marketing/email_subject_suggest'  => ['params_hint' => 'template_id, optional angle. Returns 5 subject-line variants for A/B testing, grounded in brand tone.'],
            'marketing/email_spam_check'       => ['params_hint' => 'template_id, subject. Rule-based deliverability score 0-20 across 8 spam dimensions. Free — no LLM.'],
            'marketing/email_preview_template' => ['params_hint' => 'template_id, variables (optional), format=desktop|mobile. Returns rendered HTML for inbox preview.'],
            'marketing/email_send_test'        => ['params_hint' => 'template_id, to_email, variables (optional). Sends a single test email. Approval-gated.'],
            'marketing/email_validate_campaign'=> ['params_hint' => 'campaign_id. Pre-flight check: missing variables, broken links, empty CTAs, spam score. Returns errors[] and warnings[].'],
            'marketing/email_use_template'     => ['params_hint' => 'template_id (system template). Clones a system template into the workspace as an editable copy.'],
            'marketing/email_template_picker'  => ['params_hint' => 'industry (optional), intent (optional), goal. AI ranks system templates by fit for the workspaces brand + use case. Returns top 5 with scores.'],
            'beforeafter/ba_transform' => ['params_hint' => 'image_url (required), transformation'],
            'beforeafter/create_design'=> ['params_hint' => 'prompt (required)'],
            'manualedit/create_canvas' => ['params_hint' => 'name (required)'],
            'traffic/create_rule'      => ['params_hint' => 'pattern (required), action'],
            'tasks/retry_blocked'      => ['params_hint' => 'no params — retries all blocked tasks for workspace'],
        ];
    }

    private function executeInternalAction(Task $task, string $action, array $params): array
    {
        $wsId = $task->workspace_id;

        // ── Whitelisted dispatch map ──────────────────────────────────────────
        // Key:   "{engine}/{action}" — engine-scoped to prevent cross-engine collisions.
        // Value: Closure that executes the action and ALWAYS returns array.
        //
        // Return normalisation rules:
        //   bool   → ['success' => $value, 'data' => []]
        //   int    → ['entity_id' => $value, 'data' => []]
        //   Model  → $model->toArray()
        //   array  → passed through directly
        // ─────────────────────────────────────────────────────────────────────
        // Wave 41b — moved BEFORE $dispatchMap so the maps arrow functions
        // (which capture $params by value at definition time) see the enriched
        // $params with the parents article_id / site_url / image_url merged in.
        // ── Wave 35b: parent_task_id passthrough ─────────────────────────────
        // When a task has a parent (e.g., generate_meta is the child of a
        // completed write_article task), pull the parent's result_json so
        // article_id / site_url / etc. flow into the child's params.
        if (!empty($task->parent_task_id)) {
            try {
                $parent = \Illuminate\Support\Facades\DB::table('tasks')
                    ->where('id', $task->parent_task_id)
                    ->where('status', 'completed')
                    ->first(['action', 'result_json']);
                if ($parent && $parent->result_json) {
                    $parentResult = json_decode($parent->result_json, true);
                    $inherit = $parentResult['data'] ?? $parentResult ?? [];
                    foreach (['article_id', 'website_id', 'page_id', 'site_url', 'image_url', 'image_alt'] as $k) {
                        if (!isset($params[$k]) && isset($inherit[$k])) {
                            $params[$k] = $inherit[$k];
                        }
                    }
                    // Wave 59c — defensive guard: chain children that need
                    // article_id (meta/image/links/insert/aeo_enrich) should
                    // inherit it from their parent. If they don't, the
                    // depends_on wiring is broken (parent is wrong action).
                    $needsArticleId = ['generate_meta', 'generate_image_mini', 'generate_image', 'generate_image_high',
                                       'link_suggestions', 'insert_link', 'aeo_enrich'];
                    if (in_array($task->action, $needsArticleId, true) && !isset($params['article_id'])) {
                        \Illuminate\Support\Facades\Log::warning('[Orchestrator] chain wiring suspicious: child needs article_id but parent did not provide it', [
                            'task_id' => $task->id,
                            'task_action' => $task->action,
                            'parent_task_id' => $task->parent_task_id,
                            'parent_action' => $parent->action ?? 'unknown',
                            'parent_result_keys' => is_array($inherit) ? array_keys($inherit) : 'non-array',
                        ]);
                    }
                }
            } catch (\Throwable $ptErr) {
                \Illuminate\Support\Facades\Log::warning('[Orchestrator] parent_task_id resolution failed', ['task_id' => $task->id, 'error' => $ptErr->getMessage()]);
            }
        }

        $dispatchMap = [
            // -- INFRA888 (2026-07-18) --------------------------------------
            // Executes an APPROVED infrastructure operation on the queue.
            // ProvisioningService is idempotent: a terminal operation returns
            // its prior result instead of re-executing.
            'infrastructure/provision_hosting' => function () use ($wsId, $params) {
                return app(\App\Engines\Infrastructure\Services\ProvisioningService::class)
                    ->execute($wsId, $params);
            },

            // ── CRM ──────────────────────────────────────────────────────────
            'crm/create_lead'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->createLead($wsId, $params)->toArray(),
            'crm/update_lead'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->updateLead($params['lead_id'], $params, $params['user_id'] ?? null, $wsId)->toArray(),
            // 2026-07-14 — move_lead was capability-mapped but had NO dispatch
            // executor (capability<->dispatch drift) -> died 'not supported'. It is a
            // pipeline-status move, so route it to updateLead like update_lead.
            'crm/move_lead'        => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        // normalise the target status: the LLM emits 'stage'/'to' for a move,
                                        // but updateLead only reads 'status' (else it is a silent no-op).
                                        ->updateLead((int) ($params['lead_id'] ?? 0), array_merge($params, ['status' => $params['status'] ?? $params['stage'] ?? $params['to'] ?? $params['pipeline_status'] ?? null]), $params['user_id'] ?? null, $wsId)->toArray(),
            // 2026-05-22 FIX 17 — list_leads. Same pattern as FIX 13 list_keywords.
            'crm/list_leads'       => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->listLeads($wsId, $params),
            'crm/score_lead'       => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->scoreLead($params['lead_id'], $params['score'] ?? null, $wsId)->toArray(),
            'crm/assign_lead'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->assignLead($params['lead_id'], $params['assigned_to'] ?? null, $params['user_id'] ?? null, $wsId)->toArray(),
            'crm/import_leads'     => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->importLeads($wsId, $params['rows'] ?? [], $params['user_id'] ?? null),
            'crm/create_contact'   => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->createContact($wsId, $params)->toArray(),
            'crm/create_deal'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->createDeal($wsId, $params)->toArray(),
            'crm/update_deal_stage'=> fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->updateDealStage($params['deal_id'], $params['stage'], $params['user_id'] ?? null, $wsId)->toArray(),
            'crm/log_activity'     => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->logActivity($wsId, $params)->toArray(),
            'crm/add_note'         => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->addNote($wsId, $params['entity_type'], $params['entity_id'], $params['body'], $params['user_id'] ?? null)->toArray(),

            // ── SEO ───────────────────────────────────────────────────────────
            'seo/serp_analysis'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->serpAnalysis($wsId, $params),
            'seo/ai_report'        => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->aiReport($wsId, $params),
            'seo/deep_audit'       => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->deepAudit($wsId, $params),
            'seo/improve_draft'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->improveDraft($wsId, $params),
            'seo/write_article'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->writeArticle($wsId, $params),
            'seo/link_suggestions' => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->generateLinkSuggestions($wsId, $params),
            // 2026-06-11 — first-class orphan fix (generate + apply orphan-first +
            // self-bill exact applied count). Lets Sarah delegate "fix the orphans"
            // as one credited task that runs through the keystone insert path.
            'seo/fix_orphans'      => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->fixOrphans($wsId, $params),
            'seo/gsc_sync'         => fn() => app(\App\Engines\SEO\Services\GscSyncService::class)
                                        ->sync($wsId, $params),
            // RISK-0091 (2026-08-25): competitor_serp/gaps were sync-only; add async so an
            // approved-plan task doesn't throw. Mirror the sync SeoService routing.
            'seo/competitor_serp'  => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->competitorSerp($wsId, $params),
            'seo/competitor_gaps'  => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->competitorGaps($wsId, $params),
            'seo/insert_link'      => function () use ($wsId, $params, $task) {
                // Wave 38c — chain mode: when article_id is set (parent_task passthrough)
                // and link_id is not, insert ALL pending seo_links suggestions for
                // that articles source URL. Otherwise fall back to the manual
                // single-link insertion (UI-driven flow).
                $svc = app(\App\Engines\SEO\Services\SeoService::class);
                if (!empty($params['link_id'])) {
                    return ['inserted' => $svc->insertLink($wsId, (int) $params['link_id'])];
                }
                // 2026-05-22 FIX 19a — article_id fallback. When auto-injected by FIX 2,
                // insert_link has no params and relies on Wave 35b passthrough, which
                // does NOT pick up article_id from write_article result_json. Look it
                // up explicitly from the parent task.
                if (empty($params['article_id']) && !empty($task) && !empty($task->parent_task_id)) {
                    $parentResult = \Illuminate\Support\Facades\DB::table('tasks')
                        ->where('id', $task->parent_task_id)
                        ->value('result_json');
                    if ($parentResult) {
                        $pr = json_decode($parentResult, true);
                        $aid = $pr['data']['article_id'] ?? null;
                        if ($aid) {
                            $params['article_id'] = (int) $aid;
                            \Illuminate\Support\Facades\Log::info('[insert_link] article_id resolved from parent task', [
                                'task_id' => $task->id, 'parent_task_id' => $task->parent_task_id, 'article_id' => $aid,
                            ]);
                        }
                    }
                }
                if (!empty($params['article_id'])) {
                    $article = \Illuminate\Support\Facades\DB::table('articles')
                        ->where('id', (int) $params['article_id'])
                        ->where('workspace_id', $wsId)
                        ->first(['slug', 'title']);
                    if ($article) {
                        $sourceUrl = null;
                        $idx = \Illuminate\Support\Facades\DB::table('seo_content_index')
                            ->where('workspace_id', $wsId)
                            ->where('url', 'like', '%/' . ($article->slug ?? '') . '%')
                            ->first(['url']);
                        if (!$idx && !empty($article->title)) {
                            $idx = \Illuminate\Support\Facades\DB::table('seo_content_index')
                                ->where('workspace_id', $wsId)
                                ->where('title', $article->title)
                                ->first(['url']);
                        }
                        if ($idx) {
                            $sourceUrl = $idx->url;
                            $pending = \Illuminate\Support\Facades\DB::table('seo_links')
                                ->where('workspace_id', $wsId)
                                ->where('source_url', $sourceUrl)
                                ->where('status', 'suggested')
                                ->orderByDesc('priority_score')
                                ->limit(5)
                                ->pluck('id')
                                ->toArray();
                            $insertedCount = 0;
                            foreach ($pending as $lid) {
                                try {
                                    if ($svc->insertLink($wsId, (int) $lid)) {
                                        $insertedCount++;
                                    }
                                } catch (\Throwable $ie) { /* per-link failure non-fatal */ }
                            }
                            return ['inserted_count' => $insertedCount, 'article_id' => (int) $params['article_id']];
                        }
                    }
                }
                return ['inserted' => false, 'reason' => 'no link_id or resolvable article_id'];
            },
            'seo/dismiss_link'     => fn() => ['dismissed' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->dismissLink($wsId, $params['link_id'] ?? 0)],
            'seo/check_outbound'   => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->checkOutbound($wsId, $params),
            'seo/autonomous_goal'  => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->createGoal($wsId, $params),
            'seo/pause_goal'       => fn() => ['paused' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->pauseGoal($wsId, $params['goal_id'] ?? 0)],
            'seo/resume_goal'      => fn() => ['resumed' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->resumeGoal($wsId, $params['goal_id'] ?? 0)],
            'seo/add_keyword'      => fn() => ['entity_id' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->addKeyword($wsId, $params)],
            // 2026-05-22 FIX 13 — Sarah needs a real list tool. SeoService::listKeywords
            // already exists; just wire it. Returns {keywords, usage, scan}.
            'seo/list_keywords'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->listKeywords($wsId, $params),

            // 2026-05-22 FIX 9 — stub handlers for SEO keyword/link actions
            // that FIX 1 exposed to Sarah's catalog but have no service impl.
            // These return a clear not-implemented payload so the task fails
            // gracefully instead of crashing with "No async handler".
            'seo/keyword_research' => fn() => [
                'success' => false,
                'not_implemented' => true,
                'message' => 'keyword_research is in the catalog but not yet wired to a service. Use seo/add_keyword + seo/serp_analysis as a workaround.',
            ],
            'seo/keywords_suggest' => fn() => [
                'success' => false,
                'not_implemented' => true,
                'message' => 'keywords_suggest is in the catalog but not yet wired to a service.',
            ],
            'seo/keyword_check' => fn() => [
                'success' => false,
                'not_implemented' => true,
                'message' => 'keyword_check is in the catalog but not yet wired to a service. Use seo/serp_analysis for ranking lookups.',
            ],
            'seo/generate_links' => fn() => [
                'success' => false,
                'not_implemented' => true,
                'message' => 'generate_links is in the catalog but not yet wired to a service. Use seo/link_suggestions instead.',
            ],

            // ── Write / Content ───────────────────────────────────────────────
            'write/create_article'      => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->createArticle($wsId, $params),
            'write/write_article'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->writeArticle($wsId, $params),
            'write/improve_draft'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->improveDraft($wsId, $params),
            'write/generate_outline'    => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->generateOutline($wsId, $params),
            'write/generate_headlines'  => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->generateHeadlines($wsId, $params),
            'write/generate_meta'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->generateMeta($wsId, $params),
            // Wave 45 — AEO Enrichment dispatch.
            'write/aeo_enrich'          => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->aeoEnrich($wsId, $params),

            // ── Builder ───────────────────────────────────────────────────────
            'builder/create_website'    => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->createWebsite($wsId, $params),
            'builder/generate_page'     => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->createPage($params['website_id'], $params),
            'builder/wizard_generate'   => fn() => app(\App\Engines\Builder\Services\ArthurService::class)
                                            ->buildFromChat($wsId, $params['build_data'] ?? $params, $params['logo_url'] ?? null, $params['images'] ?? [], $params['colors'] ?? [], $params['user_id'] ?? null),
            'builder/publish_website'   => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->publishWebsite($params['website_id']),
            // RISK-0088 (2026-08-25): async full-site build via the proven Arthur path.
            'builder/full_site_generation' => fn() => app(\App\Engines\Builder\Services\ArthurService::class)
                                            ->buildFromChat($wsId, $params['build_data'] ?? $params, $params['logo_url'] ?? null, $params['images'] ?? [], $params['colors'] ?? [], $params['user_id'] ?? null),

            // A1 (2026-06-23) — async handler for agent/approved Arthur page edits.
            // EngineExecutionService::execute had this inline, but approved tasks
            // run through the Orchestrator, which was MISSING it → Sarah-initiated
            // builder edits failed ("no handler for [builder/ai_builder_action]").
            // Routes to the SAME ArthurEditService as direct Arthur edits. Resolves
            // the target page: explicit page_id → website_id's home → the workspace's
            // most-recent website's home (best-effort, logged) — because agents
            // often dispatch with page_id=null.
            'builder/ai_builder_action' => fn() => (function () use ($params, $wsId) {
                $pageId = (int) ($params['page_id'] ?? 0);
                if ($pageId <= 0 && !empty($params['website_id'])) {
                    $pageId = (int) (\Illuminate\Support\Facades\DB::table('pages')
                        ->where('website_id', (int) $params['website_id'])
                        ->orderByDesc('is_homepage')->orderBy('position')->orderBy('id')
                        ->value('id') ?? 0);
                }
                if ($pageId <= 0) {
                    $pageId = (int) (\Illuminate\Support\Facades\DB::table('pages')
                        ->join('websites', 'websites.id', '=', 'pages.website_id')
                        ->where('websites.workspace_id', $wsId)
                        ->whereNull('websites.deleted_at')
                        ->orderByDesc('pages.is_homepage')
                        ->orderByDesc('websites.id')->orderBy('pages.position')
                        ->value('pages.id') ?? 0);
                    if ($pageId > 0) {
                        \Illuminate\Support\Facades\Log::warning('[Orchestrator] ai_builder_action: no page_id/website_id in payload — resolved to workspace home page', ['workspace_id' => $wsId, 'resolved_page_id' => $pageId]);
                    }
                }
                if ($pageId <= 0) {
                    throw new \RuntimeException('ai_builder_action: could not resolve a target page (provide page_id or website_id)');
                }
                return app(\App\Engines\Builder\Services\ArthurEditService::class)->editPage(
                    $pageId,
                    (string) ($params['command'] ?? $params['message'] ?? ''),
                    isset($params['section_index']) ? (int) $params['section_index'] : null,
                    ['workspace_id' => $wsId, 'agent_slug' => $params['agent'] ?? $params['agent_slug'] ?? 'sarah']
                );
            })(),

            // ── Marketing ─────────────────────────────────────────────────────
            'marketing/create_campaign'   => fn() => app(\App\Engines\Marketing\Services\MarketingService::class)
                                              ->createCampaign($wsId, $params),
            'marketing/schedule_campaign' => fn() => app(\App\Engines\Marketing\Services\MarketingService::class)
                                              ->scheduleCampaign($params['campaign_id'] ?? 0, $params['scheduled_at'] ?? ''),
            'marketing/create_automation' => fn() => ['entity_id' => app(\App\Engines\Marketing\Services\MarketingService::class)
                                              ->createAutomation($wsId, $params)],

            // ── Social ────────────────────────────────────────────────────────
            'social/social_create_post'   => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->createPost($wsId, $params),
            'social/create_post'          => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->createPost($wsId, $params),
            'social/social_schedule_post' => fn() => (function() use ($params) {
                                                app(\App\Engines\Social\Services\SocialService::class)
                                                    ->schedulePost($params['post_id'], $params['scheduled_at']);
                                                return ['scheduled' => true, 'post_id' => $params['post_id']];
                                              })(),
            'social/social_publish_post'  => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->publishPost($params['post_id']),

            // 2026-05-25 — publish + delete dispatches. Both ALWAYS require
            // approval (enforced at the chat-handler payload-build step so
            // they never auto-execute). Tasks land in pending_approval and
            // the user must click Approve before they run.
            'write/publish_article'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                              ->updateArticle((int) $params['article_id'], [
                                                  'status' => 'published',
                                              ]),
            'write/delete_article'        => fn() => (function () use ($params) {
                                                app(\App\Engines\Write\Services\WriteService::class)
                                                    ->deleteArticle((int) $params['article_id']);
                                                return ['deleted' => true, 'article_id' => (int) $params['article_id']];
                                              })(),
            'social/delete_post'          => fn() => (function () use ($params) {
                                                app(\App\Engines\Social\Services\SocialService::class)
                                                    ->deletePost((int) $params['post_id']);
                                                return ['deleted' => true, 'post_id' => (int) $params['post_id']];
                                              })(),

            // 2026-05-25 — Agent-triggered retry of blocked tasks. Runs
            // through TaskRetryService::retryBlockedForWorkspace which
            // applies the same idempotency check as the UI retry button.
            // Params: task_ids (optional array — if omitted, retries ALL
            // blocked for the workspace).
            'tasks/retry_blocked'         => fn() => app(\App\Core\TaskSystem\TaskRetryService::class)
                                              ->retryBlockedForWorkspace(
                                                  $wsId,
                                                  'agent:' . ($task->source ?? 'unknown'),
                                                  $params['task_ids'] ?? null
                                              ),

            // 2026-05-22 FIX 9 — social/list_posts was missing from the map.
            // SocialService::listPosts exists and is callable; just needed
            // the dispatch wire. Returns the post list for the workspace.
            'social/list_posts'           => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->listPosts($wsId, $params),

            // 2026-05-22 FIX 17 — marketing/list_campaigns.
            'marketing/list_campaigns' => fn() => app(\App\Engines\Marketing\Services\MarketingService::class)
                                        ->listCampaigns($wsId, $params),

            // ── Calendar ──────────────────────────────────────────────────────
            'calendar/create_event' => fn() => ['entity_id' => app(\App\Engines\Calendar\Services\CalendarService::class)
                                        ->createEvent($wsId, $params)],

            // ── BeforeAfter ───────────────────────────────────────────────────
            'beforeafter/ba_transform'   => fn() => app(\App\Engines\BeforeAfter\Services\BeforeAfterService::class)
                                             ->createDesign($wsId, $params),
            'beforeafter/create_design'  => fn() => app(\App\Engines\BeforeAfter\Services\BeforeAfterService::class)
                                             ->createDesign($wsId, $params),

            // ── Creative (Wave 35b: previously had no async dispatch map entries) ──
            'creative/generate_image'      => fn() => app(\App\Engines\Creative\Services\CreativeService::class)
                                             ->generateImage($wsId, $this->ensureImagePrompt($wsId, $params)),
            'creative/generate_image_mini' => fn() => app(\App\Engines\Creative\Services\CreativeService::class)
                                             ->generateImage($wsId, array_merge($this->ensureImagePrompt($wsId, $params), ['quality' => 'mini'])),
            'creative/generate_image_high' => fn() => app(\App\Engines\Creative\Services\CreativeService::class)
                                             ->generateImage($wsId, array_merge($this->ensureImagePrompt($wsId, $params), ['quality' => 'high'])),

            // 2026-08-11 — ASYNC VIDEO RECONCILIATION. The approved-async
            // generate_video path is routed here (executeStep forces
            // connectorName=null for creative/generate_video) so it runs the SAME
            // governed provider path as the sync kernel path (EES:805):
            // CreativeService::generateVideo → ScenePlannerService → MiniMax. Returns
            // {status:'in_progress', asset_id, job_ids}; the normalizer treats
            // in_progress as success and the video:finalize-pending worker completes
            // it. Supersedes the phantom CreativeConnector::generateVideo() HTTP path.
            // D2 (2026-08-13) — pass the task id through. Orchestrator links the
            // observational CreativeJob to its asset by looking the asset up via
            // assets.task_id (see 'Phase I — finalize the observational CreativeJob'
            // below). CreativeService::generateVideo() never received the task id, so
            // assets.task_id stayed NULL, the lookup returned NULL, creative_jobs
            // .asset_id stayed NULL and CreativeJobService::complete() never
            // back-filled assets.creative_job_id — a real generated video was
            // orphaned from Production and Assets.
            'creative/generate_video'      => fn() => app(\App\Engines\Creative\Services\CreativeService::class)
                                             ->generateVideo($wsId, $params + ['task_id' => $task->id]),

            // 2026-07-07 — BULK resolver: fan out featured-image tasks for every
            // article missing one (backend finds the real ids — Sarah never guesses).
            'write/fill_missing_images'    => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                             ->fillMissingImages($wsId, $params),

            // ── ManualEdit ────────────────────────────────────────────────────
            'manualedit/create_canvas'   => fn() => app(\App\Engines\ManualEdit\Services\ManualEditService::class)
                                             ->createCanvas($wsId, $params),

            // ── Traffic Defense ───────────────────────────────────────────────
            'traffic/create_rule'        => fn() => ['entity_id' => app(\App\Engines\TrafficDefense\Services\TrafficDefenseService::class)
                                             ->createRule($wsId, $params)],

            /* b7-orchestrator */
            // ── CRM AI (Batch 5) ──────────────────────────────────────────────
            'crm/generate_outreach'   => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                            ->generateOutreach($wsId, $params),
            'crm/generate_followup'   => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                            ->generateFollowUp($wsId, $params),
            'crm/ai_followup_draft'   => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                            ->generateFollowUp($wsId, $params),
            'crm/ai_reply_suggestion' => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                            ->aiReplySuggestion($wsId, $params),
            'crm/ai_lead_scoring'     => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                            ->aiLeadScoring($wsId, $params),

            // ── Social AI (Batch 1) ───────────────────────────────────────────
            'social/social_ai_post'      => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->aiGeneratePost($wsId, $params),
            'social/ai_generate_post'    => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->aiGeneratePost($wsId, $params),
            'social/hashtag_suggestions' => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->generateHashtags($wsId, $params),
            'social/generate_hashtags'   => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->generateHashtags($wsId, $params),
            'social/social_image'        => fn() => app(\App\Engines\Creative\Services\CreativeService::class)
                                              ->generateImage($wsId, array_merge(['style' => 'social_post', 'aspect' => '1:1'], $params)),

            // ── Marketing AI (Email Phase 1) ──────────────────────────────────
            'marketing/email_ai_generate' => fn() => app(\App\Engines\Marketing\Services\EmailBuilderService::class)
                                                ->aiGenerate($wsId, $params),

            // ── Studio AI (Studio Phase 1) ────────────────────────────────────
            'studio/generate_design' => fn() => app(\App\Engines\Studio\Services\StudioAiService::class)
                                            ->generateDesign($wsId, $params),
            'studio/generate_image'  => fn() => app(\App\Engines\Studio\Services\StudioAiService::class)
                                            ->generateImage($wsId, $params),
            'studio/suggest_copy'    => fn() => app(\App\Engines\Studio\Services\StudioAiService::class)
                                            ->suggestCopy($wsId, $params),

            // ── ContentPack (Batch 3 — H1) ────────────────────────────────────
            'content/create_pack'  => fn() => app(\App\Engines\Content\Services\ContentPackService::class)
                                          ->createPack($wsId, $params),
            'content/add_asset'    => fn() => app(\App\Engines\Content\Services\ContentPackService::class)
                                          ->addAsset($wsId, $params),
            'content/get_pack'     => fn() => app(\App\Engines\Content\Services\ContentPackService::class)
                                          ->getPack($wsId, (int) ($params['pack_id'] ?? 0)),
            'content/list_packs'   => fn() => app(\App\Engines\Content\Services\ContentPackService::class)
                                          ->listPacks($wsId, $params),
            'content/publish_pack' => fn() => app(\App\Engines\Content\Services\ContentPackService::class)
                                          ->publishPack($wsId, $params),

            // ── Sarah orchestrator (Batch 4) ──────────────────────────────────
            'sarah/draft_campaign' => fn() => app(\App\Core\Orchestration\SarahCampaignOrchestrator::class)
                                          ->draftCampaign($wsId, $params),

        ];

        // ── Lookup ────────────────────────────────────────────────────────────
        $key = "{$task->engine}/{$action}";
        $handler = $dispatchMap[$key] ?? null;

        // 2026-07-23 — the LLM sometimes mislabels the engine (e.g.
        // fill_missing_images under 'creative' instead of 'write'), which misses
        // the dispatch key and fails as "not supported". Fall back to the
        // capability map's CANONICAL engine for this action before giving up.
        if ($handler === null) {
            $__cap = $this->capabilityMap->resolve($action);
            if ($__cap && !empty($__cap['engine']) && $__cap['engine'] !== $task->engine) {
                $__altKey = "{$__cap['engine']}/{$action}";
                if (isset($dispatchMap[$__altKey])) {
                    $handler = $dispatchMap[$__altKey];
                    Log::info("Orchestrator — engine normalized [{$key}] -> [{$__altKey}]", ['task_id' => $task->id]);
                }
            }
        }

        if ($handler === null) {
            Log::warning("Orchestrator::executeInternalAction — no handler for [{$key}]", [
                'task_id' => $task->id, 'engine' => $task->engine, 'action' => $action,
                'category' => $task->category ?? null,  // 2026-05-27 — surface category in logs
            ]);
            // 2026-05-30 — no_schema_leakage: the engine/action slug + the
            // reference to dispatchMap is engineering-internal and used to
            // surface to end users via the deliverable JSON. Friendly copy
            // here; the technical details remain in the Log::warning above.
            return [
                'success' => false,
                'data'    => [],
                'message' => "This action isn't supported yet — please flag it so we can wire it up.",
            ];
        }

        // ── Execute with normalisation and error containment ──────────────────
        try {
            $raw = $handler();

            // Normalise: ensure result is always a JSON-safe array
            if ($raw instanceof \Illuminate\Database\Eloquent\Model) {
                $raw = $raw->toArray();
            } elseif (is_bool($raw)) {
                $raw = ['result' => $raw];
            } elseif (is_int($raw) || is_string($raw)) {
                $raw = ['entity_id' => $raw];
            } elseif (! is_array($raw)) {
                $raw = ['result' => (string) $raw];
            }

            // v1.4.4 (2026-05-30) — no_schema_leakage rule: never surface
            // "Action [engine/action] completed" to end users. The friendly
            // summary is rendered by DeliverableSummary::summaryHtmlFor()
            // anyway; this message is internal telemetry only. Map known
            // actions to conversational phrasing; fall back to a generic
            // "Done." rather than leaking the slug.
            // 2026-06-10 — STATUS ≠ TRUTH keystone fix. Some services RETURN a
            // failure result instead of throwing — e.g. CreativeService::generateImage
            // returns ['status'=>'failed','error'=>'Generation failed'] when the
            // image provider fails. Previously that was wrapped as success:true and
            // the task marked COMPLETED with no deliverable (the article-136
            // ghost-completion), which then let Sarah report "done" for work that
            // never happened. Detect a returned failure and propagate it so the
            // task is marked FAILED, not completed.
            $innerFailed = (isset($raw['success']) && $raw['success'] === false)
                || (isset($raw['status']) && in_array(strtolower((string) $raw['status']), ['failed', 'error'], true));
            if ($innerFailed) {
                $reason = $raw['error'] ?? $raw['message'] ?? 'action reported failure';
                return [
                    'success'   => false,
                    'data'      => $raw,
                    'message'   => is_string($reason) && $reason !== '' ? $reason : 'action reported failure',
                    'retryable' => false, // a returned (non-thrown) failure is usually deterministic
                ];
            }
            // 2026-06-20 (forensic: Chef Red orphan loop) — a handler can
            // return success while changing NOTHING (fix_orphans applied:0,
            // insert_link inserted_count:0/inserted:false). Surfacing that as a
            // plain success let Sarah report "done" for work that never
            // happened. Flag it so the read-back + narration layers stay honest.
            $noChange = self::resultChangedNothing($key, is_array($raw) ? $raw : []);
            return [
                'success'   => true,
                'data'      => $raw,
                'message'   => self::friendlyMessageFor($key, is_array($raw) ? $raw : [], $noChange),
                'no_change' => $noChange,
            ];

        } catch (\Throwable $e) {
            // Catch everything — do not crash the queue worker.
            // The Orchestrator's outer execute() will catch this and mark the task failed.
            Log::error("Orchestrator::executeInternalAction failed [{$key}]", [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw so Orchestrator marks task failed and releases credits
        }
    }

    private function resolveSteps(Task $task, array $params): array
    {
        if (isset($params['steps']) && is_array($params['steps'])) {
            return array_map(fn ($s) => [
                'action' => $s['action'] ?? $task->action,
                'params' => $s['params'] ?? [],
            ], $params['steps']);
        }

        return [['action' => $task->action, 'params' => $params]];
    }

    private function extractAgentSlug(Task $task): ?string
    {
        if ($task->source !== 'agent') return null;
        $agents = $task->assigned_agents_json ?? [];
        return $agents[0] ?? null;
    }

    /**
     * Sanitize payload for audit — strip secrets.
     */
    private function sanitizeAuditPayload(array $data): array
    {
        $sensitiveKeys = ['api_key', 'token', 'secret', 'password', 'authorization', 'credential'];

        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $value = '***REDACTED***';
                }
            }
        });

        return $data;
    }

    /**
     * 2026-07-06 — Derive a featured-image prompt from the article when a
     * creative image task carries an article_id (or title) but no explicit
     * prompt. CreativeService (LOCKED) hard-requires `prompt`; the documented
     * contract for these actions is "article_id (required) OR explicit prompt",
     * so building the prompt is the Orchestrator's job (plumbing, not creative
     * logic). Without this, Sarah-chat / proposal image tasks that pass only
     * article_id fail with "Prompt required". Any caller that already supplies
     * a prompt is untouched.
     */
    private function ensureImagePrompt(int $wsId, array $params): array
    {
        if (!empty($params['prompt'])) return $params;

        $title = $params['title'] ?? null;
        $keyword = null;

        $aid = $params['article_id'] ?? null;
        if ($aid) {
            $art = \Illuminate\Support\Facades\DB::table('articles')
                ->where('workspace_id', $wsId)
                ->where('id', (int) $aid)
                ->first(['title', 'focus_keyword', 'blog_category']);
            if ($art) {
                $title   = $art->title ?: $title;
                $keyword = $art->focus_keyword ?: ($art->blog_category ?: null);
            }
        }

        // Strip the "Featured image for " prefix the chat handler prepends.
        if (is_string($title)) {
            $title = trim(preg_replace('/^\s*Featured image for\s*/i', '', $title));
        }

        if (!empty($title)) {
            $params['prompt'] = "Professional, photorealistic featured blog image for an article titled \"{$title}\"."
                . ($keyword ? " Theme: {$keyword}." : '')
                . " Clean, modern, editorial style. No text, no words, no logos in the image.";
        }

        return $params;
    }

    /**
     * v1.4.4 (2026-05-30) — Map internal action keys to user-facing copy.
     * Used by the deliverable envelope so no_schema_leakage rule is upheld
     * (no "Action [creative/generate_image_mini] completed" surfaces).
     */
    /**
     * 2026-06-20 (forensic: Chef Red orphan loop) — true when a handler
     * succeeded but changed nothing, so the deliverable envelope can stay
     * honest instead of reporting a no-op as a win.
     */
    private static function resultChangedNothing(string $key, array $raw): bool
    {
        switch ($key) {
            case 'seo/fix_orphans':
                return (int) ($raw['applied'] ?? 0) === 0
                    && (int) ($raw['orphans_before'] ?? 0) > 0;
            case 'seo/insert_link':
                if (array_key_exists('inserted_count', $raw)) return (int) $raw['inserted_count'] === 0;
                if (array_key_exists('inserted', $raw)) return $raw['inserted'] === false;
                return false;
            default:
                if (array_key_exists('changed', $raw)) return $raw['changed'] === false;
                return false;
        }
    }

    /** No-op copy used when resultChangedNothing() is true. */
    private const NOOP_MESSAGES = [
        'seo/fix_orphans'    => 'I checked the orphan pages but found no internal links I could safely auto-insert — they need a manual link or a fresh piece of linking content. Want me to draft one?',
        'seo/insert_link'    => 'No internal link was inserted — I could not find a suitable, natural placement.',
        'seo/generate_links' => 'No new internal-link opportunities were found right now.',
    ];

    private static function friendlyMessageFor(string $key, array $raw = [], bool $noChange = false): string
    {
        if ($noChange) {
            return self::NOOP_MESSAGES[$key] ?? 'Ran, but nothing needed changing.';
        }
        static $map = [
            'creative/generate_image'      => 'Image generated.',
            'studio/generate_design'      => 'Design draft created.',
            'studio/generate_image'       => 'Studio image generated.',
            'studio/suggest_copy'         => 'Copy options drafted.',
            'marketing/email_ai_generate'      => 'Email draft created.',
            'marketing/email_block_rewrite'    => 'Block rewritten.',
            'marketing/email_subject_suggest'  => 'Subject lines drafted.',
            'marketing/email_spam_check'       => 'Spam check complete.',
            'marketing/email_preview_template' => 'Preview rendered.',
            'marketing/email_send_test'        => 'Test email queued.',
            'marketing/email_validate_campaign'=> 'Campaign validated.',
            'marketing/email_use_template'     => 'Template copied to workspace.',
            'marketing/email_template_picker'  => 'Template recommendations ready.',
            'creative/generate_image_mini' => 'Image generated.',
            'creative/generate_image_high' => 'High-quality image generated.',
            'creative/generate_video'      => 'Video generated.',
            'write/write_article'          => 'Article drafted.',
            'write/improve_draft'          => 'Draft improved.',
            'write/generate_meta'          => 'Meta title and description generated.',
            'write/generate_outline'       => 'Article outline generated.',
            'write/aeo_enrich'             => 'AI search optimization added.',
            'write/publish_article'        => 'Article published.',
            'write/delete_article'         => 'Article deleted.',
            'seo/run_audit'                => 'SEO audit completed.',
            'seo/deep_audit'               => 'Technical SEO audit completed.',
            'seo/track_keywords'           => 'Keyword tracking refreshed.',
            'seo/serp_analysis'            => 'SERP analysis completed.',
            'seo/generate_article'         => 'Article queued for writing.',
            'seo/generate_links'           => 'Internal link suggestions generated.',
            'seo/insert_link'              => 'Internal link inserted.',
            'seo/fix_orphans'              => 'Orphan pages linked into the site.',
            'seo/gsc_sync'                 => 'Latest Google Search Console data pulled in.',
            'social/create_post'           => 'Social post created.',
            'social/update_post'           => 'Social post updated.',
            'social/schedule_post'         => 'Social post scheduled.',
            'social/publish_post'          => 'Social post published.',
            'social/delete_post'           => 'Social post removed.',
            'crm/create_lead'              => 'Lead added.',
            'crm/create_contact'           => 'Contact added.',
            'crm/update_lead'              => 'Lead updated.',
            'crm/score_lead'               => 'Lead scored.',
            'crm/generate_outreach'        => 'Outreach email drafted.',
            'studio/export_design'         => 'Design exported.',
            'studio/create_design'         => 'New design started.',
            'studio/publish_social'        => 'Design published to social.',
            // EM-7/12: the request only ACCEPTS the campaign; the worker sends it.
            'marketing/send_campaign'      => 'Email campaign accepted for sending.',
            'marketing/schedule_campaign'  => 'Email campaign scheduled.',
            'marketing/update_campaign'    => 'Email campaign updated.',
            'builder/wizard_generate'      => 'Website generated.',
            'builder/create_website'       => 'Website created.',
            'builder/generate_page'        => 'Page generated.',
            'builder/add_page_from_template' => 'New page added from template.',
            'builder/update_page'          => 'Page updated.',
            'builder/publish_website'      => 'Website published.',
            'calendar/create_event'        => 'Event added to calendar.',
            'tasks/retry_blocked'          => 'Blocked tasks retried.',
        ];
        return $map[$key] ?? 'Done.';
    }
}

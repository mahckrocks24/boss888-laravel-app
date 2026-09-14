<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        then: function () {
            Route::middleware('api')
                ->group(base_path('routes/exec-api.php'));
        },
    )
    ->withSchedule(function (Schedule $schedule) {

        // SOCIAL-888 (2026-09-04) — canonical social scheduled-publish worker.
        // Dry-run (MockTransport) until config('publisher.live_transport'); approval-gated,
        // idempotent (terminal-state suppression), so a per-minute re-run never double-posts.
        $schedule->command('social:publish-due')
            ->name('social:publish-due')
            ->everyMinute()
            ->withoutOverlapping();

        // PUBLISHER888 Unit 1 (2026-09-04) — desk-scheduled stories go live on time.
        // RESUME888 — retention sweep for the resume builder.
        $schedule->command('resume:purge')->name('resume:purge')->dailyAt('03:20')->withoutOverlapping();

        $schedule->command('publisher:publish-scheduled')
            ->name('publisher:publish-scheduled')
            ->everyMinute()
            ->withoutOverlapping();

        // EXPERIENCE888 (2026-08-13) - the learning loop must sustain itself.
        // Ingestion turns authoritative task/approval/commitment rows into typed
        // experience; it is idempotent via dedupe_key, so an hourly re-read of
        // the same rows creates nothing and cannot inflate a sample size.
        // A single workspace failure is caught inside the command and never
        // aborts the sweep.
        $schedule->command('experience888:ingest --all')
            ->name('experience888:ingest')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('experience888:ingest cron failed');
            });

        // Daily re-evaluation so ageing actually takes effect. Deterministic and
        // rerunnable: the verdict is a pure function of evidence and dates, so a
        // second run the same day changes nothing. Nothing is deleted - only
        // confidence and status move, and every change is logged.
        $schedule->command('experience888:decay --all')
            ->name('experience888:decay')
            ->dailyAt('03:20')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('experience888:decay cron failed');
            });

        // PHASE 1C (2026-07-30) — platform event processing: reap stale claims,
        // fan out recorded events, run due deliveries. ONE command rather than
        // three schedule entries, so the three stages cannot race each other.
        //
        // Every stage inside is independently flag-gated and defaults to OFF, so
        // with the flags off this entry is a proven no-op (see ActivationGateTest).
        // ->withoutOverlapping() is belt-and-braces: the command also takes its
        // own cache lock, because a scheduler restart can clear the mutex.
        $schedule->command('platform-events:process')
            ->name('platform-events:process')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('platform-events:process cron failed');
            });

        // 2026-08-10 — video completion worker. Drives in_progress video assets to
        // completion WITHOUT a browser (poll provider → download → stitch → finalize
        // → settle credits), so a closed tab can never strand a job or its reserved
        // credit. Idempotent + bounded (see VideoFinalizePendingCommand). No video
        // assets in flight ⇒ proven no-op.
        $schedule->command('video:finalize-pending')
            ->name('video:finalize-pending')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('video:finalize-pending cron failed');
            });

        // 2026-05-24 FIX 53C — removed legacy `sarah:proactive --type=daily`
        // cron entry. It was a lightweight pending-approval reminder at
        // 08:00 UTC that duplicated the new TZ-gated sarah:morning-brief.
        // The underlying ProactiveStrategyEngine::dailyCheck() method
        // remains callable via API + tests.

        $schedule->call(function () {
            $count = app(\App\Core\Billing\TrialService::class)->processExpiredTrials();
            \Illuminate\Support\Facades\Log::info("Trial expiry cron: {$count} trial(s) expired");
        })
            ->name('trial:expire')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Trial expiry cron failed');
            });

        // MISSION-018 WS-2 (2026-08-24) — the ops tripwire. Scans the recent
        // log window + failed_jobs and emails admin@ on threshold crossings;
        // 60-minute per-reason cooldown lives in the command. Proven live:
        // quiet window silent, forced crossing sent (Postmark accepted),
        // immediate repeat suppressed by cooldown.
        $schedule->command('ops:log-alert')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('ops:log-alert scheduler run failed');
            });

        // MISSION-018 WS-1 (2026-08-24, RISK-0047): NO schedule added here — a
        // tasks:recover-orphans entry ALREADY exists below (added after the
        // RISK-0047 record was written, which is why the record's "no scheduler
        // entry" claim is stale). The real and only defect was the predicate,
        // fixed in TaskStateMachine::detectOrphans; the pre-existing schedule
        // now actually recovers orphans through the corrected predicate.

        // DFS-F1 (2026-06-21) — rank tracking moved daily→WEEKLY (Mon 02:00 UTC,
        // ahead of the week's sarah:weekly-review). seo:track-ranks is the SINGLE
        // consolidated rank-position command (depth 20, see TrackKeywordRanksCommand).
        $schedule->command('seo:track-ranks')
            ->name('seo:track-ranks')
            ->weeklyOn(1, '02:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('SEO rank tracking cron failed');
            });

        // DFS-F1 (2026-06-21) — seo:rank-track UNSCHEDULED. It duplicated
        // seo:track-ranks' rank check AND called keywordData per-keyword DAILY
        // ($0.05/call) — the dominant DataForSEO cost leak. Rank position now
        // comes from seo:track-ranks (weekly); search volume/difficulty/cpc now
        // comes from seo:refresh-volume (monthly, batched — one call/workspace).
        // The command file is retained for manual runs.
        $schedule->command('seo:refresh-volume')
            ->name('seo:refresh-volume')
            ->monthlyOn(1, '02:15')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:refresh-volume cron failed');
            });

        // AGENT VOICE (2026-07-19) — the delegated agents report their own
        // completed work. Before this, 17 of 20 agents had NEVER posted a
        // message while `tasks.assigned_agents_json` showed priya alone owning
        // 900 tasks in ws2. Completions only, every agent except Sarah, always
        // batched and framed as work Sarah delegated. Runs :20 past the hour so
        // it lands after sarah:auto-execute (:00) has finished spawning work.
        $schedule->command('agents:report-completions')
            ->name('agents:report-completions')
            ->hourlyAt(20)
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('agents:report-completions cron failed');
            });

        // Wave 49b — daily AEO score snapshot.
        $schedule->command('aeo:snapshot')
            ->name('aeo:snapshot')
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('aeo:snapshot cron failed');
            });

        // GSC Phase 2 — daily Search Console sync for every connected workspace
        // (trailing 28d; idempotent upsert into gsc_metrics). Runs after the
        // GSC data-finalisation lag window.
        $schedule->command('gsc:sync-all')
            ->name('gsc:sync-all')
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('gsc:sync-all cron failed');
            });

        // 2026-07-01 — sync real GSC positions into seo_keywords.current_rank so
        // Sarah's gatherer / opportunity-zone rule / goal tracking read TRUE ranks
        // (DataForSEO seo:track-ranks has been failing). Runs AFTER gsc:sync-all.
        $schedule->command('seo:sync-gsc-ranks')
            ->name('seo:sync-gsc-ranks')
            ->dailyAt('05:20')
            ->withoutOverlapping()
            ->onOneServer()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:sync-gsc-ranks cron failed');
            });

        // Wave 52 — daily catch-up reindex of builder pages.
        $schedule->command('seo:reindex-builder-pages')
            ->name('seo:reindex-builder-pages')
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Wave 49c — daily aeo_traffic retention cleanup (90-day window).
        $schedule->command('aeo:cleanup-traffic')
            ->name('aeo:cleanup-traffic')
            ->dailyAt('04:15')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // DFS-F1 (2026-06-21) — SERP context refresh moved daily→WEEKLY (Mon 02:30).
        $schedule->command('seo:serp-refresh')
            ->name('seo:serp-refresh')
            ->weeklyOn(1, '02:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:serp-refresh cron failed');
            });

        // 2026-05-13 — migrated from app/Console/Kernel.php which never
        // fires in Laravel 11. Original schedules preserved verbatim.
        $schedule->command('seo:insights')
            ->name('seo:insights')
            ->daily()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:insights cron failed');
            });

        $schedule->command('seo:authority-score')
            ->name('seo:authority-score')
            ->dailyAt('03:02')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:authority-score cron failed');
            });

        // Wave 1 (2026-05-17) — 90-day retention purge for SEO assistant
        // chat history, per AI Assistant Operating Rules.
        $schedule->command('seo:purge-chat-history')
            ->name('seo:purge-chat-history')
            ->dailyAt('04:02')
            ->withoutOverlapping()
            ->onOneServer()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:purge-chat-history cron failed');
            });

        $schedule->command('seo:outbound-check')
            ->name('seo:outbound-check')
            ->twiceDaily(2, 14)
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:outbound-check cron failed');
            });

        $schedule->command('seo:cluster')
            ->name('seo:cluster')
            ->weeklyOn(0, '04:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:cluster cron failed');
            });

        // House account credit replenish — 1st of month at midnight UTC
        $schedule->command('credits:replenish-house')
            ->name('credits:replenish-house')
            ->monthlyOn(1, '00:00')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('House account credit replenish failed');
            });

        // House account weekly proactive — Monday 4am UTC (8am UAE)
        $schedule->command('house:weekly-proactive')
            ->name('house:weekly-proactive')
            ->weeklyOn(1, '04:00')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('House account proactive check failed');
            });

        // 2026-05-24 FIX 53C — removed legacy `sarah:proactive --type=weekly`
        // and `--type=monthly` cron entries. They were redundant with the
        // new TZ-gated sarah:weekly-review (richer LLM-synthesized retro
        // + pivot proposals) and sarah:monthly-strategy (multi-agent
        // strategy meeting). Underlying ProactiveStrategyEngine methods
        // (weeklyReview, monthlyStrategy) remain callable via API.

        // 2026-05-24 FIX 46 — Sarah's daily morning brief. Runs the
        // full cross-engine orchestration cycle: gather state from all
        // 13 engines + tier + goals + pipeline, send to runtime for
        // synthesis, persist proposed actions, post composite brief
        // to chat. One message per workspace, one approval batch.
        // 2026-05-24 FIX 53 — hourly + per-workspace TZ-gated. The
        // command itself filters by Carbon::now($ws->timezone)->hour===8
        // and uses workspace_memory for idempotency so the hourly cron
        // doesn't double-post.
        $schedule->command('sarah:morning-brief')
            ->name('sarah:morning-brief')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sarah morning brief failed');
            });

        // 2026-05-24 FIX 47 — Sarah's weekly retrospective. Reads past
        // 7 days outcomes across all engines, posts wins/losses + pivot
        // proposals every Monday at 09:00 UTC.
        // 2026-05-24 FIX 53B — hourly + per-workspace TZ-gated.
        // Command filters to Mon 09:00 in each workspace's local time
        // with workspace-local-week idempotency.
        $schedule->command('sarah:weekly-review')
            ->name('sarah:weekly-review')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sarah weekly review failed');
            });

        // 2026-05-24 FIX 47 — Sarah's monthly strategy meeting. Runs
        // 1st of every month at 10:00 UTC. Reads 30-day outcomes,
        // facilitates multi-agent strategy meeting (Sarah + James +
        // Priya + Marcus + Vera + Elena via runtime), posts refreshed
        // 30-day plan. Costs 8cr (canonical strategy meeting price).
        // 2026-05-24 FIX 53B — hourly + per-workspace TZ-gated.
        // Command filters to 1st-of-month 10:00 in each workspace's
        // local time with workspace-local-month idempotency.
        $schedule->command('sarah:monthly-strategy')
            ->name('sarah:monthly-strategy')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sarah monthly strategy failed');
            });

        // Notification system retention — purge notifications older than 90 days
        $schedule->command('lu:notifications:purge')
            ->name('lu:notifications:purge')
            ->dailyAt('00:05')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Notifications purge cron failed');
            });

        // PATCH 7 (2026-05-08) — email-sequence runner.
        // Fires every 15 minutes; finds due steps for active enrollments
        // and sends through the existing Postmark mailer.
        // LAUNCH SCOPE (2026-07-20) — email-sequence drip cron DISABLED (email
        // marketing removed from launch). RunSequences::handle() also refuses
        // internally. Restore both to re-enable.
        // $schedule->command('lu:sequences:run')->everyFifteenMinutes()...(disabled)

        // PATCH 8 (2026-05-08) — prune builder snapshot history weekly
        // (30-day retention). ManualEdit canvas states (page_id IS NULL)
        // are NOT pruned by this — separate lifecycle.
        $schedule->call(function () {
            $deleted = app(\App\Engines\Builder\Services\BuilderSnapshotService::class)
                ->pruneOldSnapshots(30);
            \Illuminate\Support\Facades\Log::info("builder:prune-snapshots removed {$deleted} rows");
        })->name('builder:prune-snapshots')->weekly();

        // PATCH (Intel Fix 3) — credit orphan reaper.
        // CreditService::findOrphanedReservations(30) exists but no scheduler
        // ever called it, leaving reservations stuck "pending" indefinitely.
        // 6 reservations from 2026-05-06 froze 60 credits for ws=1 — released
        // by hand at patch deploy time; this scheduler entry prevents recurrence.
        // Runs hourly; releases any reservation older than 30 minutes still
        // in pending status.
        $schedule->call(function () {
            $svc = app(\App\Core\Billing\CreditService::class);
            $orphans = $svc->findOrphanedReservations(30);
            $released = 0;
            foreach ($orphans as $orphan) {
                try {
                    $svc->release((int) $orphan->workspace_id, $orphan->reservation_reference);
                    $released++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('credits:reap-orphans release failed', [
                        'reservation_ref' => $orphan->reservation_reference ?? null,
                        'workspace_id'    => $orphan->workspace_id ?? null,
                        'error'           => $e->getMessage(),
                    ]);
                }
            }
            if ($released > 0) {
                \Illuminate\Support\Facades\Log::info("credits:reap-orphans released {$released} orphan reservation(s)");
            }
        
        })->name('credits:reap-orphans')->hourly()->withoutOverlapping();

        /* B34: mention:scan-daily */
        // Hourly tick. Internal logic handles per-plan daily caps and the
        // 24h cooldown per watchlist row, so running every hour is safe and
        // necessary (workspaces span timezones; daily UTC fire would skew).
        // LAUNCH SCOPE (2026-07-20) — social-listening cron DISABLED (mentions
        // removed). MentionScanService::scanWatchlist() also refuses internally.
        // $schedule->command('mention:scan-daily')->hourly()...(disabled)

        // PATCH (Phase 2C, 2026-05-10) — task orphan reaper. Tasks stuck in
        // running / queued > 30 min usually mean a worker crashed or Redis
        // was flushed mid-job. recoverOrphans() marks them as failed with a
        // clear reason so the dashboard reflects real state.
        $schedule->call(function () {
            try {
                $recovered = app(\App\Core\Orchestration\TaskStateMachine::class)->recoverOrphans(30);
                if ($recovered > 0) {
                    \Illuminate\Support\Facades\Log::info("tasks:recover-orphans recovered {$recovered} orphan task(s)");
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('tasks:recover-orphans failed: ' . $e->getMessage());
            }
        })->name('tasks:recover-orphans')->everyFifteenMinutes()->withoutOverlapping();

        // 2026-06-30 — surface Sarah's approvable proposals into the approvals queue.
        $schedule->command('proposals:sync-approvals')->everyFifteenMinutes()->withoutOverlapping();

        // 2026-07-02 — video-render safety net: fail exports stuck in
        // pending/processing past the timeout so the editor never shows a render
        // "processing forever" (worker death / killed ffmpeg).
        // INFRA888 - recover stuck infrastructure operations (same reaper
        // pattern as credits:reap-orphans / tasks:recover-orphans).
        $schedule->command('infra:reap-operations')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // INFRA888 Phase 2B-CERT - credential expiry sweep.
        // HOURLY, not sub-hourly: expiry is measured in days, so a finer
        // cadence adds load and log noise without detecting anything sooner.
        // Contacts no provider; reads stored expiry timestamps only. Idempotent,
        // so an overlapping or repeated run emits no duplicate events.
        $schedule->command('infra:sweep-credential-expiry')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // Phase 3A — infrastructure monitoring (real HTTP GETs, read-only, no
        // customer mutation). Every 5 min, each check in its own tenant context.
        $schedule->command('infra:run-monitor-checks')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Phase 3B — daily monitor rollup (retention/archival). Hourly with a
        // trailing window so the current day stays current; idempotent.
        $schedule->command('infra:rollup-monitor-daily')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('studio:reap-stuck-renders')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // 2026-07-06 — Sarah bounded autonomous execution lane. Hourly tick,
        // per-workspace TZ-gated + once-per-local-day (mirrors sarah:morning-brief).
        // Drains safe pending proposals within a per-day tier credit cap so Sarah
        // ACTS instead of only proposing. External/irreversible actions stay
        // human-gated. Per-workspace switch: settings_json->sarah_autonomy.
        $schedule->command("sarah:auto-execute")
            ->name("sarah:auto-execute")
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error("Sarah auto-execute failed");
            });

        // b15 (2026-07-24) — CALENDAR-DRIVEN EXECUTION.
        // PlanSchedulerService stamps plan_tasks.scheduled_for and writes the
        // automation_events calendar row, but nothing read that back, so a
        // scheduled plan ("publish 2 articles every 5 minutes") appeared on the
        // calendar and never fired. This drains tasks whose slot has arrived.
        // Every minute so a 5-minute cadence lands on time.
        // b20 (2026-07-24) — sweep push registrations whose sign-in has ended.
        // Stale-token accumulation is what let a signed-out phone keep getting
        // notifications, and what made the problem reappear on its own after it
        // looked fixed. Daily is ample; the session binding does the real work.
        $schedule->command("sarah:prune-device-tokens")
            ->name("sarah:prune-device-tokens")
            ->dailyAt("03:20")
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command("sarah:run-due-tasks")
            ->name("sarah:run-due-tasks")
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error("Sarah run-due-tasks failed");
            });

        // Engineer888 (2026-07-30) — daily engineering brief.
        //
        // Runs the probes that were previously only run by hand, and only when
        // someone remembered: git delivery state, runtime health, worker and
        // scheduler liveness, queue depth, task failures, and new errors counted
        // forward from the last run's log offset.
        //
        // 06:00 so the brief covers the whole previous day and is waiting before
        // work starts. --quiet-ok keeps the scheduler log silent on a clean
        // platform, so anything logged here is worth reading.
        //
        // NOT runInBackground(): the brief is a ~1.3s read-only collector, and
        // running it in the foreground means a failure surfaces in the scheduler
        // log rather than being detached from it.
        $schedule->command('engineering:brief', ['--quiet-ok'])
            ->name('engineering:brief')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('engineering:brief failed — platform state is now unmonitored');
            });

    })
    ->withMiddleware(function (Middleware $middleware) {
        // Wave 47 — AEO Plan Gate (\$69+ tiers only)
        $middleware->alias([
            'aeo.gate' => \App\Http\Middleware\AeoPlanGate::class,
        ]);


        $middleware->prepend(\App\Http\Middleware\PublishedSiteMiddleware::class);
        // Run BEFORE PublishedSite so request()->ip() resolves to the real
        // client (CF-Connecting-IP / X-Forwarded-For) rather than the
        // Cloudflare edge node, which is what per-IP throttling +
        // audit logs need.
        $middleware->prepend(\App\Http\Middleware\TrustProxies::class);
        $middleware->append(\App\Http\Middleware\CorsMiddleware::class);
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        // OWNER RULE 2026-09-14: no vendor name or raw provider error ever reaches a customer-facing API response.
        $middleware->append(\App\Http\Middleware\VendorSafeResponse::class);
        // MW-1a — SEO isolation: noindex header on staging/IP hosts only.
        $middleware->append(\App\Http\Middleware\StagingNoindex::class);

        // W4 (2026-07-21) - launch-scope route guard. Returns 404 "not in
        // product" for social / email-marketing / mentions route surfaces.
        // Runs inside CORS so the browser can still read the response.
        // Reads the same LaunchScopePolicy the kernel and agent layers read,
        // so the route boundary cannot drift from the execution boundary.
        $middleware->append(\App\Http\Middleware\LaunchScopeRoutes::class);

        // Public form-submit endpoints (cross-origin, no CSRF token possible).
        $middleware->validateCsrfTokens(except: [
            'book',
            '*/book',
        ]);

        // PLATFORM SECURITY 1.0 (2026-08-03) — lu_admin_at crosses middleware groups.
        //
        // The cookie is WRITTEN by /api/auth/login, which is in the `api` group
        // and carries no EncryptCookies. It is READ on /admin/*, which is in the
        // `web` group and does. Without this exemption EncryptCookies calls
        // decrypt() on a plaintext JWT, throws DecryptException, and nulls the
        // cookie before AdminSessionIdentity ever sees it — so a request holding
        // a VALID token is bounced to the login page, which sets the same cookie
        // again. An infinite login loop, and no way into the admin console.
        //
        // Proven on 2026-08-03 before activation: middleware alone returned 200;
        // EncryptCookies in front of it returned 302 for the same valid token.
        // The direct-invocation tests could not see it because they never cross
        // the real HTTP pipeline.
        //
        // The token is not weakened by the exemption. It is a signed JWT verified
        // by RefreshTokenService, HttpOnly, Secure, SameSite=Lax and scoped to
        // /admin — it already carries its own integrity proof, which is the thing
        // cookie encryption would have been adding.
        //
        // The name is read from the middleware's own constant so the write side,
        // the read side and this exemption can never drift apart.
        $middleware->encryptCookies(except: [
            \App\Http\Middleware\AdminSessionIdentity::COOKIE,
        ]);

        $middleware->alias([
            'auth.jwt'        => \App\Http\Middleware\JwtAuthMiddleware::class,
            'desk.context'    => \App\Http\Middleware\DeskContext::class, // PUBLISHER888 Unit 1
            // Phase 2B-R2 — MFA step-up for privileged control-plane ops. Self-
            // disables below the two-MFA-admin governance bar (fail-safe, not fail-open).
            'mfa.stepup'      => \App\Http\Middleware\RequireMfaStepUp::class,
            // E0.5 (2026-07-27) — fail-closed MFA ENROLMENT gate.
            // `mfa.stepup` stands down before governance activation, and
            // activation needs two MFA-enrolled admins — of which there are
            // currently zero. This one has no bootstrap path: no confirmed
            // enrolment, no access. Applied to Bella's admin routes.
            'mfa.enrolled'    => \App\Http\Middleware\RequireEnrolledMfa::class,
            'api.key'         => \App\Http\Middleware\ApiKeyAuth::class,
            'connector.brand' => \App\Http\Middleware\ConnectorBrandFilter::class,
            'runtime.secret'  => \App\Http\Middleware\RuntimeSecretMiddleware::class,
            'plan'            => \App\Http\Middleware\PlanMiddleware::class,
            'team.role'       => \App\Http\Middleware\TeamRoleMiddleware::class,
            // ADDED 2026-04-12 (Phase 2J / doc 12): wires the previously-orphan
            // TrafficDefenseService into the request pipeline. Apply via
            // Route::middleware(['auth.jwt', 'traffic.defense']).
            'traffic.defense' => \App\Http\Middleware\TrafficDefenseMiddleware::class,
            // Phase 2E (2026-05-10) — platform admin gate. Required by the
            // orchestration health endpoint + capability-registry admin API.
            // Checks $user->is_platform_admin; pair with auth.jwt.
            'admin'           => \App\Http\Middleware\AdminMiddleware::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
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

        $schedule->command('sarah:proactive --type=daily')
            ->name('sarah:daily')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sarah daily proactive check failed');
            });

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

        // SEO keyword rank tracking — daily 3am UAE (23:00 UTC)
        $schedule->command('seo:track-ranks')
            ->name('seo:track-ranks')
            ->dailyAt('23:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('SEO rank tracking cron failed');
            });

        // 2026-05-13 — Daily DataForSEO refresh for stale keywords:
        //   seo:rank-track   — vol/diff/cpc + current_rank for keywords
        //                      stale > 24h (cap 100/run)
        //   seo:serp-refresh — top-20 SERP results for top-10 keywords
        //                      per workspace (stale > 24h)
        $schedule->command('seo:rank-track')
            ->name('seo:rank-track')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:rank-track cron failed');
            });

        $schedule->command('seo:serp-refresh')
            ->name('seo:serp-refresh')
            ->dailyAt('03:30')
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
            ->weeklyOn(0, '03:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('seo:authority-score cron failed');
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

        $schedule->command('sarah:proactive --type=weekly')
            ->name('sarah:weekly')
            ->weeklyOn(1, '09:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sarah weekly proactive review failed');
            });

        $schedule->command('sarah:proactive --type=monthly')
            ->name('sarah:monthly')
            ->monthlyOn(1, '10:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sarah monthly strategy check failed');
            });

        // Notification system retention — purge notifications older than 90 days
        $schedule->command('lu:notifications:purge')
            ->name('lu:notifications:purge')
            ->daily()
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Notifications purge cron failed');
            });

        // PATCH 7 (2026-05-08) — email-sequence runner.
        // Fires every 15 minutes; finds due steps for active enrollments
        // and sends through the existing Postmark mailer.
        $schedule->command('lu:sequences:run')
            ->name('lu:sequences:run')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Sequence runner failed');
            });

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
    })
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->prepend(\App\Http\Middleware\PublishedSiteMiddleware::class);
        // Run BEFORE PublishedSite so request()->ip() resolves to the real
        // client (CF-Connecting-IP / X-Forwarded-For) rather than the
        // Cloudflare edge node, which is what per-IP throttling +
        // audit logs need.
        $middleware->prepend(\App\Http\Middleware\TrustProxies::class);
        $middleware->append(\App\Http\Middleware\CorsMiddleware::class);
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);

        // Public form-submit endpoints (cross-origin, no CSRF token possible).
        $middleware->validateCsrfTokens(except: [
            'book',
            '*/book',
        ]);

        $middleware->alias([
            'auth.jwt'        => \App\Http\Middleware\JwtAuthMiddleware::class,
            'api.key'         => \App\Http\Middleware\ApiKeyAuth::class,
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
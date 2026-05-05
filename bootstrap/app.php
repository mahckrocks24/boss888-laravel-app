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
    })
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->prepend(\App\Http\Middleware\PublishedSiteMiddleware::class);
        $middleware->append(\App\Http\Middleware\CorsMiddleware::class);
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);

        // Public form-submit endpoints (cross-origin, no CSRF token possible).
        $middleware->validateCsrfTokens(except: [
            'book',
            '*/book',
        ]);

        $middleware->alias([
            'auth.jwt'        => \App\Http\Middleware\JwtAuthMiddleware::class,
            'runtime.secret'  => \App\Http\Middleware\RuntimeSecretMiddleware::class,
            'plan'            => \App\Http\Middleware\PlanMiddleware::class,
            'team.role'       => \App\Http\Middleware\TeamRoleMiddleware::class,
            // ADDED 2026-04-12 (Phase 2J / doc 12): wires the previously-orphan
            // TrafficDefenseService into the request pipeline. Apply via
            // Route::middleware(['auth.jwt', 'traffic.defense']).
            'traffic.defense' => \App\Http\Middleware\TrafficDefenseMiddleware::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();